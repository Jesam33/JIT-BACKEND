<?php

namespace App\Http\Middleware;

use Closure;

class RequireTenant
{
    /**
     * Fail-closed gate for tenant-scoped routes: aborts when no tenant is bound.
     * A no-op while fail-closed enforcement is disabled, so the app keeps serving
     * (via ResolveTenant's legacy fallback) until the cutover flag is flipped.
     */
    public function handle($request, Closure $next)
    {
        if (! config('saas.enforce_tenancy')) {
            return $next($request);
        }

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant || ! isset($tenant->id)) {
            abort(response()->json([
                'message' => 'Organisation could not be determined for this request.',
                'code' => 'TENANT_REQUIRED',
            ], 400));
        }

        return $next($request);
    }
}
