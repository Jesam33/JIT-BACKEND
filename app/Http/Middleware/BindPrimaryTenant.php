<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindPrimaryTenant
{
    /**
     * Bind the primary (JIT) organisation for back-office routes that live
     * outside the tenant-resolving lms-api group, chiefly the Botble admin
     * Blade panel. Its TenantAware reads/writes would otherwise fail closed
     * under enforcement (no tenant header, no LmsSession bearer token). JIT is
     * the sole organisation today, so binding primary is correct; platform-
     * admin-vs-org-owner separation is a later phase. No-op when a tenant is
     * already bound, so it never clobbers a legitimately resolved tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentTenant') || ! app('currentTenant')) {
            $primary = Tenant::primary();
            if ($primary) {
                app()->instance('currentTenant', $primary);
            }
        }

        return $next($request);
    }
}
