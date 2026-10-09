<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CoursePayments;
use App\Services\PaystackService;
use App\Services\PlatformBilling;
use App\Services\TenantOnboardingService;
use App\Support\PayoutCountries;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantSignupController extends Controller
{
    /**
     * Institute signup. A FREE plan provisions immediately (no payment); a paid
     * plan is pay-first, this endpoint only:
     *   1. creates a `pending` tenant + owner user (no LMS content, no invite),
     *   2. initializes a Paystack transaction, and
     *   3. returns the authorization_url for the browser to redirect to.
     * Paid provisioning happens later, in verify() or the webhook, once money
     * confirms. Free signups are activated + provisioned inline here, reusing the
     * same idempotent {@see TenantOnboardingService::activatePaidSignup()}.
     */
    public function signup(Request $request, PaystackService $paystack, TenantOnboardingService $onboarding)
    {
        // Self-serve plans only (free/basic/pro). Enterprise is contact-sales:
        // its price is null and `contact_sales` is set, so it must NEVER be
        // provisioned through signup, otherwise the null price would read as
        // "free" ((float) null <= 0) and hand out an unlimited academy at no
        // charge. Enterprise goes through the contact path instead.
        $selfServePlans = array_keys(array_filter(
            (array) config('saas.plans', []),
            fn ($p) => empty($p['contact_sales'] ?? false) && ($p['price'] ?? null) !== null
        ));

        // Normalise a supplied slug to the DNS-safe lowercase form before validating.
        if ($request->filled('slug')) {
            $request->merge(['slug' => Str::lower(trim((string) $request->input('slug')))]);
        }

        // Validate FIRST (so bad input 422s regardless of gateway state), then
        // check gateway availability, then touch the database.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::notIn(config('saas.reserved_slugs', []))],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            // No password at signup, the owner sets it later on the emailed setup
            // link (/lms/admin/setup), so registration only collects who they are.
            'plan' => ['required', 'string', Rule::in($selfServePlans)],
            // The academy's teaching niche, used by the public Campuses filter.
            // Free text is allowed so an owner who picks "Other" can type their own;
            // the dropdown of general niches lives on the frontend (src/lib/niches.ts),
            // so we never constrain the stored value to a fixed list here.
            'niche' => ['required', 'string', 'max:80'],
            // Where the academy is based: fixes the currency it sells in and pays
            // its plan in (App\Support\PayoutCountries). Optional for older
            // clients; they default to Nigeria.
            'country' => ['nullable', 'string', 'size:2'],
        ], [
            'slug.regex' => 'The subdomain may only contain lowercase letters, numbers, and hyphens.',
            'slug.not_in' => 'That subdomain is reserved. Please choose another.',
            'plan.required' => 'Please choose a plan.',
            'plan.in' => 'Please choose one of the available plans.',
            'niche.required' => 'Please choose what your academy teaches.',
        ]);

        $plan = $validated['plan'];
        $email = $validated['admin_email'];
        $niche = trim($validated['niche']);

        $country = strtoupper((string) ($validated['country'] ?? 'NG'));
        if (! PayoutCountries::supports($country)
            || (CoursePayments::linkProvider() === CoursePayments::PAYSTACK && $country !== 'NG')) {
            throw ValidationException::withMessages([
                'country' => 'We can’t take academies from that country yet.',
            ]);
        }
        $currency = (string) PayoutCountries::currencyFor($country);

        // A free plan (price ≤ 0) provisions without payment; only paid signups
        // need a live gateway. Fail loud for a paid plan when no gateway is set
        // (but only after validation) so we never create a pending tenant that can
        // never be paid for. A free plan is unaffected.
        $isFree = (float) config("saas.plans.$plan.price", 0) <= 0;
        if (! $isFree && ! PlatformBilling::gateway()) {
            return response()->json([
                'message' => 'Online signup is temporarily unavailable. Please contact us to get started.',
            ], 503);
        }

        // Existing email: three cases, decided by what this user actually owns.
        //   1. Owns a still-PENDING institute  → resume that abandoned signup.
        //   2. Host super admin, or owns a LIVE (active/suspended) institute
        //      → genuinely registered; tell them to sign in.
        //   3. Neither → an ORPHANED owner row: the `users` row survives but every
        //      institute it owned is gone (the classic case: a database reset wipes
        //      the tenant-scoped tables, tenant_admins, tenants, but NOT the core
        //      `users` table, which has no tenant_id). Reuse the row for a fresh
        //      signup instead of locking the person out with "already registered".
        // This replaces the plain `unique:users,email` rule.
        $reuseUserId = null;
        $pendingTenantId = null;
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            $ownerLinks = DB::table('tenant_admins')
                ->join('tenants', 'tenants.id', '=', 'tenant_admins.tenant_id')
                ->where('tenant_admins.user_id', $existingUser->id)
                ->where('tenant_admins.role', 'owner')
                ->get(['tenants.id', 'tenants.status']);

            $pendingTenantId = optional($ownerLinks->firstWhere('status', 'pending'))->id;
            $ownsLiveTenant = $ownerLinks->contains(fn ($l) => $l->status !== 'pending');
            // super_user is the Botble host admin flag, never let signup adopt or
            // clobber that account, even if it somehow owns no tenant.
            $isSuperAdmin = (bool) ($existingUser->super_user ?? false);

            if (! $pendingTenantId && ($isSuperAdmin || $ownsLiveTenant)) {
                throw ValidationException::withMessages([
                    'admin_email' => 'This email is already registered. Please sign in instead.',
                ]);
            }

            if (! $pendingTenantId) {
                // Case 3, orphan. Fall through to the fresh-signup transaction
                // below, reusing this user row rather than creating a duplicate.
                $reuseUserId = $existingUser->id;
            }
        }

        if ($pendingTenantId) {
            // Resume: reuse the pending tenant, refresh the chosen plan, re-init.
            $tenant = Tenant::find($pendingTenantId);
            $settings = $tenant->settings ?? [];
            $settings['plan'] = $plan;
            // Persist the chosen niche without disturbing any other profile keys.
            $profile = (array) ($settings['profile'] ?? []);
            $profile['niche'] = $niche;
            $settings['profile'] = $profile;
            // Nothing has been paid yet, so the country can still change.
            $settings['country'] = $country;
            $settings['currency'] = $currency;
            $tenant->update(['name' => $validated['name'], 'settings' => $settings]);

            // Switching an abandoned (still-pending) signup to the free plan:
            // provision it now instead of re-opening a payment.
            if ($isFree) {
                return $this->activateFreeSignup($onboarding, $tenant, true);
            }

            $init = $this->initPayment($paystack, $tenant, $existingUser, $plan);
            if (! ($init['ok'] ?? false)) {
                return response()->json(['message' => $init['message']], 502);
            }

            return response()->json([
                'tenant' => $tenant->only(['id', 'name', 'slug', 'status']),
                'authorization_url' => $init['authorization_url'],
                'reference' => $init['reference'],
                'resumed' => true,
            ], 201);
        }

        // Fresh signup, create the pending tenant, owner user and admin link in
        // a transaction. Nothing is provisioned here.
        $tenant = null;
        $user = null;

        DB::beginTransaction();
        try {
            $slug = $validated['slug'] ?? Str::slug($validated['name']);
            $reserved = (array) config('saas.reserved_slugs', []);
            $baseSlug = $slug ?: 'institute';
            $slug = $baseSlug;
            $i = 1;
            while (Tenant::query()->where('slug', $slug)->exists() || in_array($slug, $reserved, true)) {
                $slug = $baseSlug . '-' . $i++;
            }

            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'status' => 'pending', // nothing provisioned until payment confirms
                'settings' => ['plan' => $plan, 'profile' => ['niche' => $niche], 'country' => $country, 'currency' => $currency],
            ]);

            [$first, $last] = array_pad(explode(' ', $validated['admin_name'], 2), 2, '');
            // Unusable placeholder, the owner sets a real password via the emailed
            // setup link (OwnerAuthController::setup). Random so it can never be
            // signed into until then, and it satisfies NOT NULL.
            $placeholderPassword = Hash::make(Str::random(40));

            if ($reuseUserId) {
                // Case 3, adopt the orphaned owner row (its institutes are gone)
                // rather than creating a duplicate. Refresh the name and reset the
                // password to an unusable placeholder so the setup link is again the
                // only way in. username left as-is (it's already this email).
                $user = User::find($reuseUserId);
                $user->update([
                    'first_name' => $first,
                    'last_name' => $last,
                    'password' => $placeholderPassword,
                ]);
            } else {
                $user = User::create([
                    'first_name' => $first,
                    'last_name' => $last,
                    'username' => $email,
                    'email' => $email,
                    'password' => $placeholderPassword,
                ]);
            }

            DB::table('tenant_admins')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Free plan → provision immediately, no payment. Same idempotent
        // activation the paid verify path uses, so the owner gets the same seeded
        // LMS and setup-link email.
        if ($isFree) {
            return $this->activateFreeSignup($onboarding, $tenant, false);
        }

        $init = $this->initPayment($paystack, $tenant, $user, $plan);
        if (! ($init['ok'] ?? false)) {
            // Payment couldn't be started. The pending tenant/user remain and can
            // be resumed on a later retry with the same email.
            return response()->json([
                'message' => $init['message'] ?? 'Could not start payment. Please try again.',
            ], 502);
        }

        return response()->json([
            'tenant' => $tenant->only(['id', 'name', 'slug', 'status']),
            'admin' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
            'authorization_url' => $init['authorization_url'],
            'reference' => $init['reference'],
        ], 201);
    }

    /**
     * Provision a FREE-plan signup immediately, no payment. Mirrors verify()'s
     * success path: activation + provisioning is idempotent and self-healing
     * (activatePaidSignup reverts the tenant to `pending` and rethrows on failure),
     * so we surface a 503 "finalizing" the frontend can retry rather than a raw 500.
     * The response carries `free: true` so the client shows an inline "check your
     * email" success instead of redirecting to a payment page.
     */
    private function activateFreeSignup(TenantOnboardingService $onboarding, Tenant $tenant, bool $resumed)
    {
        try {
            $onboarding->activatePaidSignup($tenant);
        } catch (\Throwable $e) {
            Log::error('Free-signup activation failed', [
                'tenant_id' => $tenant->id,
                'err' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'pending',
                'message' => 'We are finalizing your Online Academy. This can take a moment; please try again.',
            ], 503);
        }

        $tenant->refresh();

        return response()->json([
            'status' => 'success',
            'free' => true,
            'resumed' => $resumed,
            'message' => 'Your Online Academy is ready, check your email for your setup link.',
            'front_door' => $this->frontDoor($tenant),
            'tenant' => $tenant->only(['id', 'name', 'slug']),
        ], 201);
    }

    /**
     * Confirm a signup payment and, on success, activate + provision the tenant.
     * Public (no auth): the reference maps to exactly one pending tenant. Safe to
     * hit repeatedly and races the webhook, activation is idempotent.
     */
    public function verify(Request $request, TenantOnboardingService $onboarding, PaystackService $paystack)
    {
        $reference = trim((string) $request->query('reference', ''));
        if ($reference === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing payment reference.'], 422);
        }

        $tenant = Tenant::where('paystack_init_reference', $reference)->first();
        if (! $tenant) {
            return response()->json([
                'status' => 'error',
                'message' => 'We could not find that signup. Please try again.',
            ], 404);
        }

        // Already activated (e.g. the webhook returned before the browser did).
        // Idempotent success, do not re-verify or re-provision.
        if ($tenant->status === 'active') {
            return response()->json([
                'status' => 'success',
                'message' => 'Your Online Academy is ready. Check your email for your setup link.',
                'front_door' => $this->frontDoor($tenant),
                'tenant' => $tenant->only(['id', 'name', 'slug']),
            ]);
        }

        // Paid through Flutterwave: confirm there (amount + currency checked,
        // provisioning idempotent).
        $tx = \App\Models\PlatformTransaction::query()->where('reference', $reference)->first();
        if ($tx && $tx->gateway === CoursePayments::FLUTTERWAVE) {
            try {
                $status = PlatformBilling::confirm($tx);
            } catch (\Throwable $e) {
                Log::error('Paid-signup activation failed after confirmed payment', ['tenant_id' => $tenant->id, 'reference' => $reference, 'err' => $e->getMessage()]);

                return response()->json([
                    'status' => 'pending',
                    'message' => 'Payment received, we are finalizing your Online Academy. This can take a moment; please click Check again.',
                ], 503);
            }

            if ($status !== 'success') {
                return response()->json([
                    'status' => 'error',
                    'message' => $status === 'review'
                        ? 'We received a payment that needs checking. Our team will confirm it shortly.'
                        : 'Payment has not been confirmed yet. If you were charged, it will be confirmed shortly.',
                ], 402);
            }

            $tenant->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment confirmed. Your Online Academy is ready, check your email for your setup link.',
                'front_door' => $this->frontDoor($tenant),
                'tenant' => $tenant->only(['id', 'name', 'slug']),
            ]);
        }

        $result = $paystack->verifyTransaction($reference);
        $ok = (bool) data_get($result, 'status') && data_get($result, 'data.status') === 'success';
        if (! $ok) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment has not been confirmed yet. If you were charged, it will be confirmed shortly.',
            ], 402);
        }

        // Confirmed, record it in the platform revenue ledger (idempotent on the
        // reference, so a racing webhook won't double-count). Best-effort.
        try {
            \App\Models\PlatformTransaction::markSuccess(
                $tenant,
                $reference,
                'tenant_signup',
                data_get($tenant->settings, 'plan', $tenant->plan),
                (array) data_get($result, 'data', []),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not mark platform transaction success (signup verify)', ['tenant_id' => $tenant->id, 'err' => $e->getMessage()]);
        }

        // Payment is confirmed. Activation + provisioning is idempotent and
        // self-healing: on failure it reverts the tenant to `pending` and rethrows,
        // so a repeat verify() (or the webhook) retries cleanly. We surface a 503
        // "finalizing" rather than a raw 500, the money went through, so we must
        // never imply the payment failed, and the frontend can poll "Check again".
        try {
            $onboarding->activatePaidSignup($tenant);
        } catch (\Throwable $e) {
            Log::error('Paid-signup activation failed after confirmed payment', [
                'tenant_id' => $tenant->id,
                'reference' => $reference,
                'err' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'pending',
                'message' => 'Payment received, we are finalizing your Online Academy. This can take a moment; please click Check again.',
            ], 503);
        }
        $tenant->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Payment confirmed. Your Online Academy is ready, check your email for your setup link.',
            'front_door' => $this->frontDoor($tenant),
            'tenant' => $tenant->only(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Initialize a Paystack transaction for a signup and persist its reference.
     * Returns ['ok'=>true,'authorization_url'=>..,'reference'=>..] on success,
     * or ['ok'=>false,'message'=>..] so the caller can surface a clean error.
     */
    private function initPayment(PaystackService $paystack, Tenant $tenant, User $user, string $plan): array
    {
        $reference = 'ten_signup_' . Str::random(16);

        // Pre-fill the callback with this reference (the gateway returns to it
        // unchanged, so the frontend reads a correct ?reference=). Points at the
        // tenant-signup verify page, NOT the student /institute/verify flow.
        $callbackUrl = config('saas.frontend_url') . '/signup/verify?reference=' . $reference;

        // Flutterwave in the academy's own currency (Paystack/naira without
        // Flutterwave keys). Records the pending ledger row too.
        $init = PlatformBilling::start(
            $tenant,
            (string) $user->email,
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            $plan,
            PlatformBilling::SIGNUP,
            $reference,
            $callbackUrl
        );

        if (! $init['ok']) {
            Log::warning('Signup payment could not start', ['tenant_id' => $tenant->id, 'message' => $init['message'] ?? null]);

            return ['ok' => false, 'message' => $init['message'] ?? 'Could not start payment. Please try again.'];
        }

        // Persist the reference so verify()/the webhook can find this tenant later.
        $tenant->update(['paystack_init_reference' => $reference]);

        return ['ok' => true, 'authorization_url' => $init['authorization_url'], 'reference' => $reference];
    }

    /**
     * The tenant's public front door. With APP_DOMAIN this is a real subdomain;
     * otherwise (local dev / pre-wildcard-DNS) fall back to the apex + ?tenant=.
     */
    private function frontDoor(Tenant $tenant): string
    {
        // One source for this address, on the model, so the signup email and every
        // shareable referral link the agents hand out can never disagree. It reads
        // saas.app_domain through config, which is also what fixes this method's
        // old env('APP_DOMAIN') call: under config:cache that returned null, so
        // live signup emails quietly used the /i/{slug} form.
        //
        // Either way the address is the academy's OWN public page, never the bare
        // frontend homepage + ?tenant=, which is the marketing site with a pinned
        // cookie rather than something worth sharing.
        return $tenant->storefrontUrl();
    }
}
