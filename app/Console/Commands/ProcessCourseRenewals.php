<?php

namespace App\Console\Commands;

use App\Models\LmsCourse;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Services\CourseBilling;
use App\Services\PaystackService;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The monthly-course renewal sweep. For every monthly registration still being
 * billed, in this order:
 *
 *   1. ENDED   the student moved to another course, or their cohort finishes
 *              before the next month starts → stop billing for good (no lock).
 *   2. DUE SOON  within saas.monthly_reminder_days of paid_until → one reminder
 *              per period (billing_reminded_for).
 *   3. DUE     paid_until has passed and the month is unpaid → charge the saved
 *              card (at most once a day, billing_last_attempt_at); no card, or
 *              the charge failed → past_due and a "pay now" notice with a link.
 *   4. PAUSED  past the grace window → one "access paused" notice to the student
 *              and the owner (billing_lock_notified_at). The lock itself is
 *              computed on every request (CourseBilling::isLocked), not stored.
 *
 * Cancelled registrations are never charged or chased; they only get the pause
 * notice when the paid month runs out.
 *
 * Runs hourly; every step is guarded by a stamp so re-runs are no-ops. Console
 * context is unscoped by TenantScope, so the sweep crosses every tenant and each
 * write below is explicit about the registration's tenant. Notifications reach
 * students/owners by bell and, through the notification email sweep, by email.
 */
class ProcessCourseRenewals extends Command
{
    protected $signature = 'lms:process-course-renewals {--dry-run : Report what would happen without charging or notifying}';

    protected $description = 'Remind, auto-charge and pause monthly-course students whose month is due.';

    public function handle(PaystackService $paystack): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = ['ended' => 0, 'reminded' => 0, 'charged' => 0, 'failed' => 0, 'past_due' => 0, 'paused' => 0];

        TrainingRegistration::query()
            ->where('billing_type', LmsCourse::BILLING_MONTHLY)
            ->whereIn('billing_status', [CourseBilling::STATUS_ACTIVE, CourseBilling::STATUS_PAST_DUE, CourseBilling::STATUS_CANCELLED])
            ->whereNotNull('paid_until')
            ->orderBy('id')
            ->chunkById(200, function ($registrations) use ($paystack, $dry, &$stats) {
                foreach ($registrations as $registration) {
                    try {
                        $this->process($registration, $paystack, $dry, $stats);
                    } catch (\Throwable $e) {
                        // One bad row must never stop everyone else's renewal.
                        Log::error('lms:process-course-renewals: registration failed', [
                            'registration_id' => $registration->id,
                            'err' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(($dry ? '[dry run] ' : '') . collect($stats)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }

    private function process(TrainingRegistration $registration, PaystackService $paystack, bool $dry, array &$stats): void
    {
        $now = now();
        $cancelled = $registration->billing_status === CourseBilling::STATUS_CANCELLED;

        // 1. Ended: stop billing for good, and say so once.
        if (CourseBilling::shouldEnd($registration)) {
            $stats['ended']++;
            if (! $dry) {
                $registration->forceFill(['billing_status' => CourseBilling::STATUS_ENDED])->save();
                if (! $cancelled) {
                    CourseBilling::notifyStudent(
                        $registration,
                        'billing_ended',
                        'No more monthly payments',
                        'Your course ' . $registration->course_name . ' is finishing, so you will not be charged again.'
                    );
                }
            }

            return;
        }

        $paidUntil = $registration->paid_until;

        // 4. Access has paused: tell the student and the owner once.
        if (CourseBilling::isLocked($registration)) {
            if (! $registration->billing_lock_notified_at) {
                $stats['paused']++;
                if (! $dry) {
                    $this->notifyPaused($registration);
                    $registration->forceFill(['billing_lock_notified_at' => $now])->save();
                }
            }
            // A paused (not cancelled) student with a saved card still gets the
            // daily retry below, so a topped-up card brings them back unaided.
        }

        if ($cancelled) {
            return;
        }

        // 2. Due soon: one reminder per period.
        $reminderDays = max(0, (int) config('saas.monthly_reminder_days', 3));
        if ($now->lessThan($paidUntil)) {
            $remindFrom = $paidUntil->copy()->subDays($reminderDays);
            $alreadyReminded = $registration->billing_reminded_for && $registration->billing_reminded_for->equalTo($paidUntil);

            if ($now->greaterThanOrEqualTo($remindFrom) && ! $alreadyReminded) {
                $stats['reminded']++;
                if (! $dry) {
                    $card = CourseBilling::authorization($registration);
                    $amount = CourseBilling::money($registration->charge_currency, (float) $registration->course_price);
                    CourseBilling::notifyStudent(
                        $registration,
                        'billing_due_soon',
                        'Your next monthly payment is due ' . $paidUntil->format('j M'),
                        $card
                            ? 'We will charge ' . $amount . ' for ' . $registration->course_name . ' to your card ending '
                                . ($card['last4'] ?? '') . ' on ' . $paidUntil->format('j M Y') . '. Nothing to do if that card is still good.'
                            : 'Your ' . $amount . ' payment for ' . $registration->course_name . ' is due on '
                                . $paidUntil->format('j M Y') . '. Open Billing in your portal to pay for next month.'
                    );
                    $registration->forceFill(['billing_reminded_for' => $paidUntil])->save();
                }
            }

            return;
        }

        // 3. Due: try the saved card once a day; otherwise ask the student to pay.
        $card = CourseBilling::authorization($registration);
        $triedToday = $registration->billing_last_attempt_at
            && $registration->billing_last_attempt_at->greaterThan($now->copy()->subHours(20));

        if ($card && ! $triedToday) {
            if ($dry) {
                $stats['charged']++;

                return;
            }

            if ($this->chargeCard($registration, $card, $paystack)) {
                $stats['charged']++;

                return;
            }

            $stats['failed']++;
            $this->markPastDue($registration, true);

            return;
        }

        if (! $card && $registration->billing_status === CourseBilling::STATUS_ACTIVE) {
            $stats['past_due']++;
            if (! $dry) {
                $this->markPastDue($registration, false);
            }
        }
    }

    /** Charge the saved card for the next month. True when the money was taken. */
    private function chargeCard(TrainingRegistration $registration, array $card, PaystackService $paystack): bool
    {
        $registration->forceFill(['billing_last_attempt_at' => now()])->save();

        if (! $paystack->isConfigured()) {
            return false;
        }

        $tenant = Tenant::find($registration->tenant_id);
        $subaccount = CourseBilling::subaccountCode($tenant);

        // Never collect a non-primary academy's fees into the platform account.
        if ($tenant && ! $tenant->isPrimary() && ! $subaccount) {
            return false;
        }

        [$payment, $fee] = CourseBilling::newRenewalPayment($registration);

        $response = $paystack->chargeAuthorization(
            $registration->email,
            (float) $payment->amount,
            (string) $card['code'],
            $payment->reference,
            ['registration_id' => $registration->id, 'course_name' => $registration->course_name, 'purpose' => 'monthly_renewal'],
            $subaccount,
            $payment->currency,
            $fee
        );

        $data = (array) ($response['data'] ?? []);

        if (($data['status'] ?? '') === 'success') {
            return CourseBilling::completeRenewal($payment, $data) !== 'review';
        }

        // Anything else (declined, needs OTP/PIN, insufficient funds): the
        // student pays from the link instead. A charge left "pending" at the
        // gateway can still confirm later through the webhook.
        $payment->update([
            'status' => in_array($data['status'] ?? '', ['pending', 'ongoing', 'send_otp', 'send_pin'], true) ? 'pending' : 'failed',
            'gateway_response' => $response,
        ]);

        Log::info('Monthly auto-charge not completed', [
            'registration_id' => $registration->id,
            'status' => $data['status'] ?? null,
            'message' => $response['message'] ?? ($data['gateway_response'] ?? null),
        ]);

        return false;
    }

    /** Move to past_due and ask the student to pay (once per period). */
    private function markPastDue(TrainingRegistration $registration, bool $cardFailed): void
    {
        $firstTime = $registration->billing_status !== CourseBilling::STATUS_PAST_DUE;

        $registration->forceFill(['billing_status' => CourseBilling::STATUS_PAST_DUE])->save();

        if (! $firstTime) {
            return;
        }

        $accessEnds = CourseBilling::accessEndsAt($registration);
        $amount = CourseBilling::money($registration->charge_currency, (float) $registration->course_price);

        CourseBilling::notifyStudent(
            $registration,
            'billing_due',
            $cardFailed ? 'We could not charge your card' : 'Your monthly payment is due',
            ($cardFailed ? 'Your card was declined for the ' . $amount . ' payment for ' : 'Your ' . $amount . ' payment for ')
                . $registration->course_name . ' is due. Please pay from Billing in your portal before '
                . ($accessEnds?->format('j M Y') ?? 'your access pauses') . ' to keep your access.'
        );
    }

    private function notifyPaused(TrainingRegistration $registration): void
    {
        CourseBilling::notifyStudent(
            $registration,
            'billing_paused',
            'Your course access is paused',
            'We have not received your monthly payment for ' . $registration->course_name
                . '. Pay from Billing in your portal and your access comes back straight away. Your work and progress are saved.'
        );

        $name = trim($registration->first_name . ' ' . $registration->last_name) ?: $registration->email;
        Notify::owner(
            'billing_overdue',
            'Monthly payment overdue: ' . $name,
            $name . ' has not paid for another month of ' . $registration->course_name
                . ', so their access is paused. It comes back as soon as they pay.',
            'student',
            null,
            $registration->tenant_id ? (int) $registration->tenant_id : null
        );
    }
}
