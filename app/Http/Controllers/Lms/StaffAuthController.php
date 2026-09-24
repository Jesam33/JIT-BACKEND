<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsPasswordResetMail;
use App\Models\BatchAnnouncement;
use App\Models\LmsModule;
use App\Models\LmsSession;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTeacher;
use App\Scopes\TenantScope;
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

        $teacher = $this->authenticateTeacher($validated['email'], $validated['password']);

        if (! $teacher) {
            return response()->json(['message' => 'Invalid login credentials.'], 422);
        }

        // A deactivated staff member keeps their record (and any cohort they lead)
        // but cannot sign in, the reversible counterpart to removal, toggled by the
        // owner on the Staff Accounts page or by the staffer themselves on their
        // profile. A scheduled deletion is the same refusal with a different
        // message, so the sign-in form can say which one it is.
        $state = $teacher->lifecycleState();

        if ($state !== 'active') {
            $payload = ['account_deactivated' => false, 'account_deletion_scheduled' => false];

            $payload['message'] = match ($state) {
                'purged' => 'This account has been deleted.',
                'purge_scheduled' => 'This account is scheduled for deletion. Please contact your institute admin to cancel it.',
                default => 'Your staff account has been deactivated. Please contact your institute admin.',
            };

            if ($state === 'purge_scheduled') {
                $payload['account_deletion_scheduled'] = true;
                $payload['purge_after'] = $teacher->purge_after;
            } elseif ($state === 'deactivated') {
                $payload['account_deactivated'] = true;
            }

            return response()->json($payload, 403);
        }

        // Bind the teacher's own organisation before minting the session, so the
        // session is stamped with the right tenant_id and portal reads resolve.
        $this->bindTenantFromModel($teacher);

        $token = \Illuminate\Support\Str::random(80);

        LmsSession::query()->create([
            'role' => 'staff',
            'user_id' => $teacher->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
            ...LmsSession::requestMeta($request),
        ]);

        // Warn the staffer if this machine is new to them. Never throws, and
        // deliberately not awaited on success — see LoginDeviceTracker.
        \App\Support\LoginDeviceTracker::record('staff', $teacher->id, $teacher->email, $teacher->name, $request);

        return response()->json(['token' => $token, 'tenant' => $this->currentTenantPayload()]);
    }

    /**
     * Resolve the teacher for these credentials. A staff email is unique only
     * per-tenant, so scope to an explicitly-requested institute when present,
     * else authenticate whichever same-email account's password matches (newest
     * first) so a non-primary teacher can log in on the bare domain. Mirrors
     * StudentAuthController::authenticateStudent.
     */
    private function authenticateTeacher(string $email, string $password): ?LmsTeacher
    {
        if (app()->bound('requestedTenantSlug')) {
            $teacher = LmsTeacher::query()->where('email', $email)->first();

            return $teacher && Hash::check($password, $teacher->password) ? $teacher : null;
        }

        $candidates = LmsTeacher::query()
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

    public function me(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        // The academy owner's mirror is a valid staff actor too (full parity), so
        // `me` answers for it as well: the owner shell mounts the staff pages and
        // they bootstrap from here.
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Never surface the mirror's synthetic address: an owner signs in with the
        // account email below.
        $owner = $actor->isAcademyOwner() ? $this->ownerFromRequest($request) : null;

        return response()->json([
            'id' => $actor->id,
            'name' => $actor->name,
            'role' => $actor->role ?? 'Instructor',
            'email' => $owner?->email ?? $actor->email,
            'profile_photo_url' => $actor->profile_photo_url,
            // Tells the staff shell it is really the owner acting academy-wide.
            'academy_owner' => $actor->isAcademyOwner(),
            // RBAC, read-only here: the role preset this staffer holds and the
            // exact sections it grants. Sent as a list rather than as the role key
            // so the sidebar filters on the same names the server enforces with —
            // one source of truth (App\Support\StaffPermissions), no client-side
            // copy of the role → section map to drift out of step.
            'staff_role' => $actor->staffRole(),
            'sections' => $actor->allowedSections(),
            'plan' => $this->planForSession($session),
            // Per-feature gates for the staff shell (the sidebar shows
            // "Create with AI" only when the academy's plan includes it).
            'ai_materials' => $this->featureForSession($session, 'ai_materials'),
            // Lets the staff shell re-pin its `tenant` cookie from this
            // authenticated session so the inactivity → login redirect keeps
            // the institute instead of falling back to the primary slug.
            'tenant' => $this->tenantPayloadForSession($session),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Actor-aware scope: a staffer sees their own cohorts/classes, the owner's
        // mirror sees the whole academy (BaseLmsController::actorTrackIds et al).
        $tracks = \App\Models\LmsTrack::query()->whereIn('id', $this->actorTrackIds($actor))->get();
        $trackIds = $tracks->pluck('id');

        $courseIds = $this->actorCourseIds($actor);

        $students = \App\Models\LmsEnrollment::query()
            ->whereIn('track_id', $trackIds)
            ->with('student')
            ->get()
            ->pluck('student');

        $classroomIds = $this->actorClassroomIds($actor);

        $upcomingClasses = \App\Models\LmsClassroom::query()
            ->whereIn('id', $classroomIds)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        $classrooms = \App\Models\LmsClassroom::query()
            ->whereIn('id', $classroomIds)
            ->with('course:id,title')
            ->orderBy('starts_at', 'desc')
            ->get();

        // One grouped count instead of a COUNT query per classroom.
        $attendanceCounts = \App\Models\LmsAttendanceRecord::query()
            ->whereIn('classroom_id', $classrooms->pluck('id'))
            ->selectRaw('classroom_id, COUNT(*) as aggregate')
            ->groupBy('classroom_id')
            ->pluck('aggregate', 'classroom_id');

        $classes = $classrooms
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'course_title' => $c->course?->title,
                'starts_at' => $c->starts_at,
                'ends_at' => $c->ends_at,
                'students_count' => (int) ($attendanceCounts[$c->id] ?? 0),
            ]);

        $modules = \App\Models\LmsModule::query()
            ->whereHas('course', fn($q) => $q->whereIn('id', $courseIds))
            ->get();

        $scheduledClassIds = $this->actorScheduledClassIds($actor);

        $scheduledClasses = \App\Models\LmsScheduledClass::query()
            ->whereIn('id', $scheduledClassIds)
            ->get();

        $upcomingScheduled = \App\Models\LmsScheduledClass::query()
            ->whereIn('id', $scheduledClassIds)
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
            'teacher' => [
                'name' => $actor->name,
                // Real account email when the owner is acting; never the mirror's
                // synthetic internal address.
                'email' => ($actor->isAcademyOwner() ? $this->ownerFromRequest($request)?->email : null) ?? $actor->email,
            ],
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

        // Prefer the explicitly-requested institute (a staffer resetting from
        // their own portal), mirrors login. Otherwise fall back across
        // institutes so someone who landed on the wrong portal, or is on the
        // bare domain, still gets helped. Either way bindTenantFromModel stamps
        // the token, and the emailed link, with the account's OWN institute,
        // so the reset and the subsequent login both stay on it.
        $email = $validated['email'];

        $teacher = app()->bound('requestedTenantSlug')
            ? LmsTeacher::query()->where('email', $email)->first()
            : null;

        if (! $teacher) {
            $teacher = LmsTeacher::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('email', $email)
                ->orderByDesc('id')
                ->first();
        }

        if (! $teacher) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

        $this->bindTenantFromModel($teacher);

        $token = $this->createPasswordResetToken('staff', $teacher->email);
        $link = $this->buildResetLink('staff', $teacher->email, $token);

        $brand = $this->mailBranding();
        Mail::to($teacher->email)->send(new LmsPasswordResetMail($teacher->name, 'Staff Portal', $link, $brand['name'], $brand['color'], $brand['reply_to'], (bool) ($brand['is_platform'] ?? false)));

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
        // lookup resolves the right tenant even on the bare domain. This same
        // endpoint backs both the reset page and the staff activation (setup)
        // page, an owner-invited teacher's link lands here.
        $reset = $this->resolveResetToken('staff', $validated['email'], $validated['token']);

        if (! $reset) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $this->bindTenantFromModel($reset);

        $teacher = LmsTeacher::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $validated['email'])
            ->when($reset->tenant_id, fn ($q) => $q->where('tenant_id', $reset->tenant_id))
            ->orderByDesc('id')
            ->first();

        if (! $teacher) {
            return response()->json(['message' => 'Staff not found.'], 404);
        }

        $teacher->update(['password' => Hash::make($validated['password'])]);

        $this->consumeResetToken('staff', $validated['email'], $validated['token']);

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
