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
 * staff bearer token, which also binds a tenant, cannot reach billing.
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
     * Sanitise the browser-supplied return origin used for the Paystack callback.
     * Returns a bare "scheme://host[:port]" only when it is safe, else null (the
     * caller then falls back to the configured frontend URL). A bare origin, no
     * path/query/fragment, that is either a local dev host or the configured
     * frontend host / a subdomain of its root domain, so this can never become an
     * open redirect to an attacker-chosen destination.
     */
    protected function safeReturnOrigin(?string $origin): ?string
    {
        if (! is_string($origin) || trim($origin) === '') {
            return null;
        }

        $parts = parse_url(rtrim(trim($origin), '/'));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        // An origin is scheme://host[:port] and nothing more, reject anything
        // carrying a path, query, fragment or credentials.
        if ((isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $allowed = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if (! $allowed) {
            $feHost = strtolower((string) parse_url((string) config('saas.frontend_url'), PHP_URL_HOST));
            if ($feHost !== '') {
                $root = $this->rootDomain($feHost);
                $allowed = $host === $feHost || $host === $root || str_ends_with($host, '.' . $root);
            }
        }
        if (! $allowed) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $host . $port;
    }

    /** The registrable-ish root ("a.b.example.com" → "example.com") for origin matching. */
    private function rootDomain(string $host): string
    {
        $labels = explode('.', $host);
        $n = count($labels);

        return $n >= 2 ? ($labels[$n - 2] . '.' . $labels[$n - 1]) : $host;
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
            // Live subscription lifecycle (active | grace | frozen) plus the grace
            // window + whether enforcement is switched on, so the billing page can
            // show a renew banner before the freeze and the owner shell knows when
            // to lock. Always safe: reports active for an exempt tenant.
            'subscription' => $tenant->subscriptionInfo(),
            // Resolved current plan (limits + features + commission) with live usage
            // counts, so the billing page can show "12 / 50 students" and which
            // features the current plan includes. Primary institute reads as 'pro'.
            'plan_summary' => $tenant->planSummaryArray(),
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
            // The frontend sends the origin the owner is actually on, so the
            // Paystack callback returns them THERE (see safeReturnOrigin + below).
            'return_origin' => ['nullable', 'string', 'max:2048'],
        ]);

        $plan = $validated['plan'];
        $plans = config('saas.plans', []);

        if (! isset($plans[$plan])) {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        // Enterprise is a contact-sales tier: it has a null price, so it must
        // never reach the "amount <= 0 → activate free" path below (that would
        // hand out the top plan for free). Self-serve checkout is Free/Basic/Pro
        // only; Enterprise is provisioned by the team after a sales conversation.
        if (! empty($plans[$plan]['contact_sales']) || ($plans[$plan]['price'] ?? null) === null) {
            return response()->json([
                'message' => 'The Enterprise plan is arranged with our team. Please contact sales to get started.',
                'contact_sales' => true,
            ], 422);
        }

        $amount = (float) ($plans[$plan]['price'] ?? 0);
        if ($amount <= 0) {
            // The free plan needs no payment, activate directly.
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
        // Return to the SAME origin the owner is on (sent by the billing page) so
        // their owner token (localStorage, per-origin) and tenant cookie survive
        // the Paystack round-trip. Returning to a different host, the primary
        // domain, or 127.0.0.1 when they're on localhost, drops both and bounces
        // them to a bare "jorsas" login. Carry ?tenant={slug} so the verify page
        // re-pins THIS academy even if the cookie was lost. Falls back to the
        // configured URL (config(), not env(), so it survives config:cache) when
        // no valid origin is supplied.
        $origin = $this->safeReturnOrigin($request->input('return_origin'))
            ?? config('saas.frontend_url');
        $callbackUrl = $origin
            . '/lms/admin/billing/verify?reference=' . $reference
            . '&tenant=' . urlencode((string) $tenant->slug);

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

            // Pending row in the platform revenue ledger; confirmed on verify/webhook.
            try {
                \App\Models\PlatformTransaction::recordPending($tenant, $reference, $amount, 'plan_upgrade', $plan);
            } catch (\Throwable $e) {
                Log::warning('Could not record pending platform transaction (upgrade)', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);
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
     * repeatedly, activation is idempotent and the plan is read from the verified
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

        // Confirmed upgrade, record it in the platform revenue ledger (idempotent
        // on the reference, so a racing webhook won't double-count). Best-effort.
        try {
            \App\Models\PlatformTransaction::markSuccess($tenant, $validated['reference'], 'plan_upgrade', $plan, $data);
        } catch (\Throwable $e) {
            Log::warning('Could not mark platform transaction success (upgrade verify)', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);
        }

        return response()->json([
            'status' => 'active',
            'plan' => $tenant->plan,
            'subscription_status' => $tenant->subscription_status,
            'current_period_end' => $tenant->current_period_end,
        ]);
    }

    /**
     * The upgradeable plan catalogue from config, shaped for the plan cards
     * (price, per-plan commission, the three limits (null = unlimited) and the
     * feature flags, so the page can render a full comparison from one call).
     * Delegates to the shared App\Support\PlanCatalogue, the same source the
     * public /api/plans endpoint serves, so the billing cards and the signup
     * cards are identical by construction.
     */
    protected function planCatalogue(): array
    {
        return \App\Support\PlanCatalogue::all();
    }
}
