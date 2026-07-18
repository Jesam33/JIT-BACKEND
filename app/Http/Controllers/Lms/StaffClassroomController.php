<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsAttendance;
use App\Models\LmsAttendanceEvent;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
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

        $classroom = LmsClassroom::query()->findOrFail($id);

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
}
