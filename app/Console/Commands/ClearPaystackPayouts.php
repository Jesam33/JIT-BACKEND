<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CoursePayments;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * Payments moved to Flutterwave: clear the bank links academies made on
 * Paystack before the move and ask each owner to link their bank again.
 *
 * For every academy that still only has a Paystack payout link (no
 * Flutterwave one), the old link is moved to settings.paystack_archived
 * (kept for the record, never used again), the academy is flagged
 * `payout_relink_required`, and the owner gets a notification, also emailed
 * by the notification sweep, pointing at Profile → Payment. Until they
 * re-link, the academy's paid courses can't be bought (no money can land in
 * the platform account by mistake). Academies already on Flutterwave are
 * untouched. Safe to re-run; --dry-run lists who would be affected.
 */
class ClearPaystackPayouts extends Command
{
    protected $signature = 'lms:clear-paystack-payouts {--dry-run : List the academies without changing anything}';

    protected $description = 'Clear pre-Flutterwave Paystack bank links and ask those academy owners to link their bank again';

    public function handle(): int
    {
        if (! CoursePayments::flutterwave()->isConfigured()) {
            $this->error('Flutterwave is not configured here, so academies could not re-link. Nothing changed.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $cleared = 0;

        foreach (Tenant::query()->get() as $tenant) {
            $settings = (array) ($tenant->settings ?? []);
            $code = data_get($settings, 'paystack.subaccount_code');
            if ($tenant->isPrimary() || empty($code) || $tenant->flutterwaveSubaccountId() !== null) {
                continue;
            }

            $this->line(($dry ? '[dry run] ' : '') . "#{$tenant->id} {$tenant->name} ({$tenant->slug}): Paystack link {$code}");
            $cleared++;
            if ($dry) {
                continue;
            }

            $settings['paystack_archived'] = (array) $settings['paystack'] + ['archived_at' => now()->toIso8601String()];
            unset($settings['paystack']);
            $settings['payout_relink_required'] = true;
            $tenant->update(['settings' => $settings]);

            Notify::owner(
                'payout_relink',
                'Link your bank again to keep selling courses',
                'We have moved student payments to Flutterwave, which pays academies in their own currency in many countries. '
                    . 'Your old bank link has been removed, so students can\'t pay for your courses until you link your bank again. '
                    . 'It takes a minute: go to Profile → Payment and add your bank.',
                'payout',
                null,
                (int) $tenant->id,
            );
        }

        $this->info(($dry ? 'Would clear ' : 'Cleared ') . "{$cleared} academy bank link(s)." . ($dry || ! $cleared ? '' : ' Their owners have been notified.'));

        return self::SUCCESS;
    }
}
