<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates chat/messaging behind a plan that includes it. Chat is a paid-plan
 * feature, so an institute on the free plan cannot use messaging. The tenant is
 * already bound by ResolveTenantFromSession (from the bearer token) by the time
 * this runs, so we read the plan straight off the bound tenant. Fail-closed: an
 * unbound tenant reads as no-chat and is blocked.
 *
 * Reads the plan's `chat` feature flag (not a hardcoded slug) so the primary
 * institute, and any future plan tier that includes chat, resolves correctly
 * through the single source of truth in config/saas.php.
 *
 * Applied only to the messaging endpoints, NOT the unread-count endpoints,
 * which also carry notification counts the free portals still need.
 */
class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant || ! $tenant->planFeature('chat')) {
            return response()->json([
                'message' => 'Chat is a paid-plan feature. Upgrade your plan to enable messaging.',
                'feature' => 'chat',
                'upgrade_required' => true,
            ], 402);
        }

        return $next($request);
    }
}
