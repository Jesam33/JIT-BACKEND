<?php

namespace App\Http\Controllers;

use App\Mail\TrainingRegistrationApprovedMail;
use App\Mail\TrainingRegistrationSubmittedMail;
use App\Models\Agent;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\TrainingCourse;
use App\Models\TrainingRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class TrainingRegistrationController extends Controller
{
    private function ensureTrainingFeatureEnabled(): void
    {
        if (! filter_var(env('TRAINING_FEATURE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new NotFoundHttpException();
        }
    }

    private function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();

        $isSuperUser = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        abort_unless($isSuperUser, 403, 'Only super admin can perform this action.');
    }

    public function courses(): JsonResponse
    {
        $this->ensureTrainingFeatureEnabled();

        return response()->json(
            TrainingCourse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensureTrainingFeatureEnabled();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'date_of_birth' => ['required', 'date'],
            'qualification_level' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'course_id' => ['nullable', 'integer', 'exists:training_courses,id'],
            'course_name' => ['required', 'string', 'max:255'],
            'learning_mode' => ['required', 'in:live,pre_recorded'],
            'referral_code' => ['nullable', 'string', 'max:20'],
        ]);

        $referredByAgentId = null;
        if (! empty($validated['referral_code'])) {
            $agent = Agent::where('referral_code', $validated['referral_code'])
                ->where('status', 'approved')
                ->first();
            if ($agent) {
                $referredByAgentId = $agent->id;
            }
        }

        $registration = TrainingRegistration::query()->create($validated + [
            'status' => 'pending',
            'referred_by_agent_id' => $referredByAgentId,
        ]);

        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $adminEmail = env('TRAINING_ADMIN_EMAIL');

            if ($adminEmail) {
                Mail::to($adminEmail)->send(new TrainingRegistrationSubmittedMail($registration));
            }
        }

        return response()->json([
            'message' => 'Registration submitted successfully. You will receive an update by email after review.',
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $this->ensureTrainingFeatureEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    public function adminIndex(Request $request)
    {
        $this->ensureTrainingFeatureEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $pendingRegistrations = TrainingRegistration::query()->where('status', 'pending')->count();

        return view('admin.training.registrations', [
            'items' => $items,
            'adminDir' => env('ADMIN_DIR', 'admin'),
            'pendingRegistrations' => $pendingRegistrations,
            'studentCount' => LmsStudent::query()->count(),
            'tracks' => LmsTrack::query()->count(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $this->ensureTrainingFeatureEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'course_price' => ['required', 'numeric', 'min:0'],
        ]);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $coursePrice = (float) $validated['course_price'];

        if ($registration->referred_by_agent_id) {
            $coursePrice = round($coursePrice * 0.95, 2);
        }

        $inviteToken = Str::random(64);

        $registration->update([
            'course_price' => $coursePrice,
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => $inviteToken,
        ]);

        $lmsLink = $this->buildLmsSignupLink($registration->email, $inviteToken);

        $emailSent = false;
        $emailError = null;
        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            try {
                Mail::to($registration->email)->send(new TrainingRegistrationApprovedMail($registration, $lmsLink));
                $emailSent = true;
            } catch (Throwable $exception) {
                $emailError = $exception->getMessage();
            }
        }

        $result = [
            'message' => 'Registration approved successfully.',
            'lms_link' => $lmsLink,
            'course_price' => number_format((float) $registration->course_price, 2, '.', ''),
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()
            ->with('status', $result['message'])
            ->with('lms_link', $result['lms_link'])
            ->with('email_sent', $result['email_sent'])
            ->with('email_error', $result['email_error'])
            ->with('mailer', (string) config('mail.default'));
    }

    public function decline(Request $request, int $id)
    {
        $this->ensureTrainingFeatureEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $registration->update([
            'status' => 'rejected',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => null,
        ]);

        $result = [
            'message' => 'Registration declined successfully.',
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureTrainingFeatureEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);
        $email = $registration->email;

        $registration->delete();

        $result = [
            'message' => "Registration deleted for {$email}.",
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    private function buildLmsSignupLink(string $email, string $token): string
    {
        $signupUrl = trim((string) env('LMS_SIGNUP_URL', ''));

        if (! $signupUrl) {
            $baseUrl = config('saas.frontend_url');
            $signupUrl = str_ends_with($baseUrl, '/lms') ? $baseUrl . '/signup' : $baseUrl . '/lms/signup';
        }

        return rtrim($signupUrl, '/') . '?email=' . urlencode($email) . '&token=' . urlencode($token);
    }
}
