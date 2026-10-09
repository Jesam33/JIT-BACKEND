<?php

namespace App\Services;

use App\Models\PlatformTransaction;
use App\Models\Tenant;
use App\Support\PlanPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jorsas's own billing: an academy paying for its plan (pay-first signup or
 * a plan upgrade/renewal). The money goes to the platform's account, so there
 * is no split.
 *
 * Charged through Flutterwave in the academy's own currency
 * (PlanPricing::priceIn), or through Paystack in naira when Flutterwave isn't
 * configured. Every attempt is a PlatformTransaction row (the host's revenue
 * ledger) carrying its gateway, amount and currency, so confirmation always
 * asks the right gateway and checks the right figures.
 *
 * Paystack confirmations keep their original paths (TenantSignupController /
 * TenantBillingController / PaystackWebhookController); Flutterwave ones come
 * through confirm() from the browser verify and the Flutterwave webhook.
 */
class PlatformBilling
{
    public const SIGNUP = 'tenant_signup';

    public const UPGRADE = 'plan_upgrade';

    /** The gateway plan payments use: Flutterwave, else Paystack, else none. */
    public static function gateway(): ?string
    {
        return CoursePayments::linkProvider();
    }

    /** What this academy pays for a plan now. @return array{amount: float, currency: string}|null */
    public static function priceFor(Tenant $tenant, string $plan): ?array
    {
        // Paystack only collects naira for us.
        $currency = static::gateway() === CoursePayments::FLUTTERWAVE ? $tenant->currency() : 'NGN';

        return PlanPricing::priceIn($plan, $currency);
    }

    /**
     * Start a checkout and record the pending ledger row.
     *
     * @return array{ok: bool, authorization_url?: string, message?: string, amount?: float, currency?: string}
     */
    public static function start(Tenant $tenant, string $email, string $name, string $plan, string $purpose, string $reference, string $callbackUrl): array
    {
        $gateway = static::gateway();
        $price = static::priceFor($tenant, $plan);
        if (! $gateway || ! $price || $price['amount'] <= 0) {
            return ['ok' => false, 'message' => 'Payments are not available right now. Please contact support.'];
        }

        $meta = ['tenant_id' => $tenant->id, 'plan' => $plan, 'purpose' => $purpose];

        try {
            if ($gateway === CoursePayments::FLUTTERWAVE) {
                $body = CoursePayments::flutterwave()->initializePayment(
                    $reference, $price['amount'], $price['currency'], $email, $name, $callbackUrl,
                    null, null, $meta, 'Jorsas ' . ucfirst($plan) . ' plan'
                );
                $url = FlutterwaveService::ok($body) ? ($body['data']['link'] ?? null) : null;
                $message = $body['message'] ?? null;
            } else {
                $body = CoursePayments::paystack()->initializeTransaction($email, $price['amount'], $reference, $meta, $callbackUrl);
                $url = (data_get($body, 'status') !== false) ? data_get($body, 'data.authorization_url') : null;
                $message = data_get($body, 'message');
            }
        } catch (\Throwable $e) {
            Log::warning('Plan checkout init failed', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not start payment. Please try again.'];
        }

        if (! $url) {
            return ['ok' => false, 'message' => (string) ($message ?: 'Could not start payment. Please try again.')];
        }

        try {
            PlatformTransaction::recordPending($tenant, $reference, $price['amount'], $purpose, $plan, $price['currency'], $gateway);
        } catch (\Throwable $e) {
            Log::warning('Could not record pending platform transaction', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);
        }

        return ['ok' => true, 'authorization_url' => $url] + $price;
    }

    /**
     * Confirm a Flutterwave plan payment: re-read it from Flutterwave, check
     * the amount and currency against the ledger row, then activate the plan
     * (upgrade) or the academy (signup), exactly once. Safe to call from the
     * browser and the webhook in any order.
     *
     * Returns 'success', 'pending' (not paid yet) or 'review' (paid the wrong
     * amount/currency: held for a human, nothing activated).
     *
     * A signup's provisioning can throw (TenantOnboardingService self-heals and
     * rethrows); callers turn that into a retryable "finalizing" response.
     */
    public static function confirm(PlatformTransaction $tx): string
    {
        $tenant = Tenant::find($tx->tenant_id);
        if (! $tenant) {
            return 'pending';
        }

        if ($tx->status !== 'success') {
            $body = CoursePayments::flutterwave()->verifyByReference((string) $tx->reference);
            if (! FlutterwaveService::ok($body) || empty($body['data'])) {
                return 'pending';
            }

            $data = FlutterwaveService::normalize((array) $body['data']);
            if (($data['status'] ?? '') !== 'success' || ($data['reference'] ?? null) !== $tx->reference) {
                return 'pending';
            }

            $expectedMinor = (int) round(((float) $tx->amount) * 100);
            if ((int) $data['amount'] !== $expectedMinor || strtoupper((string) $data['currency']) !== strtoupper((string) $tx->currency)) {
                Log::warning('Plan payment mismatch; held for review', [
                    'reference' => $tx->reference, 'expected' => [$expectedMinor, $tx->currency], 'got' => [$data['amount'], $data['currency']],
                ]);
                $tx->update(['status' => 'review', 'gateway_response' => $data]);

                return 'review';
            }

            // Activate and record together: a plan is extended exactly once.
            DB::transaction(function () use ($tx, $tenant, $data) {
                $fresh = PlatformTransaction::query()->whereKey($tx->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status === 'success') {
                    return;
                }
                if ($fresh->purpose === self::UPGRADE && $fresh->plan && isset(config('saas.plans', [])[$fresh->plan])) {
                    $tenant->activatePlan($fresh->plan);
                }
                PlatformTransaction::markSuccess($tenant, (string) $fresh->reference, (string) $fresh->purpose, $fresh->plan, $data);
            });
        }

        // Provisioning a paid signup is idempotent (pending → active once).
        if ($tx->purpose === self::SIGNUP && $tenant->fresh()->status === 'pending') {
            app(TenantOnboardingService::class)->activatePaidSignup($tenant->fresh());
        }

        return 'success';
    }
}
