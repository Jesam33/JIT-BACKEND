<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsAttendance;
use App\Models\LmsAttendanceEvent;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
use App\Models\LmsNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsTeacher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class StaffClassroomController extends BaseLmsController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classrooms = LmsClassroom::query()
            // Actor-aware: a staffer sees the classrooms they host, the owner's
            // academy-wide mirror sees every classroom in the academy.
            ->whereIn('id', $this->actorClassroomIds($actor))
            ->with(['course:id,title', 'teacher:id,name'])
            ->orderBy('starts_at', 'desc')
            ->get();

        return response()->json($classrooms);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->whereIn('id', $this->actorClassroomIds($actor))
            ->with(['course:id,title', 'teacher:id,name'])
            ->findOrFail($id);

        return response()->json($classroom);
    }

    public function createClassroom(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            // Bounded to the actor's own courses, not just `exists`: that rule runs
            // on the raw query builder, so global scopes don't apply and it would
            // accept another academy's course id.
            'course_id' => ['required', 'integer', Rule::in($this->actorCourseIds($actor))],
            'teacher_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            // A live class carries BOTH a start and an end time. The end time is
            // the scheduled window shown to students (join closes after it), NOT
            // an automatic cut-off: the call itself runs until the host ends it.
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'meeting_url' => ['nullable', 'string', 'max:2048'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:64'],
            'session_thumbnail_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $validated['teacher_id'] = $actor->id;
        $this->maybeFillPasscode($validated);

        $classroom = LmsClassroom::query()->create($validated);

        // Notify every student in the course: a live class with no attendees is
        // the whole feature silently failing. Mirrors the fan-out in
        // StaffModuleController::scheduleClass (module scheduled classes); the
        // email sweep (lms:send-notification-emails) picks these up like any other
        // notification. The audience is the COURSE, not the enrollment rows: a
        // student who chose this course but has no cohort placement on record yet
        // receives nothing from an enrollment-only fan-out (see
        // BaseLmsController::studentIdsInCourses). The meeting password is
        // deliberately NOT included — students join in-portal via a minted JWT,
        // they never type the passcode.
        $studentIds = $this->studentIdsInCourses(array_filter([$classroom->course_id]));

        foreach ($studentIds as $studentId) {
            LmsNotification::create([
                'student_id' => $studentId,
                'type' => 'class_scheduled',
                'title' => 'New live class: ' . $classroom->title,
                'body' => 'A live class "' . $classroom->title . '" has been scheduled for ' . $classroom->starts_at . '. Join it from your Classroom page.',
                'reference_type' => 'classroom',
                'reference_id' => $classroom->id,
            ]);
        }

        return response()->json($classroom, 201);
    }

    public function updateClassroom(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->whereIn('id', $this->actorClassroomIds($actor))
            ->findOrFail($id);

        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', Rule::in($this->actorCourseIds($actor))],
            'teacher_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'meeting_url' => ['nullable', 'string', 'max:2048'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:64'],
            'recording_url' => ['nullable', 'string', 'max:2048'],
            'session_thumbnail_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $this->maybeFillPasscode($validated);
        $classroom->update($validated);

        return response()->json($classroom);
    }

    public function deleteClassroom(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->whereIn('id', $this->actorClassroomIds($actor))
            ->findOrFail($id);

        $classroom->delete();

        return response()->json(['message' => 'Classroom deleted.']);
    }

    /**
     * Mint a MODERATOR 8x8 JaaS token so the instructor can host the live class.
     * Handles both live-class models via a `class_type` param (mirrors the student
     * endpoint). Returns everything the embed/new-tab launcher needs; 503 when JaaS
     * credentials are not configured yet (graceful degradation, no broken button).
     */
    public function meetingToken(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Live classes are a paid-plan feature. Gate on the bound tenant's plan
        // (ResolveTenantFromSession bound it from the bearer token) BEFORE the
        // platform config check, so a free institute is told to upgrade rather
        // than shown a misleading "not configured". The primary institute, and
        // any tier that includes live_classes, passes through.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if (! $tenant || ! $tenant->planFeature('live_classes')) {
            return response()->json([
                'message' => 'Live classes are a paid-plan feature. Upgrade your plan to host live sessions.',
                'feature' => 'live_classes',
                'upgrade_required' => true,
            ], 402);
        }

        $cfg = $this->jitsiConfig();

        if (! $cfg) {
            return response()->json(['message' => 'Live classes are not configured yet.'], 503);
        }

        $classType = $request->input('class_type', 'classroom');

        if ($classType === 'scheduled') {
            $model = LmsScheduledClass::query()
                ->whereIn('id', $this->actorScheduledClassIds($actor))
                ->findOrFail($id);
            $room = $this->ensureRoom($model, 'scheduled');
        } else {
            $model = LmsClassroom::query()
                ->whereIn('id', $this->actorClassroomIds($actor))
                ->findOrFail($id);
            $room = $this->ensureRoom($model, 'classroom');
        }

        $userName = $actor->name ?: 'Instructor';

        $jwt = $this->mintJaasToken($cfg, $room, [
            'id' => 'teacher-' . $actor->id,
            'name' => $userName,
            'email' => $actor->email ?? '',
        ], true);

        return response()->json([
            'room' => $room,
            'jwt' => $jwt,
            'domain' => $cfg['domain'],
            'app_id' => $cfg['appId'],
            'user_name' => $userName,
            'moderator' => true,
        ]);
    }
}
