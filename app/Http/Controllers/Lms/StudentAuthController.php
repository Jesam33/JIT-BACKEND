<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsPasswordResetMail;
use App\Models\LmsCourse;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class StudentAuthController extends BaseLmsController
{
    public function invite(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $token = (string) $request->query('token', '');

        $registration = TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('invite_token', $token)
            ->where('status', 'approved')
            ->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid or expired invite token.'], 404);
        }

        return response()->json([
            'email' => $registration->email,
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'course_id' => $registration->course_id,
            'course_name' => $registration->course_name,
            'learning_mode' => $registration->learning_mode,
        ]);
    }

    public function courses(): JsonResponse
    {
        $this->ensureLmsEnabled();

        return response()->json(
            LmsCourse::query()->where('is_active', true)->orderBy('title')->get()
        );
    }

    public function signup(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'learning_mode' => ['nullable', 'in:live,pre_recorded'],
        ]);

        $registration = TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('invite_token', $validated['token'])
            ->where('status', 'approved')
            ->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid invite token.'], 404);
        }

        // The approved registration is the authoritative proof of the organisation.
        $this->bindTenantFromModel($registration);

        $resolvedCourseId = $validated['course_id'] ?? $registration->course_id;
        $resolvedLearningMode = $validated['learning_mode'] ?? $registration->learning_mode ?? 'live';

        $student = LmsStudent::query()->updateOrCreate(
            ['email' => $registration->email],
            [
                'training_registration_id' => $registration->id,
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'password' => Hash::make($validated['password']),
                'selected_course_id' => $resolvedCourseId,
                'learning_mode' => $resolvedLearningMode,
                'onboarding_completed' => true,
            ]
        );

        $token = Str::random(80);

        LmsSession::query()->create([
            'role' => 'student',
            'user_id' => $student->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        if (! empty($resolvedCourseId)) {
            $track = $this->findActiveTrackForCourse($resolvedCourseId);
            if ($track) {
                LmsEnrollment::query()->updateOrCreate(
                    ['student_id' => $student->id],
                    ['track_id' => $track->id]
                );

                LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

                LmsDmThread::query()->firstOrCreate([
                    'student_id' => $student->id,
                    'instructor_id' => $track->instructor_id,
                    'track_id' => $track->id,
                ]);
            }
        }

        return response()->json(['token' => $token]);
    }

    public function setupPassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $registration = TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('invite_token', $validated['token'])
            ->where('status', 'approved')
            ->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid or expired setup link.'], 404);
        }

        // The approved registration is the authoritative proof of the organisation.
        $this->bindTenantFromModel($registration);

        $student = LmsStudent::query()->updateOrCreate(
            ['email' => $registration->email],
            [
                'training_registration_id' => $registration->id,
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'password' => Hash::make($validated['password']),
                'selected_course_id' => $registration->course_id,
                'learning_mode' => $registration->learning_mode,
                'onboarding_completed' => true,
            ]
        );

        $token = Str::random(80);

        LmsSession::query()->create([
            'role' => 'student',
            'user_id' => $student->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        if (! empty($registration->course_id)) {
            $track = $this->findActiveTrackForCourse($registration->course_id);
            if ($track) {
                LmsEnrollment::query()->updateOrCreate(
                    ['student_id' => $student->id],
                    ['track_id' => $track->id]
                );

                LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

                LmsDmThread::query()->firstOrCreate([
                    'student_id' => $student->id,
                    'instructor_id' => $track->instructor_id,
                    'track_id' => $track->id,
                ]);
            }
        }

        $registration->update(['invite_token' => null]);

        return response()->json(['token' => $token]);
    }

    public function login(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $student = $this->authenticateStudent($validated['email'], $validated['password']);

        if (! $student) {
            return response()->json(['message' => 'Invalid login credentials.'], 422);
        }

        // Bind the student's own organisation BEFORE minting the session, so the
        // session row is stamped with the correct tenant_id (via TenantAware) and
        // every later portal request (me/dashboard) resolves the right tenant.
        $this->bindTenantFromModel($student);

        $token = Str::random(80);

        LmsSession::query()->create([
            'role' => 'student',
            'user_id' => $student->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['token' => $token, 'tenant' => $this->currentTenantPayload()]);
    }

    /**
     * Resolve the student for these credentials. A student's email is unique
     * only per-tenant (the same person may exist under several institutes), so:
     *  - when the request carries an explicit institute (subdomain / header /
     *    ?org=), the global TenantScope already limits the lookup to it;
     *  - otherwise (bare domain / local dev) search across institutes and
     *    authenticate whichever same-email account's password matches — newest
     *    first — so a non-primary student can still log in without a subdomain.
     */
    private function authenticateStudent(string $email, string $password): ?LmsStudent
    {
        if (app()->bound('requestedTenantSlug')) {
            $student = LmsStudent::query()->where('email', $email)->first();

            return $student && Hash::check($password, $student->password) ? $student : null;
        }

        $candidates = LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($password, $candidate->password)) {
                return $candidate;
            }
        }

        return null;
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate(['email' => ['required', 'email']]);

        // Prefer the explicitly-requested institute (mirrors login); otherwise
        // fall back across institutes so a student on the wrong portal or the
        // bare domain still gets helped. bindTenantFromModel then stamps the
        // token — and the emailed link — with the account's OWN institute, so
        // the reset and the subsequent login both stay on it.
        $email = $validated['email'];

        $student = app()->bound('requestedTenantSlug')
            ? LmsStudent::query()->where('email', $email)->first()
            : null;

        if (! $student) {
            $student = LmsStudent::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('email', $email)
                ->orderByDesc('id')
                ->first();
        }

        if (! $student) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

        $this->bindTenantFromModel($student);

        $token = $this->createPasswordResetToken('student', $student->email);
        $link = $this->buildResetLink('student', $student->email, $token);

        Mail::to($student->email)->send(new LmsPasswordResetMail($student->first_name, 'Student Portal', $link));

        return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // The token row carries the issuing institute; bind it so the account
        // lookup resolves the right tenant even on the bare domain.
        $reset = $this->resolveResetToken('student', $validated['email'], $validated['token']);

        if (! $reset) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $this->bindTenantFromModel($reset);

        $student = LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $validated['email'])
            ->when($reset->tenant_id, fn ($q) => $q->where('tenant_id', $reset->tenant_id))
            ->orderByDesc('id')
            ->first();

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->update(['password' => Hash::make($validated['password'])]);

        $this->consumeResetToken('student', $validated['email'], $validated['token']);

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
