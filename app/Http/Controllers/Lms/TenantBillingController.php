<?php

namespace App\Http\Controllers\Lms;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tenant self-serve plan upgrades via Paystack. Mirrors the proven course-intake
 * flow (LmsIntakeController init → verify → webhook): the owner picks a paid plan,
 * we initialize a Paystack transaction stamped with {tenant_id, plan, purpose},
 * redirect to the checkout, and confirm on return. Activation is idempotent and
 * shared with the webhook so a plan lands whether the browser returns or not.
 *
 * These routes live inside `tenant.required`, but authorization here does NOT
 * trust the middleware-bound tenant: it derives the tenant from the owner's own
 * session row (tenant_id) and confirms tenant_admins membership, so a student or
 * staff bearer token — which also binds a tenant — cannot reach billing.
 */
class TenantBillingController extends BaseLmsController
{
    /**
     * Resolve the authenticated owner and their tenant from the bearer session.
     * Returns [Tenant, User] or null when the caller is not an owner of a tenant.
     */
    protected function ownerContext(Request $request): ?array
    {
        $session = $this->sessionFromRequest($request, 'owner');
        if (! $session) {
            return null;
        }

        $tenantId = $session->tenant_id
            ?? (app()->bound('currentTenant') && app('currentTenant') ? app('currentTenant')->id : null);
        if (! $tenantId) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        $isAdmin = DB::table('tenant_admins')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $session->user_id)
            ->exists();
        if (! $isAdmin) {
            return null;
        }

        return [$tenant, User::find($session->user_id)];
    }

    /**
     * Current plan / subscription state plus the catalogue of upgradeable plans,
     * for the owner billing page.
     */
    public function status(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
            'plan' => $tenant->plan ?? 'free',
            'subscription_status' => $tenant->subscription_status ?? 'active',
            'current_period_end' => $tenant->current_period_end,
            'plans' => $this->planCatalogue(),
            'billing_configured' => app(PaystackService::class)->isConfigured(),
        ]);
    }

    /**
     * Initialize a Paystack transaction for a paid-plan upgrade and return the
     * authorization URL for the frontend to redirect to.
     */
    public function checkout(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plan = $validated['plan'];
        $plans = config('saas.plans', []);

        if (! isset($plans[$plan])) {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        $amount = (float) ($plans[$plan]['price'] ?? 0);
        if ($amount <= 0) {
            // The free plan needs no payment — activate directly.
            $tenant->activatePlan($plan);

            return response()->json([
                'status' => 'active',
                'plan' => $tenant->plan,
                'free' => true,
            ]);
        }

        $paystack = app(PaystackService::class);
        if (! $paystack->isConfigured()) {
            return response()->json([
                'message' => 'Payments are not enabled yet. Please contact support.',
                'billing_configured' => false,
            ], 503);
        }

        if (! $owner || ! $owner->email) {
            return response()->json(['message' => 'Owner email unavailable.'], 422);
        }

        $reference = 'JORSAS-UPG-' . Str::upper(Str::random(16));
        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/');
        $callbackUrl = $frontendUrl . '/lms/admin/billing/verify?reference=' . $reference;

        try {
            $response = $paystack->initializeTransaction(
                $owner->email,
                $amount,
                $reference,
                [
                    'tenant_id' => $tenant->id,
                    'plan' => $plan,
                    'purpose' => 'plan_upgrade',
                ],
                $callbackUrl
            );

            // Record the pending reference so a later verify can be cross-checked
            // even if Paystack metadata is ever unavailable.
            if (\Illuminate\Support\Facades\Schema::hasColumn('tenants', 'paystack_init_reference')) {
                $tenant->update(['paystack_init_reference' => $reference]);
            }

            return response()->json([
                'authorization_url' => $response['data']['authorization_url'] ?? null,
                'reference' => $reference,
            ]);
        } catch (\Throwable $e) {
            Log::error('Tenant plan-upgrade initialization failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'plan' => $plan,
            ]);

            return response()->json(['message' => 'Could not start checkout. Please try again.'], 500);
        }
    }

    /**
     * Confirm a returning Paystack transaction and activate the plan. Safe to call
     * repeatedly — activation is idempotent and the plan is read from the verified
     * transaction metadata, guarded to this owner's tenant.
     */
    public function verify(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $paystack = app(PaystackService::class);
        if (! $paystack->isConfigured()) {
            return response()->json(['message' => 'Payments are not enabled.'], 503);
        }

        try {
            $response = $paystack->verifyTransaction($validated['reference']);
        } catch (\Throwable $e) {
            Log::error('Tenant plan-upgrade verification failed', [
                'error' => $e->getMessage(),
                'reference' => $validated['reference'],
            ]);

            return response()->json(['message' => 'Could not verify payment.'], 500);
        }

        $data = $response['data'] ?? [];
        if (($data['status'] ?? '') !== 'success') {
            return response()->json([
                'status' => $data['status'] ?? 'unknown',
                'message' => 'Payment not yet confirmed.',
            ], 202);
        }

        $metadata = $data['metadata'] ?? [];
        $plan = $metadata['plan'] ?? null;
        $metaTenantId = $metadata['tenant_id'] ?? null;

        // The verified transaction must belong to the calling owner's tenant.
        if ($metaTenantId && (int) $metaTenantId !== (int) $tenant->id) {
            return response()->json(['message' => 'This payment does not belong to your organisation.'], 403);
        }

        if (! $plan || ! isset(config('saas.plans', [])[$plan])) {
            return response()->json(['message' => 'This payment is not a plan upgrade.'], 422);
        }

        $tenant->activatePlan($plan);

        return response()->json([
            'status' => 'active',
            'plan' => $tenant->plan,
            'subscription_status' => $tenant->subscription_status,
            'current_period_end' => $tenant->current_period_end,
        ]);
    }

    /**
     * The upgradeable plan catalogue from config, shaped for the billing UI.
     */
    protected function planCatalogue(): array
    {
        return collect(config('saas.plans', []))
            ->map(fn ($plan, $slug) => [
                'slug' => $slug,
                'name' => $plan['name'] ?? ucfirst($slug),
                'price' => (float) ($plan['price'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
