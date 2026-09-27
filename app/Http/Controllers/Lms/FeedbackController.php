<?php

namespace App\Http\Controllers\Lms;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Feedback;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Help us make the app better" — the feedback form every portal shows.
 *
 * ONE endpoint for all four portals. The author is resolved from whichever
 * bearer token the request carries (student, staff, owner or Admission Marketer
 * session), so the frontend needs no per-portal URL and there is no way for a
 * request body to claim a role it does not hold. The tenant likewise comes from
 * the resolved session and never from the body: nobody can file feedback against
 * an academy they have no relationship with.
 *
 * Deliberately NOT gated on the plan. Feedback is how the platform hears about
 * its own bugs, and charging for the privilege of reporting one — or letting a
 * lapsed subscription silence it — would be self-defeating.
 *
 * The row is a snapshot (see the Feedback model): the author's name and email are
 * copied onto it, because the account can be renamed or purged long before the
 * queue is worked through.
 */
class FeedbackController extends BaseLmsController
{
    /** File a piece of feedback from whichever portal the sender is signed in to. */
    public function submit(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(Feedback::CATEGORIES))],
            // Short enough that "it's broken" is not accepted as a report, long
            // enough for someone to describe a real problem in their own words.
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            // The portal path the sender was on. Attacker-controlled free text,
            // so length-capped here and escaped wherever it is rendered; it is
            // never used in a query or a redirect.
            'page' => ['nullable', 'string', 'max:191'],
        ]);

        $author = $this->resolveAuthor($request);

        if (! $author) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $author['tenant_id']) {
            return response()->json([
                'message' => 'We could not tell which academy this is about. Please sign in again from your academy and retry.',
            ], 422);
        }

        $feedback = Feedback::createForTenant($author['tenant_id'], [
            'author_role' => $author['role'],
            'author_id' => $author['id'],
            'author_name' => $author['name'],
            'author_email' => $author['email'],
            'page' => $validated['page'] ?? null,
            'category' => $validated['category'],
            'message' => $validated['message'],
            'status' => 'new',
        ]);

        return response()->json([
            'message' => 'Thank you. Your feedback has been sent to the Jorsas Tech team.',
            'feedback' => [
                'id' => $feedback->id,
                'category' => $feedback->category,
                'status' => $feedback->status,
                'created_at' => $feedback->created_at,
            ],
        ], 201);
    }

    /**
     * Who is sending this, and for which academy.
     *
     * Checked in a fixed order and the FIRST match wins. A token can only ever
     * match one of these, because each lives in its own session table with its
     * own role column — an owner token simply finds no staff session. Reusing the
     * portal session resolvers rather than trusting a body field is the whole
     * point: this is the same authentication every other endpoint on the portal
     * uses, so feedback cannot be filed as anyone else.
     *
     * @return array{role: string, id: int, name: ?string, email: ?string, tenant_id: ?int}|null
     */
    private function resolveAuthor(Request $request): ?array
    {
        if ($session = $this->sessionFromRequest($request, 'student')) {
            $student = LmsStudent::query()->find($session->user_id);

            return $student ? [
                'role' => 'student',
                'id' => (int) $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}") ?: null,
                'email' => $student->email,
                'tenant_id' => $this->authorTenantId($session->tenant_id, $student->tenant_id),
            ] : null;
        }

        if ($session = $this->sessionFromRequest($request, 'staff')) {
            $teacher = LmsTeacher::query()->find($session->user_id);

            return $teacher ? [
                'role' => 'staff',
                'id' => (int) $teacher->id,
                'name' => $teacher->name ?: null,
                'email' => $teacher->email,
                'tenant_id' => $this->authorTenantId($session->tenant_id, $teacher->tenant_id),
            ] : null;
        }

        if ($session = $this->sessionFromRequest($request, 'owner')) {
            $user = User::query()->find($session->user_id);

            return $user ? [
                'role' => 'owner',
                'id' => (int) $user->id,
                'name' => $user->name ?: null,
                'email' => $user->email,
                // The owner IS the academy side of this, so the session's tenant
                // is the only answer; there is no row to fall back to.
                'tenant_id' => $session->tenant_id ? (int) $session->tenant_id : null,
            ] : null;
        }

        if ($session = $this->agentSession($request)) {
            $agent = Agent::withoutGlobalScope(TenantScope::class)->find($session->agent_id);

            // Only an approved marketer has a live portal to send feedback from.
            if (! $agent || $agent->status !== 'approved') {
                return null;
            }

            return [
                'role' => 'agent',
                'id' => (int) $agent->id,
                'name' => $agent->name ?: null,
                'email' => $agent->email,
                'tenant_id' => $this->authorTenantId($session->tenant_id, $agent->tenant_id),
            ];
        }

        return null;
    }

    /**
     * The agent's session row, found by bearer token.
     *
     * Not tenant-scoped, and the tenant is derived FROM the session: a
     * scoped lookup would let a spoofable header decide which academy's sessions
     * are searched. Mirrors AgentController::agentOrFail().
     */
    private function agentSession(Request $request): ?AgentSession
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return AgentSession::withoutGlobalScope(TenantScope::class)
            ->where('token', $token)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * The academy this feedback is about: the session's tenant, falling back to
     * the account's own tenant_id for a legacy session row that predates the
     * tenancy backfill.
     */
    private function authorTenantId(?int $sessionTenantId, ?int $accountTenantId): ?int
    {
        $tenantId = $sessionTenantId ?: $accountTenantId;

        return $tenantId ? (int) $tenantId : null;
    }
}
