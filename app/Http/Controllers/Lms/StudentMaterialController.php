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
                // Externally-hosted video (Bunny Stream) pointers — present only for
                // videos uploaded to Bunny; file_url is the player embed URL. Lets the
                // student gallery show a thumbnail + play into an iframe (not <video>).
                'provider' => $m->provider,
                'external_id' => $m->external_id,
                'thumbnail_url' => $m->thumbnail_url,
                'duration_seconds' => $m->duration_seconds,
                'status' => $m->status,
            ]);

        return response()->json($materials);
    }
}
