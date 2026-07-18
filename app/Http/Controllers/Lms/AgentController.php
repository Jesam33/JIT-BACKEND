<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AgentApplicationApprovedMail;
use App\Mail\AgentApplicationSubmittedMail;
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
            Mail::to($agent->email)->send(new \App\Mail\AgentApplicationSubmittedMail($agent, ''));
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

    // --- Dashboard ---

    public function dashboard(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $totalReferred = LmsStudent::where('referred_by_agent_id', $agent->id)->count();
        $totalEnrollments = AgentCommission::where('agent_id', $agent->id)->count();
        $totalCommission = AgentCommission::where('agent_id', $agent->id)->sum('commission_amount');
        $pendingCommission = AgentCommission::where('agent_id', $agent->id)->where('status', 'pending')->sum('commission_amount');
        $paidCommission = AgentCommission::where('agent_id', $agent->id)->where('status', 'paid')->sum('commission_amount');

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

        return response()->json([
            'total_referred' => $totalReferred,
            'total_enrollments' => $totalEnrollments,
            'total_commission' => (float) $totalCommission,
            'pending_commission' => (float) $pendingCommission,
            'paid_commission' => (float) $paidCommission,
            'balance' => (float) ($totalCommission - $paidCommission),
            'referral_code' => $agent->referral_code,
            'recent_referrals' => $recentReferrals,
        ]);
    }

    // --- Register Student Directly ---

    public function registerStudent(Request $request): JsonResponse
    {
        $agent = $this->agentOrFail($request);

        $validated = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:40',
            'course_id' => 'required|integer|exists:lms_courses,id',
        ]);

        $course = LmsCourse::findOrFail($validated['course_id']);
        $coursePrice = (float) ($course->price ?? 0);

        DB::beginTransaction();
        try {
            $student = LmsStudent::firstOrCreate(
                ['email' => $validated['email']],
                [
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'],
                    'referred_by_agent_id' => $agent->id,
                ]
            );

            $track = LmsTrack::where('course_id', $course->id)
                ->where('status', 'active')
                ->first();

            if ($track) {
                LmsEnrollment::firstOrCreate([
                    'student_id' => $student->id,
                    'track_id' => $track->id,
                ]);
            }

            AgentCommission::create([
                'agent_id' => $agent->id,
                'enrollment_id' => null,
                'course_price' => $coursePrice,
                'commission_amount' => round($coursePrice * 0.10, 2),
                'status' => 'pending',
                'type' => 'direct',
            ]);

            AgentNotification::create([
                'agent_id' => $agent->id,
                'type' => 'student_registered',
                'title' => 'Student Registered',
                'body' => "You registered {$student->first_name} {$student->last_name} for {$course->title}.",
                'reference_type' => 'student',
                'reference_id' => $student->id,
            ]);

            DB::commit();

            return response()->json(['message' => 'Student registered successfully.', 'student' => $student]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to register student.'], 500);
        }
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

        AgentCommission::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->update(['status' => 'withdrawal_requested']);

        AgentCommission::create([
            'agent_id' => $agent->id,
            'enrollment_id' => null,
            'course_price' => 0,
            'commission_amount' => -$pendingCommission,
            'status' => 'withdrawal_requested',
            'type' => 'withdrawal',
            'notes' => "Withdrawal request for ₦" . number_format($pendingCommission, 2),
        ]);

        return response()->json(['message' => 'Withdrawal requested. You will be contacted for payout.']);
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
}
