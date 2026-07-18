<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMaterialController extends BaseLmsController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = \App\Models\LmsStudent::find($session->user_id);
        if (! $student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $session->user_id)->first();
        $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;

        if (! $courseId) {
            return response()->json([]);
        }

        $materials = LmsMaterial::query()
            ->where('course_id', $courseId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'course_id' => $m->course_id,
                'title' => $m->title,
                'file_url' => $m->file_url,
                'type' => $m->type,
                'session_id' => $m->session_id,
            ]);

        return response()->json($materials);
    }
}
