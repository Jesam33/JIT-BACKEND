<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AgentApplicationApprovedMail;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\LmsStudent;
use App\Models\TrainingRegistration;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * The owner's view of their academy's Admission Marketers (agents): everyone
 * advertising the academy, what they have brought in, and what the academy
 * owes them. The per-agent numbers mirror AgentController::dashboard exactly
 * (same earned / pending / balance formulas) so the owner and the agent never
 * see two different balances, but they are computed with grouped queries for
 * the whole list at once instead of per-agent round trips.
 *
 * Authorization is the OwnerAdminController pattern: the tenant comes from the
 * owner's own session + tenant_admins check (ownerContext), which also binds
 * currentTenant so every Agent query below is scoped to their academy — a
 * cross-academy agent id 404s before anything else runs.
 */
class OwnerAgentController extends OwnerAdminController
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        PlanGate::ensureFeature($tenant, 'admission_marketer');

        $agents = Agent::query()->latest('id')->get();
        $ids = $agents->pluck('id');

        // Students the agents referred (lms_students.referred_by_agent_id).
        $referredCounts = LmsStudent::query()
            ->whereIn('referred_by_agent_id', $ids)
            ->selectRaw('referred_by_agent_id as agent_id, count(*) as c')
            ->groupBy('referred_by_agent_id')
            ->pluck('c', 'agent_id');

        // Registrations each agent was involved in: a row they registered
        // directly (registered_by_agent_id) and/or referred (referred_by_agent_id).
        // A row with both columns set counts for each agent named — two agents
        // genuinely participated in it.
        $registrationCounts = collect();
        TrainingRegistration::query()
            ->whereIn('registered_by_agent_id', $ids)
            ->selectRaw('registered_by_agent_id as agent_id, count(*) as c')
            ->groupBy('registered_by_agent_id')
            ->get()
            ->each(fn ($r) => $registrationCounts[$r->agent_id] = ($registrationCounts[$r->agent_id] ?? 0) + $r->c);
        TrainingRegistration::query()
            ->whereIn('referred_by_agent_id', $ids)
            ->selectRaw('referred_by_agent_id as agent_id, count(*) as c')
            ->groupBy('referred_by_agent_id')
            ->get()
            ->each(fn ($r) => $registrationCounts[$r->agent_id] = ($registrationCounts[$r->agent_id] ?? 0) + $r->c);

        // Commission wallet per agent, one grouped query: earned (everything not
        // a withdrawal), the payout they have requested, and the payouts already
        // paid — balance = earned − both, the same arithmetic as the agent
        // dashboard (withdrawal rows are stored as negative amounts).
        $wallets = AgentCommission::query()
            ->whereIn('agent_id', $ids)
            ->selectRaw('agent_id,
                sum(case when type != "withdrawal" then commission_amount else 0 end) as earned,
                sum(case when type = "withdrawal" and status = "withdrawal_requested" then abs(commission_amount) else 0 end) as requested,
                sum(case when type = "withdrawal" and status = "paid" then abs(commission_amount) else 0 end) as paid')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $rows = $agents->map(function (Agent $agent) use ($referredCounts, $registrationCounts, $wallets) {
            $wallet = $wallets->get($agent->id);
            $earned = (float) ($wallet->earned ?? 0);
            $requested = (float) ($wallet->requested ?? 0);
            $paid = (float) ($wallet->paid ?? 0);

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'avatar_url' => $agent->profile_photo_url,
                'referral_code' => $agent->referral_code,
                'status' => $agent->status,
                'created_at' => $agent->created_at?->toIso8601String(),
                'approved_at' => $agent->approved_at?->toIso8601String(),
                'students_referred' => (int) ($referredCounts[$agent->id] ?? 0),
                'registrations' => (int) ($registrationCounts[$agent->id] ?? 0),
                'total_earned' => $earned,
                'balance' => $earned - $requested - $paid,
            ];
        })->values();

        return response()->json([
            'agents' => $rows,
            'totals' => [
                'agents' => $rows->count(),
                'approved' => $rows->where('status', 'approved')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
                'students_referred' => $rows->sum('students_referred'),
                'total_earned' => (float) $rows->sum('total_earned'),
                'total_balance' => (float) $rows->sum('balance'),
            ],
        ]);
    }

    /**
     * Approve a pending marketer so they can log into the agent portal. Mirrors
     * AgentController::adminApprove (the host's Blade panel version) — including
     * the best-effort approval email — but scoped to the owner's own academy.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        PlanGate::ensureFeature($tenant, 'admission_marketer');

        // Tenant-scoped: another academy's agent id 404s here.
        $agent = Agent::query()->findOrFail($id);

        $wasApproved = $agent->status === 'approved';

        $agent->update([
            'status' => 'approved',
            'approved_at' => $agent->approved_at ?? now(),
        ]);

        $emailSent = null;
        // Only a fresh approval gets the email; re-approving an already-approved
        // agent (a no-op) must not spam them.
        if (! $wasApproved && config('saas.training_email_enabled')) {
            try {
                Mail::to($agent->email)->send(
                    new AgentApplicationApprovedMail($agent, rtrim((string) config('saas.frontend_url'), '/') . '/lms/agent/login')
                );
                $emailSent = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Owner agent approve: approval email failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
                $emailSent = false;
            }
        }

        return response()->json([
            'message' => $wasApproved
                ? 'This agent is already approved.'
                : ($emailSent === false
                    ? 'Agent approved, but the approval email could not be sent. Ask them to use "Forgot password" to set one.'
                    : 'Agent approved. They can now sign in to the agent portal.'),
            'email_sent' => $emailSent,
            'agent' => $agent->fresh(),
        ]);
    }

    /**
     * Reject a pending application, or revoke an approved agent's access (the
     * agent-side auth checks status !== approved, so an existing login stops
     * working on their next request).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        PlanGate::ensureFeature($tenant, 'admission_marketer');

        $agent = Agent::query()->findOrFail($id);

        $wasApproved = $agent->status === 'approved';
        $agent->update(['status' => 'rejected']);

        return response()->json([
            'message' => $wasApproved ? 'Agent access revoked.' : 'Agent rejected.',
            'agent' => $agent->fresh(),
        ]);
    }
}
