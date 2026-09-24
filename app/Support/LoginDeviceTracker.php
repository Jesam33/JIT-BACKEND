<?php

namespace App\Support;

use App\Models\LmsLoginDevice;
use App\Models\LmsSession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Recognises the device a login came from, and warns the account holder when it
 * is one we have not seen before.
 *
 * The fingerprint is sha1(ip . '|' . normalised user agent). It is deliberately
 * coarse: browser minor versions change constantly, so a fingerprint that moved
 * with every Chrome update would alert on every release and teach people to
 * ignore the email. The normalisation below pins the parts that identify a
 * machine and discards the parts that churn.
 *
 * IP is included, which means the same laptop on a different network counts as a
 * new device. That is the intended trade: a genuinely new location IS the thing
 * worth telling someone about, and "same browser, new IP" is exactly the signal
 * an account-takeover alert should fire on.
 *
 * `lms_login_devices` outlives `lms_sessions` on purpose. Sessions expire after
 * 7 days; if "have I seen this before?" were derived from sessions, a returning
 * user on their own laptop would get a scary security email every week.
 *
 * All four roles are recorded, but only `student`, `staff` and `owner` are
 * revocable: agent sessions live in `agent_sessions` (a different table, not
 * read here), and the agent portal has no device-management screen. Agents
 * therefore get the alert but not the sign-out list — deliberate, not an
 * oversight, and the one place the roles differ.
 */
class LoginDeviceTracker
{
    /**
     * Record this login against the account's device list, emailing the holder if
     * the device is new to them.
     *
     * Returns whether the device was new, mostly so callers can log or test it.
     *
     * NEVER throws. This runs inside the login path, and a failure to record a
     * device — or to send the warning email — must not be able to stop somebody
     * signing in. Everything unexpected is reported and swallowed.
     */
    public static function record(
        string $role,
        int $userId,
        ?string $email,
        ?string $name,
        Request $request,
    ): bool {
        try {
            $fingerprint = self::fingerprint($request);
            $ip = self::ip($request);
            $userAgent = self::truncate((string) $request->userAgent(), 255);
            $label = self::describe($userAgent);

            $device = LmsLoginDevice::query()
                ->where('role', $role)
                ->where('user_id', $userId)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($device) {
                // Seen before: this is the common case and it must stay cheap.
                // The stored label is kept as-is (a UA upgrade should not rewrite
                // history), only the liveness fields move.
                $device->forceFill([
                    'ip' => $ip,
                    'last_seen_at' => now(),
                ])->save();

                return false;
            }

            // Has this account EVER signed in from a recognised device? A brand
            // new account's first login is, by definition, from an unrecognised
            // device — emailing "new sign-in, was this you?" to someone who just
            // finished signing up is noise, and worse, it trains them to dismiss
            // the one alert that matters.
            $isFirstEver = ! LmsLoginDevice::query()
                ->where('role', $role)
                ->where('user_id', $userId)
                ->exists();

            LmsLoginDevice::query()->create([
                'role' => $role,
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'device_label' => $label,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            if (! $isFirstEver && $email) {
                self::alert($role, $email, $name, $label, $ip);
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Tell the account holder about a sign-in from a device we had not seen.
     *
     * Sent regardless of role, branded as the person's academy where one is bound
     * (see the mail class). Swallowed on failure — see record().
     */
    private static function alert(string $role, string $email, ?string $name, string $label, ?string $ip): void
    {
        try {
            $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

            \Illuminate\Support\Facades\Mail::to($email)->send(
                \App\Mail\NewDeviceLoginMail::make([
                    'name' => $name ?: 'there',
                    'academy' => $tenant?->name ?: 'Jorsas Tech',
                    'tenant_id' => $tenant?->id,
                    'device' => $label,
                    'ip' => $ip ?: 'unknown',
                    'time' => now()->format('j F Y \a\t H:i'),
                    'url' => NotificationLinks::profileUrl(
                        $role === 'owner' ? 'owner' : ($role === 'staff' ? 'staff' : 'student'),
                        $tenant?->slug,
                    ),
                ])
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Stable identity for a device: the IP plus a version-stripped user agent.
     *
     * Browser and OS version numbers are removed because they change on every
     * update; the engine and platform names are kept because they do not.
     */
    public static function fingerprint(Request $request): string
    {
        return sha1(self::ip($request) . '|' . self::normalise((string) $request->userAgent()));
    }

    private static function normalise(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        // Drop version numbers ("chrome/120.0.1" -> "chrome"), which is what makes
        // the fingerprint survive a browser update.
        $ua = (string) preg_replace('#\d+(\.\d+)*#', '', $ua);
        // Collapse the whitespace the removal above leaves behind.
        $ua = (string) preg_replace('#\s+#', ' ', $ua);

        return trim($ua);
    }

    private static function ip(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip ? self::truncate($ip, 45) : null;
    }

    /** "Chrome on Windows" — enough to recognise your own machine, nothing more. */
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return 'Unknown device';
        }

        // Order matters. Every Chromium browser claims to be Chrome, and both
        // Chrome and Safari claim to be Safari, so the most specific claim has to
        // be tested first or everything reports as Safari.
        $browser = 'Unknown browser';
        foreach ([
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }

        $platform = 'Unknown system';
        foreach ([
            'Windows' => 'Windows',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Mac OS X' => 'Mac',
            'Macintosh' => 'Mac',
            'Linux' => 'Linux',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $platform = $name;
                break;
            }
        }

        return self::truncate("{$browser} on {$platform}", 120);
    }

    /** Devices this account has signed in from, newest activity first. */
    public static function devices(string $role, int $userId): Collection
    {
        return LmsLoginDevice::query()
            ->where('role', $role)
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Sign one device out by deleting its sessions, and forget it.
     *
     * The device row is removed as well as its sessions, so that signing back in
     * from that machine correctly reads as a new device and alerts again — which
     * is the right behaviour for a device the holder has just told us to forget.
     *
     * @return int sessions revoked
     */
    public static function signOutDevice(string $role, int $userId, string $fingerprint): int
    {
        // Sessions first: the fingerprint match reads each session's own stored
        // ip + user agent, so it does not depend on the device row still being
        // there. Forgetting the device afterwards is what makes a later sign-in
        // from that machine alert again.
        $revoked = self::revokeSessions($role, $userId, $fingerprint);

        LmsLoginDevice::query()
            ->where('role', $role)
            ->where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->delete();

        return $revoked;
    }

    /**
     * Sign out every session for this account.
     *
     * The device list is intentionally KEPT: "sign out everywhere" is a panic
     * button, and re-alerting the holder about their own laptop five minutes later
     * because we forgot it would be alarming at the worst moment. Clearing it is
     * a separate, deliberate act (signOutDevice on each, or a password change).
     *
     * @return int sessions revoked
     */
    public static function signOutAll(string $role, int $userId, ?string $exceptFingerprint = null): int
    {
        return self::revokeSessions($role, $userId, null, $exceptFingerprint);
    }

    /**
     * Delete sessions belonging to a device fingerprint.
     *
     * lms_sessions stores the ip and user agent as separate columns rather than
     * the fingerprint, so the match is recomputed here from each session's own
     * stored pair — the same two inputs fingerprint() hashes. Done in PHP rather
     * than SQL because the hash is over a normalised form of the user agent, which
     * MySQL cannot reproduce; the row count here is one account's live sessions,
     * so it is small by construction.
     */
    private static function revokeSessions(string $role, int $userId, ?string $fingerprint, ?string $exceptFingerprint = null): int
    {
        $sessions = LmsSession::query()
            ->where('role', $role)
            ->where('user_id', $userId)
            ->get();

        $matches = $sessions->filter(function (LmsSession $session) use ($fingerprint, $exceptFingerprint): bool {
            $own = sha1(($session->ip ?? '') . '|' . self::normalise((string) $session->user_agent));

            return $fingerprint !== null
                ? $own === $fingerprint
                : $own !== $exceptFingerprint;
        })->pluck('id');

        if ($matches->isEmpty()) {
            return 0;
        }

        return (int) LmsSession::query()->whereIn('id', $matches)->delete();
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
