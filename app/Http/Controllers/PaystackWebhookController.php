<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify EVERY webhook, fail-closed. Paystack signs the raw body with
        // HMAC-SHA512 under our secret key; a missing secret, a missing header,
        // or a non-matching signature is rejected here so execution NEVER falls
        // through to the money-moving handlers below (activatePlan /
        // activatePaidSignup). hash_equals keeps the compare constant-time.
        $secret = (string) config('services.paystack.secret_key', '');
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($secret === '') {
            Log::error('Paystack webhook received but no secret configured; rejecting.');
            return response()->json(['status' => false, 'message' => 'Webhook not configured'], 503);
        }

        $computed = hash_hmac('sha512', $request->getContent(), $secret);
        if ($signature === '' || ! hash_equals($computed, $signature)) {
            Log::warning('Paystack webhook signature missing or invalid');
            return response()->json(['status' => false, 'message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        Log::info('Paystack webhook received', ['payload' => $payload]);

        // Compute an idempotency key for this webhook: prefer data.reference or data.id
        $data = data_get($payload, 'data', []);
        $event = data_get($payload, 'event');
        $eventKey = data_get($data, 'reference') ?: data_get($data, 'id') ?: data_get($payload, 'id') ?: null;

        if ($eventKey) {
            try {
                DB::table('paystack_events')->insertOrIgnore([
                    'event' => $event,
                    'event_key' => (string) $eventKey,
                    'payload' => json_encode($payload),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted = DB::table('paystack_events')->where('event', $event)->where('event_key', (string) $eventKey)->exists();
                if (! $inserted) {
                    Log::info('Paystack webhook duplicate, skipping', ['event' => $event, 'event_key' => $eventKey]);
                    return response()->json(['status' => true]);
                }
            } catch (\Throwable $e) {
                Log::warning('Could not persist paystack event for idempotency', ['err' => $e->getMessage()]);
            }
        }

        // Handle transaction or subscription events
        // $event and $data already defined above

        try {
            if ($event === 'charge.success' || $event === 'transaction.success') {
                // For signup, we may have stored tenant_id in metadata
                $metadata = data_get($data, 'metadata', []);
                $tenantId = $metadata['tenant_id'] ?? null;
                $reference = data_get($data, 'reference');
                $customerCode = data_get($data, 'customer.customer_code') ?: data_get($data, 'customer.customer_code');
                if ($tenantId) {
                    $tenant = Tenant::find($tenantId);
                    if ($tenant) {
                        // store customer code and transaction reference
                        $tenant->update([
                            'paystack_customer_code' => $customerCode ?? $tenant->paystack_customer_code,
                            'paystack_subscription_id' => $reference ?? $tenant->paystack_subscription_id,
                        ]);

                        // Self-serve plan upgrade: activate the purchased plan.
                        // Idempotent (the paystack_events guard above dedupes
                        // redeliveries) and mirrors the synchronous verify path,
                        // so the plan lands even if the browser never returns.
                        $plan = $metadata['plan'] ?? null;
                        $purpose = $metadata['purpose'] ?? null;
                        if ($purpose === 'plan_upgrade'
                            && $plan
                            && isset(config('saas.plans', [])[$plan])) {
                            $tenant->activatePlan($plan);
                        } elseif ($purpose === 'tenant_signup') {
                            // Pay-first signup confirmed asynchronously. Activate
                            // and provision the tenant (seed content + email the
                            // owner setup link). Idempotent: activatePaidSignup
                            // guards the pending->active transition, so this is
                            // safe even when the browser verify already ran.
                            app(\App\Services\TenantOnboardingService::class)->activatePaidSignup($tenant);
                        }

                        // Record the confirmed payment in the platform revenue
                        // ledger. Idempotent on the reference, so this never
                        // double-counts against the synchronous verify paths that
                        // may have already marked it, whichever confirms first wins.
                        if (in_array($purpose, ['plan_upgrade', 'tenant_signup'], true) && $reference) {
                            try {
                                \App\Models\PlatformTransaction::markSuccess(
                                    $tenant,
                                    (string) $reference,
                                    $purpose,
                                    $plan ?? data_get($tenant->settings, 'plan', $tenant->plan),
                                    (array) $data,
                                );
                            } catch (\Throwable $e) {
                                Log::warning('Could not mark platform transaction success (webhook)', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);
                            }
                        }
                    }
                }
            }

            if (str_starts_with($event, 'subscription')) {
                $tenantRef = data_get($data, 'customer.customer_metadata.tenant_id') ?? data_get($data, 'metadata.tenant_id');
                $subscriptionId = data_get($data, 'id') ?? data_get($data, 'subscription_code') ?? data_get($data, 'subscription.id');
                if ($tenantRef) {
                    $tenant = Tenant::find($tenantRef);
                    if ($tenant) {
                        $tenant->update([
                            'paystack_subscription_id' => $subscriptionId ?? $tenant->paystack_subscription_id,
                            'status' => 'active',
                        ]);
                    }
                }
            }

            return response()->json(['status' => true]);
        } catch (\Throwable $e) {
            Log::error('Error handling Paystack webhook', ['err' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'error'], 500);
        }
    }
}
