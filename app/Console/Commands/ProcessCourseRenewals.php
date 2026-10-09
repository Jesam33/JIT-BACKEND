<?php

namespace App\Console\Commands;

use App\Models\LmsCourse;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Services\CourseBilling;
use App\Services\CoursePayments;
use App\Services\PaystackService;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The monthly-course renewal sweep. For every monthly registration still being
 * billed, in this order:
 *
 *   1. ENDED   the cohort finishes before the next month, the course was
 *              deleted, the academy closed, or the student was suspended,
 *              deleted or moved course → stop billing for good (no lock).
 *              See CourseBilling::endReason(); most of these are also stopped
 *              immediately when they happen, this is the safety net.
 *   2. DUE SOON  within saas.monthly_reminder_days (7) of paid_until → a
 *              reminder every day, at/after saas.monthly_reminder_hour local
 *              time (billing_reminded_for = when the last one went out).
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

        // 1. Ended: stop billing for good (cohort finished, course deleted,
        // academy closed, student suspended or moved on), and say why once.
        if ($reason = CourseBilling::endReason($registration)) {
            $stats['ended']++;
            if (! $dry) {
                CourseBilling::endBilling($registration, $reason);
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

        // 2. Due soon: a reminder EVERY DAY of the last week (portal + email),
        // sent once per local day at/after the reminder hour so it never lands
        // at night. billing_reminded_for holds when the last one went out.
        if ($now->lessThan($paidUntil)) {
            $remindFrom = $paidUntil->copy()->subDays(max(0, (int) config('saas.monthly_reminder_days', 7)));

            if ($now->greaterThanOrEqualTo($remindFrom) && $this->reminderDueToday($registration, $now)) {
                $stats['reminded']++;
                if (! $dry) {
                    $this->sendDueSoonReminder($registration, $paidUntil, $now);
                    $registration->forceFill(['billing_reminded_for' => $now])->save();
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

    /**
     * Whether today's reminder is still owed: it is past the reminder hour in
     * the reminder timezone, and none has gone out yet on this local day.
     */
    private function reminderDueToday(TrainingRegistration $registration, \Illuminate\Support\Carbon $now): bool
    {
        $tz = (string) config('saas.monthly_reminder_timezone', 'Africa/Lagos');
        $local = $now->copy()->setTimezone($tz);

        if ($local->hour < (int) config('saas.monthly_reminder_hour', 8)) {
            return false;
        }

        $last = $registration->billing_reminded_for;

        return ! $last || ! $last->copy()->setTimezone($tz)->isSameDay($local);
    }

    /** "Due in 5 days" / "due tomorrow" / "due today", plus how it will be paid. */
    private function sendDueSoonReminder(TrainingRegistration $registration, \Illuminate\Support\Carbon $paidUntil, \Illuminate\Support\Carbon $now): void
    {
        $tz = (string) config('saas.monthly_reminder_timezone', 'Africa/Lagos');
        $daysLeft = (int) $now->copy()->setTimezone($tz)->startOfDay()
            ->diffInDays($paidUntil->copy()->setTimezone($tz)->startOfDay());

        $when = match (true) {
            $daysLeft <= 0 => 'today',
            $daysLeft === 1 => 'tomorrow',
            default => "in {$daysLeft} days",
        };

        $date = $paidUntil->copy()->setTimezone($tz)->format('j M Y');
        $card = CourseBilling::authorization($registration);
        $amount = CourseBilling::money($registration->charge_currency, (float) $registration->course_price);
        $grace = CourseBilling::graceDays();

        CourseBilling::notifyStudent(
            $registration,
            'billing_due_soon',
            "Your monthly payment is due {$when}",
            $card
                ? "We will charge {$amount} for {$registration->course_name} to your card ending " . ($card['last4'] ?? '')
                    . " on {$date}. Nothing to do if that card is still good; to use another, pay from Billing in your portal."
                : "Your {$amount} payment for {$registration->course_name} is due on {$date}. Pay from Billing in your portal."
                    . " If it isn't paid, your access pauses {$grace} day" . ($grace === 1 ? '' : 's') . ' after that date.'
        );
    }

    /** Charge the saved card for the next month. True when the money was taken. */
    private function chargeCard(TrainingRegistration $registration, array $card, PaystackService $paystack): bool
    {
        $registration->forceFill(['billing_last_attempt_at' => now()])->save();

        // The card is charged through the gateway that saved it (Paystack
        // authorization or Flutterwave card token).
        $gateway = ($card['gateway'] ?? CoursePayments::PAYSTACK) === CoursePayments::FLUTTERWAVE
            ? CoursePayments::flutterwave()
            : $paystack;
        if (! $gateway->isConfigured()) {
            return false;
        }

        $tenant = Tenant::find($registration->tenant_id);

        // Never collect a non-primary academy's fees into the platform account
        // (re-checked inside chargeSaved for the card's own gateway).
        if ($tenant && ! $tenant->isPrimary() && ! CoursePayments::linked($tenant)) {
            return false;
        }

        [$payment, $fee] = CourseBilling::newRenewalPayment($registration, $card['gateway'] ?? CoursePayments::PAYSTACK);

        $data = CoursePayments::chargeSaved($payment, $tenant, $card, (string) $registration->email, $fee);

        if (($data['status'] ?? '') === 'success') {
            return CourseBilling::completeRenewal($payment, $data) !== 'review';
        }

        // Anything else (declined, needs OTP/PIN, insufficient funds): the
        // student pays from the link instead. A charge left "pending" at the
        // gateway can still confirm later through the webhook.
        $payment->update([
            'status' => in_array($data['status'] ?? '', ['pending', 'ongoing', 'send_otp', 'send_pin'], true) ? 'pending' : 'failed',
            'gateway_response' => $data,
        ]);

        Log::info('Monthly auto-charge not completed', [
            'registration_id' => $registration->id,
            'status' => $data['status'] ?? null,
            'message' => $data['message'] ?? ($data['gateway_response'] ?? null),
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
