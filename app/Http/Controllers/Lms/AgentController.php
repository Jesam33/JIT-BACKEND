<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AgentApplicationApprovedMail;
use App\Mail\AgentWithdrawalRequestedMail;
use App\Mail\AgentApplicationAcknowledgedMail;
use App\Mail\AgentApplicationSubmittedMail;
use App\Mail\LmsPasswordResetMail;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AgentNotification;
use App\Models\AgentSession;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentController extends BaseLmsController
{
    private function agentOrFail(Request $request): Agent
    {
        // The session token is the authoritative proof of identity, so the
        // lookup must not be tenant-scoped (otherwise a spoofable header would
        // decide which tenant's sessions we search). We derive the tenant FROM
        // the session, not the other way around.
        $session = AgentSession::withoutGlobalScope(TenantScope::class)
            ->where('token', $request->bearerToken())
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->first();

        if (!$session) abort(401, 'Unauthorized');

        // Bind the agent's organisation so every downstream TenantAware query in
        // the request scopes to it.
        $this->bindTenantFromModel($session);

        $agent = Agent::withoutGlobalScope(TenantScope::class)->find($session->agent_id);
        if (!$agent || $agent->status !== 'approved') abort(403, 'Access denied.');

        return $agent;
    }

    private function generateReferralCode(): string
    {
        // referral_code is GLOBALLY unique (its index spans all academies, unlike
        // the now per-academy email), so the collision check must ignore the
        // tenant scope — otherwise a code already taken by another academy would
        // pass this check and then hit the DB unique index as a 500.
        do {
            $code = 'AGENT-' . strtoupper(Str::random(6));
        } while (Agent::withoutGlobalScope(TenantScope::class)->where('referral_code', $code)->exists());

        return $code;
    }

    // --- Public ---

    public function apply(Request $request): JsonResponse
    {
        // Agents are per-academy: resolve the academy FIRST (from the tenant
        // header, or JIT on the bare primary domain) so the email-uniqueness rule
        // can be scoped to it — the SAME person may apply to be an agent at
        // several academies with one email.
        $tenant = $this->currentTenantOrPrimary();

        // The Admission-Marketer Network is a paid feature (Basic+). On an academy
        // whose plan doesn't include it the public application path is closed with
        // a neutral message — a visitor can't upgrade the academy, so (unlike the
        // owner-side management endpoints) this never raises the upgrade modal.
        if ($tenant && ! $tenant->planFeature('admission_marketer')) {
            return response()->json([
                'message' => "This academy isn't currently accepting Admission Marketer applications.",
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Unique PER ACADEMY, not globally (mirrors the (tenant_id, email)
            // composite index): the same email may already be an agent elsewhere.
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('agents', 'email')->where('tenant_id', $tenant?->id),
            ],
            'phone' => 'required|string|max:40',
            'home_address' => 'required|string',
            'qualification' => 'required|string|max:255',
            'custom_answers' => 'required|array',
            'custom_answers.target_students' => 'required|string',
            'custom_answers.experience' => 'required|string',
            'custom_answers.courses_to_promote' => 'required|string',
        ]);

        $password = Str::random(12);

        // The tenant is already bound (above), so Agent::create auto-stamps its
        // tenant_id via TenantAware.
        $agent = Agent::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'home_address' => $validated['home_address'],
            'qualification' => $validated['qualification'],
            'custom_answers' => $validated['custom_answers'],
            'referral_code' => $this->generateReferralCode(),
            'status' => 'pending',
            'password' => bcrypt($password),
        ]);

        if (config('saas.training_email_enabled')) {
            $adminEmail = env('TRAINING_ADMIN_EMAIL');
            if ($adminEmail) {
                $adminUrl = rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/')
                    . '/' . trim(env('ADMIN_DIR', 'admin'), '/')
                    . '/agents';
                Mail::to($adminEmail)->send(new AgentApplicationSubmittedMail($agent, $adminUrl));
            }
            Mail::to($agent->email)->send(new \App\Mail\AgentApplicationAcknowledgedMail($agent));
        }

        return response()->json(['message' => 'Application submitted successfully. You will receive an email once reviewed.']);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Agent emails are unique PER ACADEMY (not globally), so resolve the
        // account the tenant-aware way students log in: a pinned academy
        // (?tenant= → requestedTenantSlug) scopes the lookup; otherwise match
        // across academies by password. The resolved agent's own tenant_id is
        // the authoritative organisation for the session.
        $agent = $this->authenticateAgent($validated['email'], $validated['password']);

        if (! $agent) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($agent->status !== 'approved') {
            return response()->json(['message' => 'Your account is not yet approved.'], 403);
        }

        // Stamp the session with the agent's organisation.
        $this->bindTenantFromModel($agent);

        $token = Str::random(80);
        AgentSession::create([
            'agent_id' => $agent->id,
            'token' => $token,
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json(['token' => $token, 'agent' => $agent]);
    }

    /**
     * Tenant-aware agent lookup for login, mirroring
     * {@see StudentAuthController::authenticateStudent}. When the request pins an
     * academy (?tenant= → requestedTenantSlug is bound, and the global TenantScope
     * scopes to it), the lookup resolves the right per-academy account even when
     * the same email is an agent at several academies. Otherwise we match across
     * academies by password (newest first). Keeps agents' bcrypt password_verify.
     */
    private function authenticateAgent(string $email, string $password): ?Agent
    {
        if (app()->bound('requestedTenantSlug')) {
            $agent = Agent::query()->where('email', $email)->first();

            return ($agent && password_verify($password, $agent->password ?? '')) ? $agent : null;
        }

        $candidates = Agent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (password_verify($password, $candidate->password ?? '')) {
                return $candidate;
            }
        }

        return null;
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->agentOrFail($request));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'home_address' => ['sometimes', 'string'],
            'bank_name' => ['sometimes', 'string', 'max:255'],
            'account_number' => ['sometimes', 'string', 'max:20'],
            'account_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $agent->update($validated);

        return response()->json(['message' => 'Profile updated.', 'agent' => $agent]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $request->validate(['file' => ['required', 'image', 'max:2048']]);

        $path = $request->file('file')->store('profile-photos', 'public');
        // Store the RELATIVE path (the model mutator normalises it); the accessor
        // rebuilds an absolute URL against the current host on read, so the image
        // can't break when the app host changes (localhost → live, http → https).
        $agent->update(['avatar' => $path]);

        return response()->json(['url' => $agent->profile_photo_url]);
    }

    // --- Dashboard ---

    public function dashboard(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $totalReferred = LmsStudent::where('referred_by_agent_id', $agent->id)->count();
        $totalEnrollments = AgentCommission::where('agent_id', $agent->id)->count();

        $totalEarned = AgentCommission::where('agent_id', $agent->id)
            ->where('type', '!=', 'withdrawal')
            ->sum('commission_amount');

        $pendingCommission = AgentCommission::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->where('type', '!=', 'withdrawal')
            ->sum('commission_amount');

        $pendingWithdrawalAmount = AgentCommission::where('agent_id', $agent->id)
            ->where('status', 'withdrawal_requested')
            ->where('type', 'withdrawal')
            ->sum('commission_amount');

        $paidCommission = abs(AgentCommission::where('agent_id', $agent->id)
            ->where('type', 'withdrawal')
            ->where('status', 'paid')
            ->sum('commission_amount'));

        $recentReferrals = LmsStudent::where('referred_by_agent_id', $agent->id)
            ->with('enrollments.course')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->first_name . ' ' . $s->last_name,
                'email' => $s->email,
                'course' => $s->enrollments->first()?->course?->title ?? 'N/A',
                'enrolled_at' => $s->enrollments->first()?->created_at?->toIso8601String(),
            ]);

        $recentTransactions = AgentCommission::where('agent_id', $agent->id)
            ->latest()
            ->take(20)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'amount' => (float) $c->commission_amount,
                'type' => $c->type,
                'status' => $c->status,
                'notes' => $c->notes,
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return response()->json([
            'total_referred' => $totalReferred,
            'total_enrollments' => $totalEnrollments,
            'total_earned' => (float) $totalEarned,
            'pending_commission' => (float) $pendingCommission,
            'pending_withdrawal' => (float) abs($pendingWithdrawalAmount),
            'paid_commission' => (float) $paidCommission,
            'balance' => (float) ($totalEarned - abs($pendingWithdrawalAmount) - $paidCommission),
            'referral_code' => $agent->referral_code,
            'has_bank_details' => !empty($agent->bank_name) && !empty($agent->account_number) && !empty($agent->account_name),
            'recent_referrals' => $recentReferrals,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    // --- Register Student Directly ---

    public function registerStudent(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $validated = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'date_of_birth' => 'required|date',
            'qualification_level' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone_number' => 'required|string|max:40',
            'whatsapp' => 'required|string|max:40',
            'course_id' => 'required|integer|exists:lms_courses,id',
            'learning_mode' => 'required|in:live,pre_recorded',
        ]);

        $course = LmsCourse::findOrFail($validated['course_id']);

        if ($course->isFull()) {
            return response()->json(['message' => 'This course is full.', 'is_full' => true], 422);
        }

        // Pre-recorded (on-demand) is cheaper when the course sets a distinct
        // prerecorded_price; otherwise both modes charge the live price. This is
        // the amount frozen into the registration (feeds Paystack + commissions).
        $price = ($validated['learning_mode'] === 'pre_recorded' && $course->prerecorded_price !== null)
            ? (float) $course->prerecorded_price
            : (float) $course->price;

        $registration = \App\Models\TrainingRegistration::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'date_of_birth' => $validated['date_of_birth'],
            'qualification_level' => $validated['qualification_level'],
            'email' => $validated['email'],
            'phone_number' => $validated['phone_number'],
            'whatsapp' => $validated['whatsapp'],
            'course_id' => $course->id,
            'course_name' => $course->title,
            'learning_mode' => $validated['learning_mode'],
            'course_price' => $price,
            'status' => 'pending',
            'registered_by_agent_id' => $agent->id,
        ]);

        AgentNotification::create([
            'agent_id' => $agent->id,
            'type' => 'student_registered',
            'title' => 'Student Registered',
            'body' => "Registration created for {$registration->first_name} {$registration->last_name} — {$course->title}.",
            'reference_type' => 'registration',
            'reference_id' => $registration->id,
        ]);

        return response()->json([
            'message' => 'Registration created. Proceed to payment.',
            'registration_id' => $registration->id,
            'course' => [
                'title' => $course->title,
                'price' => $price,
            ],
        ], 201);
    }

    // --- Available Courses for Agent ---

    public function courses(): JsonResponse
    {
        return response()->json(
            LmsCourse::where('is_active', true)->get(['id', 'title', 'slug', 'price'])
        );
    }

    // --- Commissions / Wallet ---

    public function commissions(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $items = AgentCommission::where('agent_id', $agent->id)
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }

    public function registrations(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $registrations = \App\Models\TrainingRegistration::where(function ($q) use ($agent) {
            $q->where('registered_by_agent_id', $agent->id)
              ->orWhere('referred_by_agent_id', $agent->id);
        })
        ->with('course')
        ->latest()
        ->paginate(50);

        // Batch-load the four related sets for this page once, keyed for O(1)
        // lookup, instead of running 4 queries per registration inside through().
        $regIds = collect($registrations->items())->pluck('id');
        $payments = \App\Models\Payment::whereIn('registration_id', $regIds)
            ->get()->keyBy('registration_id');
        $commissions = \App\Models\AgentCommission::where('agent_id', $agent->id)
            ->whereIn('enrollment_id', $regIds)
            ->get()->keyBy('enrollment_id');
        $students = \App\Models\LmsStudent::whereIn('training_registration_id', $regIds)
            ->get()->keyBy('training_registration_id');
        $enrollments = \App\Models\LmsEnrollment::whereIn('student_id', $students->pluck('id'))
            ->get()->keyBy('student_id');

        $items = $registrations->through(function ($r) use ($agent, $payments, $commissions, $students, $enrollments) {
            $payment = $payments->get($r->id);
            $commission = $commissions->get($r->id);
            $student = $students->get($r->id);
            $enrollment = $student ? $enrollments->get($student->id) : null;

            return [
                'id' => $r->id,
                'name' => $r->first_name . ' ' . $r->last_name,
                'email' => $r->email,
                'phone' => $r->phone_number,
                'course' => $r->course?->title ?? $r->course_name,
                'type' => $r->registered_by_agent_id === $agent->id ? 'direct' : 'referral',
                'status' => $r->status,
                'enrolled' => $student && $enrollment,
                'student_id' => $student?->id,
                'payment_status' => $payment?->status ?? 'none',
                'commission' => $commission ? (float) $commission->commission_amount : 0,
                'commission_status' => $commission?->status ?? null,
                'created_at' => $r->created_at->toIso8601String(),
            ];
        });

        return response()->json($items);
    }

    public function withdrawalHistory(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $items = AgentCommission::where('agent_id', $agent->id)
            ->where('type', 'withdrawal')
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $pendingCommission = AgentCommission::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->sum('commission_amount');

        if ($pendingCommission <= 0) {
            return response()->json(['message' => 'No pending commissions to withdraw.'], 400);
        }

        AgentCommission::create([
            'agent_id' => $agent->id,
            'enrollment_id' => null,
            'course_price' => 0,
            'commission_amount' => -$pendingCommission,
            'status' => 'withdrawal_requested',
            'type' => 'withdrawal',
            'notes' => "Withdrawal request for ₦" . number_format($pendingCommission, 2),
        ]);

        $this->sendWithdrawalEmail($agent, $pendingCommission);

        return response()->json(['message' => 'Withdrawal requested. The admin will process your payout.']);
    }

    private function sendWithdrawalEmail(\App\Models\Agent $agent, float $amount): void
    {
        $adminEmail = env('TRAINING_ADMIN_EMAIL');
        if (!$adminEmail) return;

        $adminUrl = rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/')
            . '/' . trim(env('ADMIN_DIR', 'admin'), '/')
            . '/lms/agents/withdrawals';

        try {
            \Illuminate\Support\Facades\Mail::to($adminEmail)
                ->send(new \App\Mail\AgentWithdrawalRequestedMail($agent, $amount, $adminUrl));
        } catch (\Throwable $e) {
            // silently log — email must not break the withdrawal
        }
    }

    // --- Notifications ---

    public function notifications(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $items = AgentNotification::where('agent_id', $agent->id)
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $count = AgentNotification::where('agent_id', $agent->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        AgentNotification::where('agent_id', $agent->id)
            ->where('id', $id)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        AgentNotification::where('agent_id', $agent->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All marked as read.']);
    }

    // --- Admin: List pending agents ---

    public function adminPending(): JsonResponse
    {
        return response()->json(
            Agent::where('status', 'pending')->latest()->get()
        );
    }

    public function adminAll(): JsonResponse
    {
        return response()->json(
            Agent::latest()->paginate(20)
        );
    }

    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);

        $agent->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        if (config('saas.training_email_enabled')) {
            $baseUrl = config('saas.frontend_url');
            try {
                Mail::to($agent->email)->send(new AgentApplicationApprovedMail($agent, $baseUrl . '/lms/agent/login'));
            } catch (\Throwable $e) {
                // Log error but don't fail
            }
        }

        return response()->json(['message' => 'Agent approved.', 'agent' => $agent]);
    }

    public function adminReject(Request $request, int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        $agent->update(['status' => 'rejected']);

        return response()->json(['message' => 'Agent rejected.']);
    }

    // --- Password Reset ---

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = $validated['email'];

        // Agent emails are unique PER ACADEMY. Prefer the explicitly-requested
        // academy (?tenant= → requestedTenantSlug), else fall back across
        // academies (newest first). bindTenantFromModel then stamps the token —
        // and the emailed link's ?tenant= — with the account's own academy, so the
        // reset and the subsequent login both stay on it.
        $agent = app()->bound('requestedTenantSlug')
            ? Agent::query()->where('email', $email)->first()
            : null;

        if (! $agent) {
            $agent = Agent::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('email', $email)
                ->orderByDesc('id')
                ->first();
        }

        if (! $agent) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

        $this->bindTenantFromModel($agent);

        $token = $this->createPasswordResetToken('agent', $agent->email);
        $link = $this->buildResetLink('agent', $agent->email, $token);

        \Illuminate\Support\Facades\Mail::to($agent->email)->send(new LmsPasswordResetMail($agent->name, 'Agent Portal', $link));

        return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        // The token row carries the issuing academy; bind it so the account lookup
        // resolves the right per-academy agent even on the bare domain.
        $reset = $this->resolveResetToken('agent', $validated['email'], $validated['token']);

        if (! $reset) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $this->bindTenantFromModel($reset);

        $agent = Agent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $validated['email'])
            ->when($reset->tenant_id, fn ($q) => $q->where('tenant_id', $reset->tenant_id))
            ->orderByDesc('id')
            ->first();

        if (! $agent) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $agent->update(['password' => bcrypt($validated['password'])]);

        $this->consumeResetToken('agent', $validated['email'], $validated['token']);

        return response()->json(['message' => 'Password reset successfully.']);
    }


}
