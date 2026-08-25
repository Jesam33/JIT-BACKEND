<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsAttendance;
use App\Models\LmsAttendanceEvent;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
use App\Models\LmsScheduledClass;
use App\Models\LmsTeacher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StaffClassroomController extends BaseLmsController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classrooms = LmsClassroom::query()
            ->where('teacher_id', $session->user_id)
            ->with(['course:id,title', 'teacher:id,name'])
            ->orderBy('starts_at', 'desc')
            ->get();

        return response()->json($classrooms);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->where('teacher_id', $session->user_id)
            ->with(['course:id,title', 'teacher:id,name'])
            ->findOrFail($id);

        return response()->json($classroom);
    }

    public function createClassroom(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'meeting_url' => ['nullable', 'string', 'max:2048'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:64'],
            'session_thumbnail_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $validated['teacher_id'] = $session->user_id;
        $this->maybeFillPasscode($validated);

        $classroom = LmsClassroom::query()->create($validated);

        return response()->json($classroom, 201);
    }

    public function updateClassroom(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->where('teacher_id', $session->user_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
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

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()
            ->where('teacher_id', $session->user_id)
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

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cfg = $this->jitsiConfig();

        if (! $cfg) {
            return response()->json(['message' => 'Live classes are not configured yet.'], 503);
        }

        $classType = $request->input('class_type', 'classroom');

        if ($classType === 'scheduled') {
            $model = LmsScheduledClass::query()
                ->where('teacher_id', $session->user_id)
                ->findOrFail($id);
            $room = $this->ensureRoom($model, 'scheduled');
        } else {
            $model = LmsClassroom::query()
                ->where('teacher_id', $session->user_id)
                ->findOrFail($id);
            $room = $this->ensureRoom($model, 'classroom');
        }

        $teacher = LmsTeacher::query()->find($session->user_id);
        $userName = $teacher?->name ?: 'Instructor';

        $jwt = $this->mintJaasToken($cfg, $room, [
            'id' => 'teacher-' . $session->user_id,
            'name' => $userName,
            'email' => $teacher?->email ?? '',
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
