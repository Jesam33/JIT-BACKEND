<?php

namespace Tests\Feature;

use App\Mail\NewDeviceLoginMail;
use App\Models\LmsLoginDevice;
use App\Models\LmsSession;
use App\Support\LoginDeviceTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Login alerts recognise the DEVICE (a browser-held id), not the network: a
 * changing mobile IP must not make someone's own phone look new.
 */
class LoginDeviceAlertTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.6668.90 Safari/537.36';

    private const CHROME_WIN_UPDATED = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.86 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function request(string $ip, string $userAgent, ?string $deviceId = null): Request
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent];
        if ($deviceId !== null) {
            $server['HTTP_X_DEVICE_ID'] = $deviceId;
        }

        return Request::create('/api/frontend/lms/login', 'POST', [], [], [], $server);
    }

    private function login(Request $request, int $userId = 7): bool
    {
        return LoginDeviceTracker::record('student', $userId, 'ada@learner.test', 'Ada', $request);
    }

    private function alerts(): int
    {
        return Mail::sent(NewDeviceLoginMail::class)->count();
    }

    public function test_the_first_ever_login_never_alerts(): void
    {
        $this->assertTrue($this->login($this->request('102.89.1.1', self::CHROME_WIN, 'phone-aaaaaaaaaaaaaaaa')));
        $this->assertSame(0, $this->alerts());
        $this->assertTrue((bool) LmsLoginDevice::query()->first()->by_device_id);
    }

    public function test_the_same_device_on_a_different_network_does_not_alert(): void
    {
        $device = 'laptop-bbbbbbbbbbbbbbbb';
        $this->login($this->request('102.89.1.1', self::CHROME_WIN, $device));

        // Mobile data hands out a new IP; the browser also updated itself.
        $this->assertFalse($this->login($this->request('197.210.55.4', self::CHROME_WIN_UPDATED, $device)));
        $this->assertFalse($this->login($this->request('105.112.9.9', self::CHROME_WIN_UPDATED, $device)));

        $this->assertSame(0, $this->alerts());
        $this->assertSame(1, LmsLoginDevice::query()->count());
        $this->assertSame('105.112.9.9', LmsLoginDevice::query()->first()->ip, 'The latest IP is still recorded, just not used to decide.');
    }

    public function test_a_new_device_alerts_even_on_the_same_network(): void
    {
        $this->login($this->request('102.89.1.1', self::CHROME_WIN, 'laptop-cccccccccccccccc'));

        $this->assertTrue($this->login($this->request('102.89.1.1', self::SAFARI_IPHONE, 'iphone-dddddddddddddddd')));

        $this->assertSame(1, $this->alerts());
        Mail::assertSent(NewDeviceLoginMail::class, fn ($mail) => $mail->hasTo('ada@learner.test'));
    }

    public function test_an_old_ip_based_record_is_adopted_quietly_on_the_switch_over(): void
    {
        // Known from before device ids, under the old ip + browser fingerprint.
        $old = $this->request('102.89.1.1', self::CHROME_WIN);
        $this->login($old);
        $this->assertFalse((bool) LmsLoginDevice::query()->first()->by_device_id);

        // Same browser, now sending a device id, and on another network.
        $this->assertFalse($this->login($this->request('197.210.55.4', self::CHROME_WIN_UPDATED, 'laptop-eeeeeeeeeeeeeeee')));

        $this->assertSame(0, $this->alerts());
        $row = LmsLoginDevice::query()->sole();
        $this->assertTrue((bool) $row->by_device_id);
        $this->assertSame(sha1('device|laptop-eeeeeeeeeeeeeeee'), $row->fingerprint);

        // Adopted once only: a second, different browser id is a new device.
        $this->assertTrue($this->login($this->request('197.210.55.4', self::CHROME_WIN, 'other-ffffffffffffffff')));
        $this->assertSame(1, $this->alerts());
    }

    public function test_requests_without_a_device_id_keep_the_old_behaviour(): void
    {
        $this->login($this->request('102.89.1.1', self::CHROME_WIN));
        $this->assertTrue($this->login($this->request('197.210.55.4', self::CHROME_WIN)));

        $this->assertSame(1, $this->alerts());
    }

    public function test_a_malformed_device_id_is_ignored(): void
    {
        $this->login($this->request('102.89.1.1', self::CHROME_WIN, 'short'));
        $this->assertFalse((bool) LmsLoginDevice::query()->first()->by_device_id);
    }

    public function test_signing_out_a_device_finds_its_sessions_after_its_ip_changed(): void
    {
        $device = 'laptop-gggggggggggggggg';
        $first = $this->request('102.89.1.1', self::CHROME_WIN, $device);
        $this->login($first);
        LmsSession::query()->create(['role' => 'student', 'user_id' => 7, 'token' => Str::random(60), 'expires_at' => now()->addDay()] + LmsSession::requestMeta($first));

        $later = $this->request('197.210.55.4', self::CHROME_WIN, $device);
        LmsSession::query()->create(['role' => 'student', 'user_id' => 7, 'token' => Str::random(60), 'expires_at' => now()->addDay()] + LmsSession::requestMeta($later));

        $phone = $this->request('102.89.1.1', self::SAFARI_IPHONE, 'iphone-hhhhhhhhhhhhhhhh');
        LmsSession::query()->create(['role' => 'student', 'user_id' => 7, 'token' => Str::random(60), 'expires_at' => now()->addDay()] + LmsSession::requestMeta($phone));

        $revoked = LoginDeviceTracker::signOutDevice('student', 7, LoginDeviceTracker::fingerprint($first));

        $this->assertSame(2, $revoked, 'Both of the laptop\'s sessions, across two IPs, are signed out.');
        $this->assertSame(1, LmsSession::query()->where('role', 'student')->where('user_id', 7)->count(), 'The phone stays signed in.');
    }

    public function test_trusted_proxies_are_off_unless_configured(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }
}
