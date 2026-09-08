<?php

namespace App\Http\Middleware;

use Closure;

class EnsureSubscriptionActive
{
    /**
     * Freeze the OWNER portal when a paid-plan academy is past due.
     *
     * Scoped deliberately narrow: it only ever blocks the owner admin API
     * (/api/frontend/lms/owner/*). Billing (/billing/*) and onboarding
     * (/onboarding/*) stay reachable so a lapsed owner can always renew and
     * pay their way back in, and student/staff routes are never touched so
     * learners keep learning while the owner sorts out payment.
     *
     * The freeze decision itself (eligibility, grace window, the master
     * enforcement flag that keeps this dark until switched on) lives entirely
     * on Tenant::isSubscriptionFrozen(); this middleware only maps it to a 402.
     * Fail-open: if no tenant is bound it does nothing.
     */
    public function handle($request, Closure $next)
    {
        if (! $request->is('api/frontend/lms/owner/*')) {
            return $next($request);
        }

        $tenant = (app()->bound('currentTenant') && app('currentTenant'))
            ? app('currentTenant')
            : null;

        if ($tenant && $tenant->isSubscriptionFrozen()) {
            return response()->json([
                'frozen' => true,
                'message' => 'Your subscription is past due. Please contact management services to restore access to your dashboard.',
                'contact' => config('saas.support_email'),
                'subscription' => $tenant->subscriptionInfo(),
            ], 402);
        }

        return $next($request);
    }
}
