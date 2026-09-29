<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsStudent;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\CourseBilling;
use App\Services\PaystackService;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The student's own monthly-course billing: see where they stand, pay for the
 * next month, confirm that payment, and stop or restart renewing.
 *
 * Every route here is on EnsureAccountActive's billing allow-list, because a
 * student whose access has paused must still be able to reach the one screen
 * that lets them pay their way back in.
 */
class StudentBillingController extends BaseLmsController
{
    public function show(Request $request): JsonResponse
    {
        [$student, $registration] = $this->context($request);
        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payments = $registration
            ? Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('registration_id', $registration->id)
                ->where('status', 'success')
                ->latest('id')
                ->limit(24)
                ->get(['id', 'reference', 'amount', 'currency', 'kind', 'period_end', 'created_at'])
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'amount' => (float) $p->amount,
                    'currency' => $p->currency,
                    'kind' => $p->kind,
                    'period_end' => $p->period_end?->toIso8601String(),
                    'paid_at' => $p->created_at?->toIso8601String(),
                ])
            : collect();

        return response()->json([
            'billing' => CourseBilling::summary($registration),
            'payments' => $payments,
        ]);
    }

    /** Start a Paystack checkout for the next month. */
    public function renew(Request $request): JsonResponse
    {
        [$student, $registration] = $this->context($request);
        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $registration || ! $registration->isMonthly()) {
            return response()->json(['message' => 'Your course is not billed monthly.'], 422);
        }

        if ($registration->billing_status === CourseBilling::STATUS_ENDED) {
            return response()->json(['message' => 'This course has finished, so there is nothing more to pay.'], 422);
        }

        $tenant = Tenant::find($registration->tenant_id);
        $subaccount = CourseBilling::subaccountCode($tenant);

        // Same money-safety rule as a first purchase: a non-primary academy with
        // no linked bank must not collect into the platform account.
        if ($tenant && ! $tenant->isPrimary() && ! $subaccount) {
            return response()->json([
                'message' => 'Your academy is finishing its payment setup. Please try again later or contact them.',
            ], 409);
        }

        $paystack = app(PaystackService::class);
        if (! $paystack->isConfigured()) {
            return response()->json(['message' => 'Payments are not available right now. Please try again later.'], 503);
        }

        [$payment, $fee] = CourseBilling::newRenewalPayment($registration);

        try {
            $response = $paystack->initializeTransaction(
                $registration->email,
                (float) $payment->amount,
                $payment->reference,
                [
                    'registration_id' => $registration->id,
                    'course_name' => $registration->course_name,
                    'purpose' => 'monthly_renewal',
                ],
                CourseBilling::billingUrl($registration, ['reference' => $payment->reference]),
                $subaccount,
                $payment->currency,
                $fee
            );
        } catch (\Throwable $e) {
            Log::error('Monthly renewal initialization failed', [
                'registration_id' => $registration->id,
                'err' => $e->getMessage(),
            ]);
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => 'Could not start the payment. Please try again.'], 500);
        }

        $url = $response['data']['authorization_url'] ?? null;
        if (! $url) {
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => $response['message'] ?? 'Could not start the payment. Please try again.'], 502);
        }

        return response()->json(['authorization_url' => $url, 'reference' => $payment->reference]);
    }

    /** Confirm a renewal after Paystack sends the student back. */
    public function verify(Request $request): JsonResponse
    {
        [$student, $registration] = $this->context($request);
        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $reference = (string) $request->query('reference', '');

        // Only a renewal of THIS student's own registration can be confirmed
        // here, so a reference from someone else's checkout is simply not found.
        $payment = $registration && $reference !== ''
            ? Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('reference', $reference)
                ->where('registration_id', $registration->id)
                ->where('kind', Payment::KIND_RENEWAL)
                ->first()
            : null;

        if (! $payment) {
            return response()->json(['message' => 'Payment reference not found.'], 404);
        }

        if ($payment->status !== 'success') {
            try {
                $response = app(PaystackService::class)->verifyTransaction($reference);
            } catch (\Throwable $e) {
                Log::error('Monthly renewal verification failed', ['reference' => $reference, 'err' => $e->getMessage()]);

                return response()->json(['message' => 'Could not verify the payment yet. Please refresh in a moment.'], 500);
            }

            if (($response['data']['status'] ?? '') !== 'success') {
                return response()->json([
                    'message' => 'Payment not confirmed yet.',
                    'status' => $response['data']['status'] ?? 'unknown',
                    'billing' => CourseBilling::summary($registration),
                ]);
            }

            $status = CourseBilling::completeRenewal($payment, (array) ($response['data'] ?? []));
            if ($status === 'review') {
                return response()->json([
                    'message' => 'Payment received but needs manual confirmation. Your academy will confirm it shortly.',
                    'status' => 'review',
                ]);
            }
        }

        return response()->json([
            'message' => 'Payment confirmed. Thank you!',
            'status' => 'success',
            'billing' => CourseBilling::summary($registration->fresh()),
        ]);
    }

    /** Stop renewing. Access continues until the end of the month already paid. */
    public function cancel(Request $request): JsonResponse
    {
        [$student, $registration] = $this->context($request);
        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $registration || ! in_array($registration->billing_status, [CourseBilling::STATUS_ACTIVE, CourseBilling::STATUS_PAST_DUE], true)) {
            return response()->json(['message' => 'There is no monthly payment to cancel.'], 422);
        }

        $registration->forceFill([
            'billing_status' => CourseBilling::STATUS_CANCELLED,
            'billing_cancelled_at' => now(),
        ])->save();

        $name = trim($student->first_name . ' ' . $student->last_name) ?: $student->email;
        Notify::owner(
            'billing_cancelled',
            'Monthly payment cancelled: ' . $name,
            $name . ' stopped their monthly payments for ' . $registration->course_name
                . '. Their access ends on ' . $registration->paid_until?->format('j M Y') . ' unless they pay again.',
            'student',
            $student->id,
            $registration->tenant_id ? (int) $registration->tenant_id : null
        );

        return response()->json([
            'message' => 'Monthly payments cancelled. You keep access until ' . $registration->paid_until?->format('j M Y') . '.',
            'billing' => CourseBilling::summary($registration),
        ]);
    }

    /** Undo a cancellation while the paid month is still running. */
    public function resume(Request $request): JsonResponse
    {
        [$student, $registration] = $this->context($request);
        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $registration || $registration->billing_status !== CourseBilling::STATUS_CANCELLED || CourseBilling::isLocked($registration)) {
            return response()->json(['message' => 'Nothing to resume. If your access has paused, pay for the next month instead.'], 422);
        }

        $registration->forceFill([
            'billing_status' => CourseBilling::STATUS_ACTIVE,
            'billing_cancelled_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Monthly payments turned back on.',
            'billing' => CourseBilling::summary($registration),
        ]);
    }

    /** @return array{0: ?LmsStudent, 1: ?TrainingRegistration} */
    private function context(Request $request): array
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) {
            return [null, null];
        }

        $student = LmsStudent::query()->withoutGlobalScope(TenantScope::class)->find($session->user_id);
        if (! $student) {
            return [null, null];
        }

        return [$student, CourseBilling::currentRegistration($student)];
    }
}
