<?php

namespace App\Http\Middleware;

use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Scopes\TenantScope;
use Closure;

/**
 * Refuse a request from a deactivated account, or against a closed academy.
 *
 * This is the gate that makes "deactivate" mean something. Login checks alone are
 * not enough: someone deactivated mid-session still holds a valid bearer token and
 * would keep using the portal until it expired, up to seven days. Every route in
 * the `lms-api` group passes through here.
 *
 * What it deliberately does NOT do is block learners when their academy is merely
 * paused. "Keep teaching, stop selling" is the agreed rule: an owner deactivating
 * their academy takes the storefront and new enrolments offline, while students
 * already mid-course carry on working. So a `deactivated` tenant passes straight
 * through and only `purge_scheduled` / `purged` stop traffic, because by then the
 * academy is on its way out and only the platform can reverse it.
 *
 * Runs directly after ResolveTenant / ResolveTenantFromSession and reads what they
 * bound, so it costs no queries of its own. It also keys the academy check on the
 * bound tenant rather than on the session, which is what makes it cover an agent's
 * session too (a different token table the person-level check below knows nothing
 * about) and any request that names a closed academy without a token at all.
 */
class EnsureAccountActive
{
    /**
     * The only routes a frozen account may still reach.
     *
     * Without these the block would be a one-way door: reactivating and
     * cancelling a deletion both require being signed in, and this middleware is
     * what signs a frozen account out of everything. Exempting exactly the two
     * endpoints that undo the freeze keeps the person on one screen able to
     * reverse their own decision, and nothing else.
     *
     * Matched on the request path, because group middleware runs before route
     * middleware and so cannot be lifted per-route. The academy check below is
     * NOT exempt: a closed academy blocks these too, since only the platform can
     * reopen one.
     */
    private const SELF_RECOVERY_PATHS = [
        'api/frontend/lms/account/reactivate',
        'api/frontend/lms/account/cancel-deletion',
        'api/frontend/lms/staff/account/reactivate',
        'api/frontend/lms/staff/account/cancel-deletion',
    ];

    public function handle($request, Closure $next)
    {
        // A purged or purge-scheduled academy is closed to everyone, owner and
        // agent included: only the platform can reverse it, so letting anyone in
        // would offer buttons that cannot work.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($tenant && in_array($tenant->lifecycleState(), ['purge_scheduled', 'purged'], true)) {
            return $this->refuse([
                'academy_unavailable' => true,
                'lifecycle' => $tenant->lifecycleState(),
                'message' => $tenant->isAcademyPurged()
                    ? 'This academy has been closed.'
                    : 'This academy is scheduled for deletion and is no longer available.',
            ]);
        }

        $session = app()->bound('lmsResolvedSession') ? app('lmsResolvedSession') : null;

        if (! $session instanceof LmsSession) {
            // No token, or an agent token: nothing account-level to check. A login
            // attempt lands here too, and its own controller decides.
            return $next($request);
        }

        // The binding is only trustworthy if it belongs to THIS request's token.
        // ResolveTenantFromSession clears it between requests, and this is the
        // backstop: refusing a request on the strength of a session that arrived
        // with a different token would be a bug in both directions.
        if (! hash_equals((string) $session->token, (string) $this->bearerToken($request))) {
            return $next($request);
        }

        if (in_array($request->path(), self::SELF_RECOVERY_PATHS, true)) {
            return $next($request);
        }

        $denied = $this->deniedPayload($session);

        return $denied ? $this->refuse($denied) : $next($request);
    }

    /**
     * Why this session's own account may not be used, or null when it is fine.
     *
     * Owners are deliberately absent: an owner has no separate account state, so
     * deactivating an academy IS deactivating the owner's access to it, and the
     * tenant check above already covers that case.
     */
    private function deniedPayload(LmsSession $session): ?array
    {
        $account = match ($session->role) {
            'student' => LmsStudent::query()->withoutGlobalScope(TenantScope::class)->find($session->user_id),
            'staff', 'teacher' => LmsTeacher::query()->withoutGlobalScope(TenantScope::class)->find($session->user_id),
            default => null,
        };

        if (! $account) {
            // The row is gone entirely. Let the controller answer "Unauthorized"
            // exactly as it did before rather than invent a new error here.
            return null;
        }

        return match ($account->lifecycleState()) {
            'purged' => [
                'account_deleted' => true,
                'message' => 'This account has been deleted.',
            ],
            'purge_scheduled' => [
                'account_deletion_scheduled' => true,
                'purge_after' => $account->purge_after,
                'message' => 'This account is scheduled for deletion. Contact your institute admin to cancel it.',
            ],
            'deactivated' => [
                'account_deactivated' => true,
                'message' => $session->role === 'student'
                    ? 'Your account has been deactivated. Please contact your institute admin to restore access.'
                    : 'Your staff account has been deactivated. Please contact your institute admin.',
            ],
            default => null,
        };
    }

    private function refuse(array $payload)
    {
        return response()->json($payload, 403);
    }

    private function bearerToken($request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }
}
