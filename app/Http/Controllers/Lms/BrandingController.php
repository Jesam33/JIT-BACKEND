<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsPasswordReset;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal-agnostic white-label branding read. Any authenticated portal
 * (student / staff / owner) fetches this to theme its shell to match the
 * institute's customization. The tenant is whatever the request resolved to
 * (bearer session, subdomain, or header); we fall back to the primary tenant
 * so single-tenant and local-dev setups still get a valid palette.
 */
class BrandingController extends BaseLmsController
{
    public function show(): JsonResponse
    {
        $tenant = $this->currentTenantOrPrimary();

        $branding = $tenant instanceof Tenant
            ? $tenant->brandingArray()
            : Tenant::defaultBranding();

        return response()->json(['branding' => $branding]);
    }

    /**
     * Branding read for UNAUTHENTICATED institute pages (student/staff login,
     * password setup + reset). There is no portal session yet, so the tenant is
     * resolved, in order, from:
     *   1. an invite/setup token in the query — the TrainingRegistration it
     *      belongs to is stamped with the institute's tenant_id, so this is
     *      authoritative even on the bare apex domain (no subdomain, no cookie),
     *   2. the tenant ResolveTenant already bound (subdomain / ?tenant / header),
     *   3. else the primary tenant's palette (the current default theme).
     * Only cosmetic fields are returned and there is no session here by design;
     * brandingStyle()/fontStackFor() on the client sanitise every value.
     */
    public function publicShow(Request $request): JsonResponse
    {
        $tenant = $this->resolvePublicBrandingTenant($request);

        $branding = $tenant instanceof Tenant
            ? $tenant->brandingArray()
            : Tenant::defaultBranding();

        return response()->json(['branding' => $branding]);
    }

    private function resolvePublicBrandingTenant(Request $request): ?Tenant
    {
        $token = trim((string) $request->query('token', ''));

        if ($token !== '') {
            // 1. Student invite/setup token — stamped on its TrainingRegistration.
            $registration = TrainingRegistration::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('invite_token', $token)
                ->first();

            if ($registration && $registration->tenant_id) {
                $tenant = Tenant::find($registration->tenant_id);
                if ($tenant) {
                    return $tenant;
                }
            }

            // 2. Staff/agent reset + owner-issued staff SETUP token. These are NOT
            // training registrations — they live in lms_password_resets, which
            // carries the institute's tenant_id. The token is stored as a sha256
            // hash, so match on the hash of the query token. Decorative read: no
            // used/expiry filter, so branding still resolves if the recipient
            // revisits the link after activating (this is what made the staff
            // set-up-account page render the default theme instead of the
            // institute's colors/font).
            $reset = LmsPasswordReset::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNotNull('tenant_id')
                ->first();

            if ($reset && $reset->tenant_id) {
                $tenant = Tenant::find($reset->tenant_id);
                if ($tenant) {
                    return $tenant;
                }
            }
        }

        return $this->currentTenantOrPrimary();
    }
}
