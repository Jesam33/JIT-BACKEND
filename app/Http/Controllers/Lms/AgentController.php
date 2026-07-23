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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AgentController extends BaseLmsController
{
    private function agentOrFail(Request $request): Agent
    {
        $session = AgentSession::where('token', $request->bearerToken())
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->first();

        if (!$session) abort(401, 'Unauthorized');

        $agent = Agent::find($session->agent_id);
        if (!$agent || $agent->status !== 'approved') abort(403, 'Access denied.');

        return $agent;
    }

    private function generateReferralCode(): string
    {
        do {
            $code = 'AGENT-' . strtoupper(Str::random(6));
        } while (Agent::where('referral_code', $code)->exists());

        return $code;
    }

    // --- Public ---

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:agents,email',
            'phone' => 'required|string|max:40',
            'home_address' => 'required|string',
            'qualification' => 'required|string|max:255',
            'custom_answers' => 'required|array',
            'custom_answers.target_students' => 'required|string',
            'custom_answers.experience' => 'required|string',
            'custom_answers.courses_to_promote' => 'required|string',
        ]);

        $password = Str::random(12);

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

        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
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

        $agent = Agent::where('email', $validated['email'])->first();

        if (!$agent || !password_verify($validated['password'], $agent->password ?? '')) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($agent->status !== 'approved') {
            return response()->json(['message' => 'Your account is not yet approved.'], 403);
        }

        $token = Str::random(80);
        AgentSession::create([
            'agent_id' => $agent->id,
            'token' => $token,
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json(['token' => $token, 'agent' => $agent]);
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
        $agent->update(['avatar' => asset('storage/' . $path)]);

        return response()->json(['url' => $agent->avatar]);
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
            'course_price' => (float) $course->price,
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
                'price' => (float) $course->price,
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

        $items = $registrations->through(function ($r) use ($agent) {
            $payment = \App\Models\Payment::where('registration_id', $r->id)->first();
            $commission = \App\Models\AgentCommission::where('agent_id', $agent->id)
                ->where('enrollment_id', $r->id)
                ->first();
            $student = \App\Models\LmsStudent::where('training_registration_id', $r->id)->first();
            $enrollment = $student ? \App\Models\LmsEnrollment::where('student_id', $student->id)->first() : null;

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

        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $baseUrl = rtrim(env('LMS_BASE_URL', 'http://127.0.0.1:3000'), '/');
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

        $agent = Agent::where('email', $validated['email'])->first();

        if (!$agent) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

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

        if (!$this->isValidResetToken('agent', $validated['email'], $validated['token'])) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $agent = Agent::where('email', $validated['email'])->first();

        if (!$agent) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $agent->update(['password' => bcrypt($validated['password'])]);

        $this->consumeResetToken('agent', $validated['email'], $validated['token']);

        return response()->json(['message' => 'Password reset successfully.']);
    }


}
