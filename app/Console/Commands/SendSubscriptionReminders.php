<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionReinstatementMail;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails an institute owner EVERY day of their grace window, from the day their
 * paid plan's period ends until the portal freezes, nudging them to reinstate.
 *
 * Runs daily via schedule:run. The window itself is config('saas.subscription_
 * grace_days') (default 7) and is owned by {@see Tenant::subscriptionState()} —
 * this command deliberately does not re-derive it, it just asks each candidate
 * tenant whether it is in 'grace' right now. That keeps the freezes-on date in
 * the email identical to the date the portal actually freezes.
 *
 * Console context is unscoped by TenantScope, so the sweep crosses every tenant
 * deliberately; the candidate query is explicit about which tenants it wants.
 *
 * Idempotent per calendar day: the tenant's settings carry
 * `subscription_reminder_on` (a Y-m-d stamp), so a re-run — a host restart, a
 * manual `artisan` call, an overlapping tick — never double-sends. The stamp is
 * set even when the send fails (same policy as the other sweeps): a bounced
 * reminder is not worth retrying all day, and tomorrow's run tries again.
 * {@see Tenant::activatePlan()} clears the stamp so the next lapse notifies.
 *
 * Runs only while config('saas.subscription_enforce_freeze') is on, i.e. while
 * the grace window it describes is actually enforced. See handle().
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'lms:send-subscription-reminders';

    protected $description = 'Email owners a daily reinstatement reminder for each day of their subscription grace window.';

    public function handle(): int
    {
        // The reminder and the freeze are one feature and must never disagree: the
        // email's last line promises a pause ("your academy pauses tomorrow"), so
        // while enforcement is switched off the portal does not pause and that
        // promise is false. Gated on the same flag as Tenant::isSubscriptionFrozen()
        // rather than on a second switch that could be flipped independently.
        if (! config('saas.subscription_enforce_freeze', false)) {
            $this->info('Subscription enforcement is off; no reinstatement reminders sent.');

            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $graceDays = max(0, (int) config('saas.subscription_grace_days', 7));

        // Narrow in SQL to tenants that could possibly be in grace: on a plan
        // with a positive price whose period has already ended. Freeze-eligibility
        // (non-primary, positive price) and the exact window are then decided per
        // tenant by subscriptionState(), the same predicate the freeze uses.
        $paidSlugs = $this->paidPlanSlugs();
        if (empty($paidSlugs)) {
            return self::SUCCESS;
        }

        $candidates = Tenant::query()
            ->whereIn('plan', $paidSlugs)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            // Say so rather than exiting silently: enforcement being ON with no
            // lapsed academies is a normal, healthy state, and it should not be
            // indistinguishable in the schedule log from a run that did nothing
            // because enforcement was off.
            $this->info('Subscription reminder sweep complete: no lapsed paid academies.');

            return self::SUCCESS;
        }

        $owners = $this->ownerRoster();
        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $tenant) {
            // Not actually in the grace window: already frozen (past it), or
            // exempt (the primary institute, a hard-frozen/operator row).
            if ($tenant->subscriptionState() !== 'grace') {
                continue;
            }

            if (data_get($tenant->settings, 'subscription_reminder_on') === $today) {
                $skipped++;
                continue;
            }

            $end = $tenant->current_period_end;
            $freezesOn = $end->copy()->addDays($graceDays);
            // Whole days left before the freeze; 0 on the final day.
            $daysLeft = max(0, (int) now()->diffInDays($freezesOn, false));

            $owner = $owners[$tenant->id] ?? null;
            if ($owner) {
                $sent += $this->notify($tenant, $owner, $end, $freezesOn, $daysLeft);
            }

            // Stamp regardless: one attempt per tenant per day, no hammering.
            $settings = (array) ($tenant->settings ?? []);
            $settings['subscription_reminder_on'] = $today;
            $tenant->forceFill(['settings' => $settings])->save();
        }

        $this->info("Subscription reminder sweep complete: {$sent} email(s) sent, {$skipped} already sent today.");

        return self::SUCCESS;
    }

    /**
     * The plan slugs that cost money, i.e. the ones a lapse can actually freeze.
     * Derived from config so a retuned catalogue needs no change here.
     *
     * @return list<string>
     */
    private function paidPlanSlugs(): array
    {
        $slugs = [];
        foreach ((array) config('saas.plans', []) as $slug => $plan) {
            $price = $plan['price'] ?? null;
            if (is_numeric($price) && (float) $price > 0) {
                $slugs[] = (string) $slug;
            }
        }

        return $slugs;
    }

    /**
     * Every tenant's owner as tenant_id => [name, email, slug]. Owners without a
     * valid address are dropped (nothing to email; the billing page still shows
     * the renew banner in-app). One query for the whole run.
     *
     * @return array<int,array{name:string,email:string,slug:?string}>
     */
    private function ownerRoster(): array
    {
        $rows = DB::table('tenant_admins')
            ->join('users', 'users.id', '=', 'tenant_admins.user_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'tenant_admins.tenant_id')
            ->where('tenant_admins.role', 'owner')
            ->get([
                'tenant_admins.tenant_id as tenant_id',
                'users.email',
                'users.first_name',
                'users.last_name',
                'tenants.slug as slug',
            ]);

        $owners = [];
        foreach ($rows as $r) {
            if (! $r->email || ! filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (isset($owners[$r->tenant_id])) {
                continue; // first owner row per tenant wins, a shared one anyway
            }
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'there';
            $owners[$r->tenant_id] = ['name' => $name, 'email' => $r->email, 'slug' => $r->slug];
        }

        return $owners;
    }

    /**
     * @param array{name:string,email:string,slug:?string} $owner
     */
    private function notify(Tenant $tenant, array $owner, $periodEnd, $freezesOn, int $daysLeft): int
    {
        $base = rtrim((string) config('saas.frontend_url'), '/');
        $billingUrl = $base . '/lms/admin/billing';
        if (! empty($owner['slug'])) {
            $billingUrl .= '?tenant=' . urlencode($owner['slug']);
        }

        $planName = (string) (data_get($tenant->planConfig(), 'name') ?: ucfirst($tenant->planSlug()));

        try {
            Mail::to($owner['email'])->send(new SubscriptionReinstatementMail(
                ownerName: $owner['name'],
                academyName: (string) ($tenant->name ?: 'Your academy'),
                planName: $planName,
                endedOn: $periodEnd->toFormattedDateString(),
                freezesOn: $freezesOn->toFormattedDateString(),
                daysLeft: $daysLeft,
                billingUrl: $billingUrl,
            ));

            return 1;
        } catch (\Throwable $e) {
            Log::warning('lms:send-subscription-reminders: send failed', [
                'tenant_id' => $tenant->id,
                'email' => $owner['email'],
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
