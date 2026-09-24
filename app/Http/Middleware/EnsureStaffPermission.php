<?php

namespace App\Http\Middleware;

use App\Models\LmsSession;
use App\Models\LmsTeacher;
use App\Scopes\TenantScope;
use App\Support\StaffPermissions;
use Closure;

/**
 * Enforce a staff member's role preset on a staff-portal route.
 *
 * Applied per route group with the section it guards — `->middleware('staff.can:students')`
 * — so the section a route belongs to is stated at the route, next to the thing it
 * protects, rather than inferred from the URL. A prefix-guessing middleware would
 * silently fail open the first time someone added a route under a new path.
 *
 * Three deliberate pass-throughs:
 *
 *   - **No session, or an agent token.** There is no staff actor to judge, and the
 *     controller's own auth already answers "Unauthorized". Inventing a new error
 *     here would change the response shape of every unauthenticated call.
 *   - **An owner session.** Full parity is the existing contract: the owner portal
 *     gives an academy owner every staff feature, and `is_academy_owner` resolves to
 *     the `owner` role, which holds every section. Short-circuited so the common
 *     case costs no query.
 *   - **A missing teacher row.** The session is valid but its subject is gone; the
 *     controller answers that, not this gate.
 *
 * An UNKNOWN section name is refused rather than ignored. A typo in a route
 * definition — `staff.can:student` — would otherwise leave the route permanently
 * ungated with nothing to notice, which is the one failure mode a permission check
 * must not have. Refusing makes it loudly wrong on the first request instead.
 */
class EnsureStaffPermission
{
    public function handle($request, Closure $next, ?string $section = null)
    {
        if ($section === null || $section === '') {
            return $next($request);
        }

        if (! in_array($section, StaffPermissions::SECTIONS, true)) {
            report(new \InvalidArgumentException(
                "staff.can was given an unknown section: '{$section}'. Add it to StaffPermissions::SECTIONS or fix the route."
            ));

            return $this->refuse($section);
        }

        $session = app()->bound('lmsResolvedSession') ? app('lmsResolvedSession') : null;

        if (! $session instanceof LmsSession) {
            return $next($request);
        }

        // Only trustworthy if it belongs to THIS request's token — same reasoning
        // as EnsureAccountActive. The binding is cleared between requests; this is
        // the backstop.
        if (! hash_equals((string) $session->token, (string) $this->bearerToken($request))) {
            return $next($request);
        }

        if ($session->role === 'owner') {
            return $next($request);
        }

        if (! in_array($session->role, ['staff', 'teacher'], true)) {
            return $next($request);
        }

        $teacher = LmsTeacher::query()
            ->withoutGlobalScope(TenantScope::class)
            ->find($session->user_id);

        if (! $teacher) {
            return $next($request);
        }

        if ($teacher->allows($section)) {
            return $next($request);
        }

        return $this->refuse($section, $teacher->staffRole());
    }

    private function refuse(string $section, ?string $role = null)
    {
        return response()->json([
            'staff_permission_denied' => true,
            'section' => $section,
            'staff_role' => $role,
            'message' => 'Your role does not include access to this part of the portal. Ask your academy owner if you need it.',
        ], 403);
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
