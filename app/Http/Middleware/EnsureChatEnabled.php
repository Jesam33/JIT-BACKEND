<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates chat/messaging behind a paid plan. Chat is a paid-plan feature, so an
 * institute on the free plan cannot use messaging. The tenant is already bound
 * by ResolveTenantFromSession (from the bearer token) by the time this runs, so
 * we read the plan straight off the bound tenant. Fail-closed: an unbound tenant
 * or a missing plan reads as 'free' and is blocked.
 *
 * Applied only to the messaging endpoints — NOT the unread-count endpoints,
 * which also carry notification counts the free portals still need.
 */
class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $plan = $tenant->plan ?? 'free';

        if ($plan === 'free') {
            return response()->json([
                'message' => 'Chat is a paid-plan feature. Upgrade your plan to enable messaging.',
                'feature' => 'chat',
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
