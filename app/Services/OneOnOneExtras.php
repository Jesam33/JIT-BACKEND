<?php

namespace App\Services;

use App\Models\LmsOneOnOneCredit;
use App\Models\LmsStudent;
use App\Models\Payment;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\NotificationLinks;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Extra one-on-one sessions: once a student has used their course's
 * allowance they can buy more, at the course's
 * one_on_one_extra_session_price each. Paid through the academy's own
 * Paystack subaccount with the platform service charge held back, exactly like
 * a monthly renewal (CourseBilling::serviceCharge), and confirmed by the same
 * paths: the browser verify and the Paystack webhook
 * (LmsIntakeController::completePayment).
 *
 * Extras are for the current month on a monthly course (they don't roll over)
 * or for the whole course on a one-time one. A pending purchase is a
 * lms_one_on_one_credits row with status 'pending'; it counts only once paid.
 */
class OneOnOneExtras
{
    public const MAX_PER_PURCHASE = 10;

    /** Price of one extra session, or null when this student can't buy extras. */
    public static function price(LmsStudent $student): ?float
    {
        $course = OneOnOneBooking::course($student);

        if (! $student->isOneOnOne() || ! $course?->one_on_one_session_limit || $course->one_on_one_extra_session_price === null) {
            return null;
        }
        if (! CourseBilling::currentRegistration($student)) {
            return null;
        }

        $price = (float) $course->one_on_one_extra_session_price;

        return $price > 0 ? $price : null;
    }

    /**
     * Create the pending purchase: a credit row and its Payment, with the
     * service charge worked out.
     *
     * @return array{0: Payment, 1: ?float, 2: LmsOneOnOneCredit}  [payment, service charge, credit]
     */
    public static function start(LmsStudent $student, int $sessions): array
    {
        $price = static::price($student);
        if ($price === null) {
            throw ValidationException::withMessages(['sessions' => 'Extra sessions are not available for your course.']);
        }
        if ($sessions < 1 || $sessions > self::MAX_PER_PURCHASE) {
            throw ValidationException::withMessages(['sessions' => 'Choose between 1 and ' . self::MAX_PER_PURCHASE . ' sessions.']);
        }

        $registration = CourseBilling::currentRegistration($student);
        $tenant = Tenant::find($student->tenant_id);
        $amount = round($price * $sessions, 2);
        $charge = CourseBilling::serviceCharge($tenant, $amount);
        $course = OneOnOneBooking::course($student);

        return DB::transaction(function () use ($student, $sessions, $registration, $amount, $charge, $course, $tenant) {
            $payment = Payment::createForTenant((int) $student->tenant_id, [
                'registration_id' => $registration->id,
                'reference' => 'JORSAS-X-' . Str::upper(Str::random(18)),
                'amount' => $amount,
                // The academy's own currency (the price was set in it).
                'currency' => $tenant?->currency() ?? 'NGN',
                'status' => 'pending',
                'gateway' => 'paystack',
                'kind' => Payment::KIND_EXTRA_SESSIONS,
                'platform_fee' => $charge['fee'] ?? 0,
                'academy_amount' => $charge['academy'],
            ]);

            $credit = LmsOneOnOneCredit::createForTenant((int) $student->tenant_id, [
                'student_id' => $student->id,
                'payment_id' => $payment->id,
                'sessions' => $sessions,
                'period' => OneOnOneBooking::periodKey($course, now()),
                'status' => 'pending',
            ]);

            return [$payment, $charge['fee'], $credit];
        });
    }

    /**
     * Confirm an extra-sessions payment. Idempotent and race-safe like
     * CourseBilling::completeRenewal: the browser verify and the webhook can
     * both report the same reference. Returns 'success', 'already' or 'review'.
     */
    public static function complete(Payment $payment, ?array $gatewayData = null): string
    {
        [$status, $fresh, $credit] = DB::transaction(function () use ($payment, $gatewayData) {
            $fresh = Payment::query()->withoutGlobalScope(TenantScope::class)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->status === 'success') {
                return ['already', null, null];
            }

            if (is_array($gatewayData) && $gatewayData !== [] && ! CourseBilling::gatewayMatches($fresh, $gatewayData)) {
                $fresh->update(['status' => 'review', 'gateway_response' => $gatewayData]);

                return ['review', null, null];
            }

            $credit = LmsOneOnOneCredit::query()->withoutGlobalScope(TenantScope::class)
                ->where('payment_id', $fresh->id)
                ->lockForUpdate()
                ->first();

            $fresh->update([
                'status' => 'success',
                'gateway_response' => is_array($gatewayData) && $gatewayData !== [] ? $gatewayData : $fresh->gateway_response,
            ]);
            $credit?->forceFill(['status' => 'paid'])->save();

            return ['success', $fresh, $credit];
        });

        if ($status === 'success' && $credit) {
            try {
                static::notifyPaid($fresh, $credit);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $status;
    }

    /** Where Paystack sends the student back: their booking page. */
    public static function callbackUrl(LmsStudent $student, string $reference): string
    {
        $slug = $student->tenant_id ? Tenant::query()->whereKey($student->tenant_id)->value('slug') : null;

        return NotificationLinks::tenantPinned('/lms/app/book?reference=' . urlencode($reference), $slug);
    }

    private static function notifyPaid(Payment $payment, LmsOneOnOneCredit $credit): void
    {
        $student = LmsStudent::query()->withoutGlobalScope(TenantScope::class)->find($credit->student_id);
        if (! $student) {
            return;
        }

        $tenant = Tenant::find($payment->tenant_id);
        $name = trim($student->first_name . ' ' . $student->last_name) ?: (string) $student->email;
        $amount = CourseBilling::money($payment->currency, (float) $payment->amount);
        $what = $credit->sessions . ' extra one-on-one session' . ($credit->sessions === 1 ? '' : 's');
        $period = $credit->period
            ? ' for ' . \Illuminate\Support\Carbon::createFromFormat('Y-m', $credit->period)->format('F Y')
            : '';

        Notify::student(
            $student->id,
            'one_on_one_extras_paid',
            'Extra sessions added',
            'Thank you. ' . ucfirst($what) . $period . ' added. Book them from Book a session.',
            'course',
            $student->selected_course_id
        );

        Notify::owner(
            'payment',
            'Extra sessions bought: ' . $amount,
            trim($name . ' bought ' . $what . $period . ' for ' . $amount . '. ' . CourseBilling::breakdownLine($payment, $tenant)),
            'payment',
            $payment->id,
            $payment->tenant_id ? (int) $payment->tenant_id : null
        );
    }
}
