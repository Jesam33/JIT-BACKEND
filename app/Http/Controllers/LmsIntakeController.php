<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmationMail;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\LmsCourse;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\TrainingRegistration;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LmsIntakeController extends Controller
{
    private function ensureIntakeEnabled(): void
    {
        if (! filter_var(env('TRAINING_FEATURE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new NotFoundHttpException();
        }
    }

    public function courseCatalog(): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $courses = LmsCourse::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCourse $course) => [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'description' => $course->description,
                'price' => (float) $course->price,
                'max_students' => $course->max_students,
                'registered_count' => $course->registered_count,
                'slots_remaining' => $course->slotsRemaining(),
                'is_full' => $course->isFull(),
                'is_live_available' => $course->is_live_available,
                'is_prerecorded_available' => $course->is_prerecorded_available,
            ])
            ->values();

        return response()->json($courses);
    }

    public function courseDetail(string $slug): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $course = LmsCourse::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return response()->json([
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'requirements' => $course->requirements,
            'price' => (float) $course->price,
            'max_students' => $course->max_students,
            'registered_count' => $course->registered_count,
            'slots_remaining' => $course->slotsRemaining(),
            'is_full' => $course->isFull(),
            'is_live_available' => $course->is_live_available,
            'is_prerecorded_available' => $course->is_prerecorded_available,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'date_of_birth' => ['required', 'date'],
            'qualification_level' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'learning_mode' => ['required', 'in:live,pre_recorded'],
        ]);

        $course = LmsCourse::query()->findOrFail($validated['course_id']);

        if ($course->isFull()) {
            return response()->json([
                'message' => 'This course is full. Please join the waitlist.',
                'is_full' => true,
            ], 422);
        }

        $registration = TrainingRegistration::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'date_of_birth' => $validated['date_of_birth'],
            'qualification_level' => $validated['qualification_level'],
            'phone_number' => $validated['phone_number'],
            'email' => $validated['email'],
            'whatsapp' => $validated['whatsapp'],
            'course_id' => $course->id,
            'course_name' => $course->title,
            'learning_mode' => $validated['learning_mode'],
            'course_price' => $course->price,
            'status' => 'pending',
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

    public function initializePayment(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'registration_id' => ['required', 'integer', 'exists:training_registrations,id'],
        ]);

        $registration = TrainingRegistration::query()->findOrFail($validated['registration_id']);

        if ($registration->status !== 'pending') {
            return response()->json(['message' => 'Payment already processed for this registration.'], 422);
        }

        if ((float) $registration->course_price <= 0) {
            return $this->handleZeroPayment($registration);
        }

        $reference = 'JORSAS-' . Str::upper(Str::random(20));

        try {
            $paystack = app(PaystackService::class);

            if (! $paystack->isConfigured()) {
                return $this->handleZeroPayment($registration);
            }

            $response = $paystack->initializeTransaction(
                $registration->email,
                (float) $registration->course_price,
                $reference,
                [
                    'registration_id' => $registration->id,
                    'course_name' => $registration->course_name,
                ]
            );

            Payment::query()->create([
                'registration_id' => $registration->id,
                'reference' => $reference,
                'amount' => $registration->course_price,
                'currency' => 'NGN',
                'status' => 'pending',
                'gateway' => 'paystack',
            ]);

            return response()->json([
                'authorization_url' => $response['data']['authorization_url'] ?? null,
                'reference' => $reference,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Paystack initialization failed', [
                'error' => $exception->getMessage(),
                'registration_id' => $registration->id,
            ]);

            return response()->json(['message' => 'Could not initialize payment. Please try again.'], 500);
        }
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $payment = Payment::query()->where('reference', $validated['reference'])->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment reference not found.'], 404);
        }

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Payment already verified.', 'status' => 'success']);
        }

        try {
            $paystack = app(PaystackService::class);
            $response = $paystack->verifyTransaction($validated['reference']);

            if (($response['data']['status'] ?? '') === 'success') {
                return $this->completePayment($payment);
            }

            return response()->json([
                'message' => 'Payment not yet confirmed.',
                'status' => $response['data']['status'] ?? 'unknown',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Paystack verification failed', [
                'error' => $exception->getMessage(),
                'reference' => $validated['reference'],
            ]);

            return response()->json(['message' => 'Could not verify payment.'], 500);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');

        $secret = (string) env('PAYSTACK_SECRET_KEY', '');
        $payload = $request->getContent();

        if ($signature !== hash_hmac('sha512', $payload, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->input('event');

        if ($event === 'charge.success') {
            $reference = $request->input('data.reference');
            $payment = Payment::query()->where('reference', $reference)->first();

            if ($payment && $payment->status !== 'success') {
                $this->completePayment($payment);
            }
        }

        return response()->json(['message' => 'Webhook received.']);
    }

    private function handleZeroPayment(TrainingRegistration $registration): JsonResponse
    {
        $token = Str::random(80);

        $student = LmsStudent::query()->create([
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'email' => $registration->email,
            'selected_course_id' => $registration->course_id,
            'learning_mode' => $registration->learning_mode,
            'onboarding_completed' => false,
        ]);

        $setupToken = Str::random(64);

        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
            'invite_token' => $setupToken,
        ]);

        LmsCourse::query()->where('id', $registration->course_id)->increment('registered_count');

        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * 0.10, 2),
                'status' => 'pending',
                'type' => 'referral',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        }

        Payment::query()->create([
            'registration_id' => $registration->id,
            'reference' => 'FREE-' . Str::upper(Str::random(16)),
            'amount' => 0,
            'currency' => 'NGN',
            'status' => 'success',
            'gateway' => 'free',
        ]);

        $this->sendSetupEmail($registration, $setupToken);

        return response()->json([
            'message' => 'Registration complete! Check your email for setup instructions.',
            'status' => 'success',
        ]);
    }

    private function completePayment(Payment $payment): JsonResponse
    {
        $registration = $payment->registration;

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        $payment->update(['status' => 'success']);
        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        LmsStudent::query()->updateOrCreate(
            ['email' => $registration->email],
            [
                'training_registration_id' => $registration->id,
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'selected_course_id' => $registration->course_id,
                'learning_mode' => $registration->learning_mode,
                'onboarding_completed' => false,
            ]
        );

        LmsCourse::query()->where('id', $registration->course_id)->increment('registered_count');

        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * 0.10, 2),
                'status' => 'pending',
                'type' => 'referral',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        }

        $setupToken = Str::random(64);
        $registration->update(['invite_token' => $setupToken]);

        $this->sendSetupEmail($registration, $setupToken);

        return response()->json([
            'message' => 'Payment confirmed! Check your email for LMS setup instructions.',
            'status' => 'success',
        ]);
    }

    // ─── Admin intake management ─────────────────────────────────────

    public function pending(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    public function adminIndex(Request $request)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $pendingRegistrations = TrainingRegistration::query()->where('status', 'pending')->count();

        return view('admin.lms.intake.index', [
            'items' => $items,
            'adminDir' => env('ADMIN_DIR', 'admin'),
            'pendingRegistrations' => $pendingRegistrations,
            'studentCount' => LmsStudent::query()->count(),
            'tracks' => LmsTrack::query()->count(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $setupToken = Str::random(64);

        $registration->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => $setupToken,
        ]);

        $this->sendSetupEmail($registration, $setupToken);

        $result = ['message' => 'Intake approved and setup email sent.'];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    public function decline(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $registration->update([
            'status' => 'rejected',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => null,
        ]);

        $result = ['message' => 'Intake declined successfully.'];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);
        $email = $registration->email;
        $registration->delete();

        $result = ['message' => "Intake record deleted for {$email}."];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $isSuperUser = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        abort_unless($isSuperUser, 403, 'Only super admin can perform this action.');
    }

    private function sendSetupEmail(TrainingRegistration $registration, string $setupToken): void
    {
        $baseUrl = rtrim((string) env('LMS_BASE_URL', 'http://127.0.0.1:3000'), '/');
        $setupLink = $baseUrl . '/lms/setup-password?token=' . urlencode($setupToken) . '&email=' . urlencode($registration->email);

        try {
            Mail::to($registration->email)->send(new PaymentConfirmationMail($registration, $setupLink));
        } catch (\Throwable $exception) {
            Log::error('Failed to send setup email', [
                'error' => $exception->getMessage(),
                'registration_id' => $registration->id,
            ]);
        }
    }
}
