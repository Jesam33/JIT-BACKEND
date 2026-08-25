<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;

class ResolveTenant
{
    /**
     * Resolve the tenant from an explicit request signal (subdomain, header, or
     * ?org=) and bind it. While fail-closed enforcement is OFF, an unresolved
     * request falls back to the legacy 'default'/primary tenant so code can be
     * deployed before the migration/backfill run. This middleware never aborts —
     * RequireTenant is the gate.
     */
    public function handle($request, Closure $next)
    {
        $requestedSlug = $this->resolveRequestedSlug($request);

        $tenant = null;
        if ($requestedSlug) {
            $tenant = Tenant::where('slug', $requestedSlug)->first();
            if ($tenant) {
                // Only record a slug that resolved to a real tenant, so noise like
                // 'www'/'app' subdomains never triggers the session anti-spoof check.
                app()->instance('requestedTenantSlug', $tenant->slug);
            }
        }

        // Legacy fallback — only while fail-closed enforcement is disabled.
        if (! $tenant && ! config('saas.enforce_tenancy')) {
            $tenant = Tenant::where('slug', 'default')->first()
                ?? Tenant::where('slug', config('saas.primary_slug', 'default'))->first();
        }

        if ($tenant) {
            app()->instance('currentTenant', $tenant);
        } else {
            // Never leave a stale tenant bound when this request resolved none.
            app()->forgetInstance('currentTenant');
        }

        return $next($request);
    }

    /**
     * The explicitly-requested tenant slug, in priority order: subdomain, then
     * any configured tenant header, then the ?org= query param.
     */
    private function resolveRequestedSlug($request): ?string
    {
        // Resolve by subdomain: {tenant}.example.com or tenant.localhost for local dev.
        $host = $request->getHost();
        $subdomain = null;
        $baseDomain = env('APP_DOMAIN');

        if ($baseDomain && str_ends_with($host, $baseDomain)) {
            $candidate = preg_replace('/\.' . preg_quote($baseDomain, '/') . '$/', '', $host);
            if ($candidate !== $baseDomain) {
                $subdomain = explode('.', $candidate)[0] ?? null;
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
            // A bare IP host (127.0.0.1, ::1, a LAN address) has no subdomain —
            // never mistake its first octet ("127") for a tenant slug, which would
            // short-circuit the header/?org resolution below.
            $subdomain = null;
        } else {
            // localhost pattern: tenant.localhost or tenant.test
            $parts = explode('.', $host);
            if (count($parts) >= 3) {
                $subdomain = $parts[0];
            } elseif (count($parts) === 2 && str_ends_with($host, 'localhost')) {
                $subdomain = $parts[0];
            }
        }

        if ($subdomain) {
            // Never treat an infra/reserved subdomain (www, api, …) as a tenant.
            $reserved = (array) config('saas.reserved_slugs', []);
            if (! in_array(strtolower($subdomain), $reserved, true)) {
                return $subdomain;
            }
        }

        foreach ((array) config('saas.tenant_headers', ['X-Tenant']) as $headerName) {
            $value = $request->header($headerName);
            if ($value) {
                return $value;
            }
        }

        return $request->query('org') ?: null;
    }
}
