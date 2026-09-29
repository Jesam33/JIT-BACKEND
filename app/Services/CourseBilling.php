<?php

namespace App\Services;

use App\Models\LmsEnrollment;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Support\NotificationLinks;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Student course billing: the platform service charge on every payment, and the
 * monthly-course lifecycle.
 *
 * ── Service charge ──────────────────────────────────────────────────────────
 * Every student payment that settles to an academy's own Paystack subaccount has
 * the platform's service charge (Tenant::commissionPercent(), a flat 5%) held
 * back and the rest paid to the academy. Taken per payment: once for a one-time
 * course, every month for a monthly one. The exact amount is sent to Paystack
 * per transaction and recorded on the Payment row, and the owner is told the
 * breakdown on every payment.
 *
 * ── Monthly courses ─────────────────────────────────────────────────────────
 * A monthly registration carries `paid_until`. Each payment extends it by one
 * month from whichever is later, the old paid_until or now (a late payer's month
 * starts when they pay). States:
 *
 *   active     paid up, or due and still being chased
 *   past_due   the due date passed and the renewal did not go through
 *   cancelled  the student stopped renewing; access runs to paid_until
 *   ended      the course/cohort finished, or the student moved to another
 *              course; never billed again and never locked
 *
 * Access pauses (isLocked) once paid_until + the grace window
 * (saas.monthly_grace_days, 3) has passed, or right at paid_until for a
 * cancelled student. Paying again restores it immediately. The renewal sweep
 * (lms:process-course-renewals) does the reminders, card charges and notices.
 */
class CourseBilling
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ENDED = 'ended';

    /* ---------------------------------------------------------------------
     * Service charge
     * ------------------------------------------------------------------ */

    /** The academy's Paystack subaccount code, or null (primary / not linked). */
    public static function subaccountCode(?Tenant $tenant): ?string
    {
        $code = $tenant ? data_get($tenant->settings, 'paystack.subaccount_code') : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * The service charge on one payment.
     *
     * Only a payment that settles to an academy's subaccount is split; without
     * one (the primary institute) the whole amount lands in the platform
     * account, so there is nothing to hold back and `fee` is null.
     *
     * @return array{percent: float, fee: ?float, academy: ?float}
     */
    public static function serviceCharge(?Tenant $tenant, float $amount): array
    {
        $percent = $tenant ? $tenant->commissionPercent() : (float) config('saas.platform_commission_percent', 5);

        if (! static::subaccountCode($tenant) || $amount <= 0) {
            return ['percent' => $percent, 'fee' => null, 'academy' => null];
        }

        $fee = round($amount * $percent / 100, 2);

        return ['percent' => $percent, 'fee' => $fee, 'academy' => round($amount - $fee, 2)];
    }

    /** "NGN 30,000.00", currency code rather than a symbol (charges are not always NGN). */
    public static function money(?string $currency, float $amount): string
    {
        return strtoupper((string) ($currency ?: 'NGN')) . ' ' . number_format($amount, 2);
    }

    /**
     * The owner-facing breakdown for one payment, e.g.
     * "Service charge (5%): NGN 1,500.00. You receive NGN 28,500.00 (before
     * Paystack's processing fee)." Empty when the payment was not split (no
     * academy share recorded: it settled whole to the platform account).
     */
    public static function breakdownLine(Payment $payment, ?Tenant $tenant = null): string
    {
        if ($payment->academy_amount === null || $payment->platform_fee === null) {
            return '';
        }

        $percent = $tenant ? $tenant->commissionPercent() : null;
        $amount = (float) $payment->amount;
        $fee = (float) $payment->platform_fee;
        $academy = $payment->academy_amount !== null ? (float) $payment->academy_amount : $amount - $fee;

        // The percent shown is derived from the stored amounts, so a later rate
        // change never misdescribes an old payment.
        $shown = $amount > 0 ? round($fee / $amount * 100, 2) : $percent;
        $shown = rtrim(rtrim(number_format((float) $shown, 2, '.', ''), '0'), '.');

        return 'Service charge (' . $shown . '%): ' . static::money($payment->currency, $fee)
            . '. You receive ' . static::money($payment->currency, $academy)
            . ' (before Paystack\'s processing fee).';
    }

    /**
     * Tell the owner a payment landed, with the service-charge breakdown. Used
     * for renewals; a first payment is announced by the enrolment bell, which
     * carries the same breakdown (see LmsIntakeController::completePayment).
     */
    public static function notifyOwnerOfRenewal(TrainingRegistration $registration, Payment $payment): void
    {
        $name = trim($registration->first_name . ' ' . $registration->last_name) ?: $registration->email;
        $tenant = $registration->tenant_id ? Tenant::find($registration->tenant_id) : null;
        $amount = static::money($payment->currency, (float) $payment->amount);

        Notify::owner(
            'payment',
            'Monthly payment received: ' . $amount,
            trim($name . ' paid ' . $amount . ' for ' . $registration->course_name
                . ' (monthly, paid until ' . $registration->paid_until?->format('j M Y') . '). '
                . static::breakdownLine($payment, $tenant)),
            'payment',
            $payment->id,
            $registration->tenant_id ? (int) $registration->tenant_id : null
        );
    }

    /* ---------------------------------------------------------------------
     * Monthly lifecycle
     * ------------------------------------------------------------------ */

    public static function graceDays(): int
    {
        return max(0, (int) config('saas.monthly_grace_days', 3));
    }

    /** When access pauses for this registration, or null when it never will. */
    public static function accessEndsAt(TrainingRegistration $registration): ?Carbon
    {
        if (! $registration->isMonthly() || ! $registration->paid_until) {
            return null;
        }

        return match ($registration->billing_status) {
            self::STATUS_ACTIVE, self::STATUS_PAST_DUE => $registration->paid_until->copy()->addDays(static::graceDays()),
            self::STATUS_CANCELLED => $registration->paid_until->copy(),
            default => null,
        };
    }

    public static function isLocked(?TrainingRegistration $registration): bool
    {
        $ends = $registration ? static::accessEndsAt($registration) : null;

        return $ends !== null && now()->greaterThan($ends);
    }

    /**
     * The registration a student's access hangs on: their CURRENT one
     * (lms_students.training_registration_id). Read across tenants but pinned to
     * the student's own, since this runs from middleware before any scoping.
     */
    public static function currentRegistration(LmsStudent $student): ?TrainingRegistration
    {
        if (! $student->training_registration_id) {
            return null;
        }

        return TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($student->training_registration_id)
            ->when($student->tenant_id, fn ($q) => $q->where('tenant_id', $student->tenant_id))
            ->first();
    }

    /**
     * Everything the student portal shows about billing (me + billing page).
     *
     * @return array<string,mixed>
     */
    public static function summary(?TrainingRegistration $registration): array
    {
        if (! $registration || ! $registration->isMonthly()) {
            return ['monthly' => false, 'locked' => false];
        }

        $card = static::authorization($registration);
        $locked = static::isLocked($registration);

        return [
            'monthly' => true,
            'registration_id' => $registration->id,
            'course_name' => $registration->course_name,
            'amount' => (float) $registration->course_price,
            'currency' => strtoupper((string) ($registration->charge_currency ?: 'NGN')),
            'status' => $registration->billing_status,
            'paid_until' => $registration->paid_until?->toIso8601String(),
            'access_ends_at' => static::accessEndsAt($registration)?->toIso8601String(),
            'grace_days' => static::graceDays(),
            'locked' => $locked,
            // Due = inside the reminder window or past the due date, not ended.
            'due' => in_array($registration->billing_status, [self::STATUS_ACTIVE, self::STATUS_PAST_DUE, self::STATUS_CANCELLED], true)
                && $registration->paid_until
                && now()->greaterThanOrEqualTo($registration->paid_until->copy()->subDays((int) config('saas.monthly_reminder_days', 3))),
            'can_pay' => $registration->billing_status !== self::STATUS_ENDED,
            'can_cancel' => in_array($registration->billing_status, [self::STATUS_ACTIVE, self::STATUS_PAST_DUE], true),
            'can_resume' => $registration->billing_status === self::STATUS_CANCELLED && ! $locked,
            'card' => $card ? ['last4' => $card['last4'] ?? null, 'brand' => $card['brand'] ?? null] : null,
        ];
    }

    /**
     * Start the first month on the student's first successful payment.
     */
    public static function startFirstPeriod(TrainingRegistration $registration, Payment $payment, ?array $gatewayData): void
    {
        if (! $registration->isMonthly()) {
            return;
        }

        $paidUntil = now()->addMonthNoOverflow();

        $registration->forceFill([
            'paid_until' => $paidUntil,
            'billing_status' => self::STATUS_ACTIVE,
            'billing_cancelled_at' => null,
        ]);
        static::captureAuthorization($registration, $gatewayData);
        $registration->save();

        $payment->forceFill(['period_end' => $paidUntil])->save();
    }

    /**
     * Keep a reusable CARD authorization for automatic renewals. Transfer, USSD
     * and bank payments are not reusable, so those students renew from a link.
     */
    public static function captureAuthorization(TrainingRegistration $registration, ?array $gatewayData): void
    {
        $auth = (array) ($gatewayData['authorization'] ?? []);

        if (empty($auth['authorization_code']) || empty($auth['reusable']) || ($auth['channel'] ?? 'card') !== 'card') {
            return;
        }

        $registration->paystack_authorization = json_encode([
            'code' => (string) $auth['authorization_code'],
            'last4' => $auth['last4'] ?? null,
            'brand' => $auth['card_type'] ?? ($auth['brand'] ?? null),
            'exp' => trim(($auth['exp_month'] ?? '') . '/' . ($auth['exp_year'] ?? ''), '/') ?: null,
        ]);
    }

    /** @return array{code:string,last4:?string,brand:?string,exp:?string}|null */
    public static function authorization(TrainingRegistration $registration): ?array
    {
        try {
            $raw = $registration->paystack_authorization;
        } catch (\Throwable $e) {
            // Undecryptable (APP_KEY rotated): treat as no saved card.
            return null;
        }

        $data = $raw ? json_decode((string) $raw, true) : null;

        return is_array($data) && ! empty($data['code']) ? $data : null;
    }

    /**
     * A pending renewal Payment for the next month, with its service charge
     * worked out. Tenant is the registration's own, so this is safe from the
     * console sweep as well as a request.
     *
     * @return array{0: Payment, 1: ?float}  [payment, service charge]
     */
    public static function newRenewalPayment(TrainingRegistration $registration, string $gateway = 'paystack'): array
    {
        $tenant = Tenant::find($registration->tenant_id);
        $amount = (float) $registration->course_price;
        $charge = static::serviceCharge($tenant, $amount);

        $payment = Payment::createForTenant((int) $registration->tenant_id, [
            'registration_id' => $registration->id,
            'reference' => 'JORSAS-M-' . Str::upper(Str::random(18)),
            'amount' => $amount,
            'currency' => strtoupper((string) ($registration->charge_currency ?: 'NGN')),
            'status' => 'pending',
            'gateway' => $gateway,
            'kind' => Payment::KIND_RENEWAL,
            'platform_fee' => $charge['fee'] ?? 0,
            'academy_amount' => $charge['academy'],
        ]);

        return [$payment, $charge['fee']];
    }

    /**
     * Confirm a renewal payment and extend access by a month.
     *
     * Idempotent and race-safe: the browser verify, the webhook and a
     * synchronous card charge can all report the same reference, so the payment
     * row is locked and re-checked inside a transaction and only the first
     * caller extends the period.
     *
     * Returns 'success', 'already', or 'review' (amount/currency mismatch, held
     * for a human exactly like a first payment).
     */
    public static function completeRenewal(Payment $payment, ?array $gatewayData = null): string
    {
        $result = DB::transaction(function () use ($payment, $gatewayData) {
            $fresh = Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->status === 'success') {
                return ['already', null, null];
            }

            if (is_array($gatewayData) && $gatewayData !== [] && ! static::gatewayMatches($fresh, $gatewayData)) {
                $fresh->update(['status' => 'review', 'gateway_response' => $gatewayData]);

                return ['review', null, null];
            }

            $registration = TrainingRegistration::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($fresh->registration_id)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                return ['already', null, null];
            }

            $from = $registration->paid_until && $registration->paid_until->isFuture()
                ? $registration->paid_until->copy()
                : now();
            $paidUntil = $from->addMonthNoOverflow();

            $fresh->update([
                'status' => 'success',
                'period_end' => $paidUntil,
                'gateway_response' => is_array($gatewayData) && $gatewayData !== [] ? $gatewayData : $fresh->gateway_response,
            ]);

            $registration->forceFill([
                'paid_until' => $paidUntil,
                // Paying again also un-cancels: the student chose to continue.
                'billing_status' => self::STATUS_ACTIVE,
                'billing_cancelled_at' => null,
                'billing_reminded_for' => null,
                'billing_lock_notified_at' => null,
            ]);
            static::captureAuthorization($registration, $gatewayData);
            $registration->save();

            return ['success', $fresh, $registration];
        });

        [$status, $fresh, $registration] = $result;

        if ($status === 'success') {
            // Courtesies, after the money is recorded; never allowed to undo it.
            try {
                static::notifyOwnerOfRenewal($registration, $fresh);
                static::notifyStudent(
                    $registration,
                    'billing_paid',
                    'Payment received, thank you',
                    'We received ' . static::money($fresh->currency, (float) $fresh->amount) . ' for '
                        . $registration->course_name . '. You are paid up until '
                        . $registration->paid_until->format('j M Y') . '.'
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $status;
    }

    /**
     * Same reconciliation as a first payment: the gateway must have collected
     * exactly the amount and currency we asked for.
     */
    public static function gatewayMatches(Payment $payment, array $gatewayData): bool
    {
        $expectedMinor = (int) round(((float) $payment->amount) * 100);
        $gotMinor = (int) ($gatewayData['amount'] ?? 0);
        $expectedCurrency = strtoupper((string) ($payment->currency ?: 'NGN'));
        $gotCurrency = strtoupper((string) ($gatewayData['currency'] ?? ''));

        if ($gotMinor > 0 && $gotMinor !== $expectedMinor) {
            Log::warning('Monthly renewal amount mismatch; holding for review', [
                'reference' => $payment->reference, 'expected' => $expectedMinor, 'got' => $gotMinor,
            ]);

            return false;
        }

        if ($gotCurrency !== '' && $gotCurrency !== $expectedCurrency) {
            Log::warning('Monthly renewal currency mismatch; holding for review', [
                'reference' => $payment->reference, 'expected' => $expectedCurrency, 'got' => $gotCurrency,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Whether billing for this registration should stop for good: the student
     * moved on to another course (it is no longer their current registration),
     * or the cohort they are in has finished (its end date is on or before the
     * day the next month would start).
     */
    public static function shouldEnd(TrainingRegistration $registration): bool
    {
        $student = LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $registration->tenant_id)
            ->where('training_registration_id', $registration->id)
            ->first(['id', 'tenant_id', 'training_registration_id']);

        if (! $student) {
            return true;
        }

        $trackId = LmsEnrollment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->value('track_id');

        $endDate = $trackId
            ? LmsTrack::query()->withoutGlobalScope(TenantScope::class)->whereKey($trackId)->value('end_date')
            : null;

        if (! $endDate) {
            return false;
        }

        $end = Carbon::parse($endDate)->endOfDay();
        $nextPeriodStarts = $registration->paid_until ?? now();

        return $end->lessThanOrEqualTo($nextPeriodStarts);
    }

    /** Deep link to the student billing page on the student's own academy. */
    public static function billingUrl(TrainingRegistration $registration, array $query = []): string
    {
        $slug = $registration->tenant_id ? Tenant::query()->whereKey($registration->tenant_id)->value('slug') : null;
        $path = '/lms/app/billing' . ($query ? '?' . http_build_query($query) : '');

        return NotificationLinks::tenantPinned($path, $slug);
    }

    /** Bell (and, via the email sweep, an email) for the student on this registration. */
    public static function notifyStudent(TrainingRegistration $registration, string $type, string $title, string $body): void
    {
        $studentId = LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $registration->tenant_id)
            ->where('training_registration_id', $registration->id)
            ->value('id');

        if ($studentId) {
            Notify::student((int) $studentId, $type, $title, $body, 'billing', $registration->id);
        }
    }
}
