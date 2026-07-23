<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsPasswordResetMail;
use App\Models\BatchAnnouncement;
use App\Models\LmsModule;
use App\Models\LmsSession;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class StaffAuthController extends BaseLmsController
{
    public function login(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $teacher = LmsTeacher::query()->where('email', $validated['email'])->first();

        if (! $teacher || ! Hash::check($validated['password'], $teacher->password)) {
            return response()->json(['message' => 'Invalid login credentials.'], 422);
        }

        $token = \Illuminate\Support\Str::random(80);

        LmsSession::query()->create([
            'role' => 'staff',
            'user_id' => $teacher->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['token' => $token]);
    }

    public function me(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        return response()->json([
            'id' => $teacher->id,
            'name' => $teacher->name,
            'role' => $teacher->role ?? 'Instructor',
            'email' => $teacher->email,
            'profile_photo_url' => $teacher->profile_photo_url,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        $tracks = \App\Models\LmsTrack::query()->where('instructor_id', $teacher->id)->get();
        $trackIds = $tracks->pluck('id');

        $courseIds = $tracks->pluck('course_id')->filter();

        $students = \App\Models\LmsEnrollment::query()
            ->whereIn('track_id', $trackIds)
            ->with('student')
            ->get()
            ->pluck('student');

        $upcomingClasses = \App\Models\LmsClassroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        $classes = \App\Models\LmsClassroom::query()
            ->where('teacher_id', $teacher->id)
            ->with('course:id,title')
            ->orderBy('starts_at', 'desc')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'course_title' => $c->course?->title,
                'starts_at' => $c->starts_at,
                'ends_at' => $c->ends_at,
                'students_count' => \App\Models\LmsAttendanceRecord::where('classroom_id', $c->id)->count(),
            ]);

        $modules = \App\Models\LmsModule::query()
            ->whereHas('course', fn($q) => $q->whereIn('id', $courseIds))
            ->get();

        $scheduledClasses = \App\Models\LmsScheduledClass::query()
            ->where('teacher_id', $teacher->id)
            ->get();

        $upcomingScheduled = \App\Models\LmsScheduledClass::query()
            ->where('teacher_id', $teacher->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        $batchIds = $tracks->pluck('batch_id')->filter()->unique();

        $announcements = BatchAnnouncement::query()
            ->whereIn('batch_id', $batchIds)
            ->with('batch')
            ->orderBy('created_at', 'desc')
            ->get();

        $taskIds = LmsTask::query()->whereIn('course_id', $courseIds)->pluck('id');

        $pendingSubmissions = LmsTaskSubmission::query()
            ->whereIn('task_id', $taskIds)
            ->whereNotNull('submitted_at')
            ->whereNull('graded_at')
            ->count();

        return response()->json([
            'teacher' => ['name' => $teacher->name, 'email' => $teacher->email],
            'tracks' => $tracks->count(),
            'students' => $students->count(),
            'modules' => $modules->count(),
            'modules_published' => $modules->where('status', 'published')->count(),
            'upcoming_classes' => $upcomingClasses->count(),
            'upcoming_scheduled' => $upcomingScheduled->count(),
            'scheduled_classes' => $scheduledClasses->count(),
            'classes' => $classes,
            'pending_submissions' => $pendingSubmissions,
            'modules_list' => LmsModule::query()
                ->whereHas('course', fn($q) => $q->whereIn('id', $courseIds))
                ->with('course:id,title')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'course' => $m->course ? ['id' => $m->course->id, 'title' => $m->course->title] : null,
                    'status' => $m->status,
                    'sort_order' => $m->sort_order,
                ]),
            'announcements' => $announcements->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'created_at' => $a->created_at->toIso8601String(),
            ]),
            'track_info' => $tracks->first() ? [
                'id' => $tracks->first()->id,
                'name' => $tracks->first()->name,
                'batch_id' => $tracks->first()->batch_id,
            ] : null,
            'upcoming_classes_list' => $upcomingScheduled->take(5)->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'module_title' => $c->module?->title,
                'starts_at' => $c->starts_at->toIso8601String(),
                'meeting_url' => $c->meeting_url,
            ]),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate(['email' => ['required', 'email']]);

        $teacher = LmsTeacher::query()->where('email', $validated['email'])->first();

        if (! $teacher) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

        $token = $this->createPasswordResetToken('staff', $teacher->email);
        $link = $this->buildResetLink('staff', $teacher->email, $token);

        Mail::to($teacher->email)->send(new LmsPasswordResetMail($teacher->name, 'Staff Portal', $link));

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

        if (! $this->isValidResetToken('staff', $validated['email'], $validated['token'])) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $teacher = LmsTeacher::query()->where('email', $validated['email'])->first();

        if (! $teacher) {
            return response()->json(['message' => 'Staff not found.'], 404);
        }

        $teacher->update(['password' => Hash::make($validated['password'])]);

        $this->consumeResetToken('staff', $validated['email'], $validated['token']);

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
