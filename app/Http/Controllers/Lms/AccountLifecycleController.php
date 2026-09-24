<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AccountLifecycleMail;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use App\Support\NotificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * A student or staffer managing their own account: pause it, come back, ask to be
 * deleted, change their mind.
 *
 * One controller for both roles because the two differ only in which model the
 * session points at and which profile page the email links to. The verb semantics
 * live on the model (see HasAccountLifecycle) so this stays a thin, honest
 * translation of HTTP into those calls.
 *
 * Two things worth knowing about the shape of this flow:
 *
 *  1. Deactivating and deleting do NOT destroy the caller's session. They would
 *     otherwise be locked out of the very endpoints that undo it, because the
 *     account gate refuses a frozen account everywhere else. The session survives
 *     and the gate refuses everything except reactivate / cancel-deletion, so the
 *     person stays on one screen and can reverse their own decision.
 *  2. Nothing is deleted here. "Delete" arms a 30-day window; the purge is a
 *     scheduled command. See `lms:purge-scheduled-accounts`.
 */
class AccountLifecycleController extends BaseLmsController
{
    /** The current lifecycle state, for the profile page's controls. */
    public function show(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->statePayload($account, $role));
    }

    /** Pause access. Reversible instantly, and touches no record. */
    public function deactivate(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $account->deactivate();

        $this->notifyAccountLifecycle($account->fresh(), $role, 'deactivated');

        return response()->json($this->statePayload($account->fresh(), $role) + [
            'message' => 'Your account has been deactivated. You can reactivate it at any time.',
        ]);
    }

    /** Undo a deactivation, or a scheduled deletion. */
    public function reactivate(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Which of the two we are undoing decides the wording, so read the state
        // before overwriting it.
        $wasScheduled = $account->isPurgeScheduled();

        $account->reactivate();

        $this->notifyAccountLifecycle($account->fresh(), $role, $wasScheduled ? 'deletion_cancelled' : 'reactivated');

        return response()->json($this->statePayload($account->fresh(), $role) + [
            'message' => $wasScheduled
                ? 'Your scheduled deletion has been cancelled and your account is active again.'
                : 'Your account has been reactivated.',
        ]);
    }

    /**
     * Ask to be deleted. This only arms the window: every record stays intact and
     * the whole thing is cancellable from this same screen until the purge runs.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A typed confirmation, because this is the one action a person can take
        // that ends in their own records being erased. The UI already asks; this
        // is the server refusing to act on a stray or replayed request.
        $request->validate(['confirm' => ['required', 'string', 'in:DELETE']]);

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($account->isPurged()) {
            // Already gone. Nothing to schedule, and re-arming a date on a purged
            // row would make it look cancellable when it is not.
            return response()->json(['message' => 'This account has already been deleted.'], 409);
        }

        // Some accounts must not be erased at all, whatever the holder asks — for
        // a student, having paid for a course means the academy's financial record
        // of that transaction has to survive. The profile does not offer the
        // button in that case (see statePayload); this is the server refusing to
        // act on a replayed or hand-made request. The erasure route that DOES
        // exist for them is a rights request to Jorsas, which is why the message
        // points there rather than just saying no.
        if ($reason = $account->purgeBlockedReason()) {
            return response()->json([
                'message' => $reason,
                'purge_blocked' => true,
                'can_request_erasure' => true,
            ], 422);
        }

        $account->schedulePurge();

        $this->notifyAccountLifecycle($account->fresh(), $role, 'deletion_scheduled');

        return response()->json($this->statePayload($account->fresh(), $role) + [
            'message' => 'Your account is scheduled for deletion. You can cancel this any time before the date shown.',
        ]);
    }

    /** Change your mind about a scheduled deletion. */
    public function cancelDeletion(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $account->isPurgeScheduled()) {
            return response()->json(['message' => 'This account is not scheduled for deletion.'], 409);
        }

        $account->cancelPurge();

        $this->notifyAccountLifecycle($account->fresh(), $role, 'deletion_cancelled');

        return response()->json($this->statePayload($account->fresh(), $role) + [
            'message' => $account->canAccessAccount()
                ? 'Your scheduled deletion has been cancelled and your account is active again.'
                : 'Your scheduled deletion has been cancelled. Your account is still deactivated.',
        ]);
    }

    /**
     * Who is acting, resolved from the bearer session.
     *
     * Students are checked first only because the two roles can never both match
     * one token. An academy OWNER is deliberately excluded: an owner has no
     * separate account state, so their equivalent actions are the academy's, and
     * they live on the owner endpoints.
     *
     * @return array{0: LmsStudent|LmsTeacher|null, 1: string}
     */
    private function actor(Request $request): array
    {
        if ($session = $this->sessionFromRequest($request, 'student')) {
            return [LmsStudent::query()->find($session->user_id), 'student'];
        }

        if ($session = $this->sessionFromRequest($request, 'staff')) {
            return [LmsTeacher::query()->find($session->user_id), 'staff'];
        }

        return [null, ''];
    }

    /** The lifecycle block the profile page renders its controls from. */
    private function statePayload($account, string $role): array
    {
        return [
            'lifecycle' => [
                'state' => $account->lifecycleState(),
                'active' => $account->is_active,
                'deactivated' => $account->isDeactivated(),
                'deletion_scheduled' => $account->isPurgeScheduled(),
                'purged' => $account->isPurged(),
                'deactivated_at' => $account->deactivated_at,
                'purge_after' => $account->purge_after,
                'purge_window_days' => $account::purgeWindowDays(),
                // Whether the Delete control should be offered at all, and why
                // not when it shouldn't. Sent so the profile swaps the button for
                // the explanation instead of offering an action the server will
                // refuse — the reason is written for the account holder to read.
                'can_delete' => $account->canBePurged(),
                'delete_blocked_reason' => $account->purgeBlockedReason(),
            ],
            'role' => $role,
        ];
    }
}
