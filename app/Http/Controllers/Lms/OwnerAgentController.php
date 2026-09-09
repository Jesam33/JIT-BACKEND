<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AgentApplicationApprovedMail;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\LmsEnrollment;
use App\Models\LmsStudent;
use App\Models\Payment;
use App\Models\TrainingRegistration;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
     * One agent's analytics: profile, wallet stats, and the three activity
     * lists (referred students, registrations with payment + commission state,
     * and the commission/withdrawal history). Same numbers the agent sees on
     * their own dashboard (AgentController::dashboard / registrations), but
     * batched — related rows are loaded once per list and keyed, never one
     * query per row.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        PlanGate::ensureFeature($tenant, 'admission_marketer');

        // Tenant-scoped: another academy's agent id 404s here.
        $agent = Agent::query()->findOrFail($id);

        // Wallet, same arithmetic as index() and the agent dashboard.
        $wallet = AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->selectRaw('
                sum(case when type != "withdrawal" then commission_amount else 0 end) as earned,
                sum(case when type = "withdrawal" and status = "withdrawal_requested" then abs(commission_amount) else 0 end) as requested,
                sum(case when type = "withdrawal" and status = "paid" then abs(commission_amount) else 0 end) as paid')
            ->first();

        $earned = (float) ($wallet->earned ?? 0);
        $requested = (float) ($wallet->requested ?? 0);
        $paid = (float) ($wallet->paid ?? 0);

        // Recent referred students + their first enrollment's course, batched.
        $students = LmsStudent::query()
            ->where('referred_by_agent_id', $agent->id)
            ->latest('id')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at']);

        $enrollments = LmsEnrollment::query()
            ->with('course:id,title')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $referrals = $students->map(fn (LmsStudent $s) => [
            'id' => $s->id,
            'name' => trim("{$s->first_name} {$s->last_name}") ?: $s->email,
            'email' => $s->email,
            'course' => $enrollments->get($s->id)?->first()?->course?->title ?? 'No enrollment yet',
            'enrolled_at' => $enrollments->get($s->id)?->first()?->created_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ])->values();

        // Recent registrations this agent brought in (registered directly or
        // referred), with payment + commission state — batched like
        // AgentController::registrations.
        $registrations = TrainingRegistration::query()
            ->where(function ($q) use ($agent) {
                $q->where('registered_by_agent_id', $agent->id)
                    ->orWhere('referred_by_agent_id', $agent->id);
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $regIds = $registrations->pluck('id');
        $payments = Payment::query()->whereIn('registration_id', $regIds)->get()->keyBy('registration_id');
        // AgentCommission.enrollment_id actually references training_registrations.id
        // (historical column name, same mapping the agent portal uses).
        $commissions = AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->whereIn('enrollment_id', $regIds)
            ->get()
            ->keyBy('enrollment_id');

        $registrationRows = $registrations->map(fn (TrainingRegistration $r) => [
            'id' => $r->id,
            'name' => trim("{$r->first_name} {$r->last_name}"),
            'course' => $r->course?->title ?? $r->course_name,
            'type' => $r->registered_by_agent_id === $agent->id ? 'direct' : 'referral',
            'status' => $r->status,
            'payment_status' => $payments->get($r->id)?->status ?? 'none',
            'commission' => (float) ($commissions->get($r->id)?->commission_amount ?? 0),
            'commission_status' => $commissions->get($r->id)?->status,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values();

        // Commission / withdrawal ledger, newest first.
        $transactions = AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (AgentCommission $c) => [
                'id' => $c->id,
                'amount' => (float) $c->commission_amount,
                'type' => $c->type,
                'status' => $c->status,
                'notes' => $c->notes,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values();

        $totalStudents = LmsStudent::query()->where('referred_by_agent_id', $agent->id)->count();
        $totalRegistrations = TrainingRegistration::query()
            ->where(function ($q) use ($agent) {
                $q->where('registered_by_agent_id', $agent->id)
                    ->orWhere('referred_by_agent_id', $agent->id);
            })
            ->count();

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'avatar_url' => $agent->profile_photo_url,
                'referral_code' => $agent->referral_code,
                'status' => $agent->status,
                'created_at' => $agent->created_at?->toIso8601String(),
                'approved_at' => $agent->approved_at?->toIso8601String(),
            ],
            'stats' => [
                'students_referred' => $totalStudents,
                'registrations' => $totalRegistrations,
                'total_earned' => $earned,
                'pending_withdrawal' => $requested,
                'paid_out' => $paid,
                'balance' => $earned - $requested - $paid,
            ],
            'referrals' => $referrals,
            'registrations' => $registrationRows,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Approve a pending marketer so they can log into the agent portal.
     * Mirrors the host panel's AdminController::approveAgent: a NEW temporary
     * password is generated, saved, and emailed (the agent cannot sign in
     * without it — AgentApplicationApprovedMail REQUIRES the password as its
     * third argument; the API-side adminApprove omitted it, which made every
     * approval email throw inside its own catch and fail silently).
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

        // A fresh approval needs a password they can actually use; re-approving
        // an already-approved agent is a no-op and must not reset (or re-email)
        // their password.
        $password = null;
        if (! $wasApproved) {
            $password = Str::random(12);
            $agent->update([
                'status' => 'approved',
                'approved_at' => now(),
                'password' => Hash::make($password),
            ]);
        }

        $emailSent = null;
        if ($password !== null && config('saas.training_email_enabled')) {
            try {
                Mail::to($agent->email)->send(
                    new AgentApplicationApprovedMail(
                        $agent,
                        rtrim((string) config('saas.frontend_url'), '/') . '/lms/agent/login?email=' . urlencode($agent->email),
                        $password
                    )
                );
                $emailSent = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Owner agent approve: approval email failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
                $emailSent = false;
            }
        }

        $message = 'Agent approved. They can now sign in to the agent portal.';
        if ($emailSent === false) {
            $message = 'Agent approved, but the approval email could not be sent. Ask them to use "Forgot password" to set one.';
        } elseif ($wasApproved) {
            $message = 'This agent is already approved.';
        }

        return response()->json([
            'message' => $message,
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
