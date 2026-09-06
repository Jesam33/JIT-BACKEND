<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsModule;
use App\Models\LmsScheduledClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentModuleController extends BaseLmsController
{
    /**
     * Return the ids (as a flipped set for O(1) lookup) of the given modules
     * that already have at least one delivered (past, non-cancelled) class, 
     * one query instead of an exists() per module.
     */
    private function taughtModuleIds(\Illuminate\Support\Collection $moduleIds): \Illuminate\Support\Collection
    {
        return LmsScheduledClass::query()
            ->whereIn('module_id', $moduleIds)
            ->where('starts_at', '<', now())
            ->where('status', '!=', 'cancelled')
            ->distinct()
            ->pluck('module_id')
            ->flip();
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) return response()->json(['message' => 'Unauthorized'], 401);

        $student = \App\Models\LmsStudent::find($session->user_id);
        if (! $student) return response()->json(['message' => 'Student not found'], 404);

        $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $session->user_id)->first();
        $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;
        if (! $courseId) return response()->json([]);

        $allModules = LmsModule::with('contents')
            ->where('course_id', $courseId)
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->get();

        $taughtModuleIds = $this->taughtModuleIds($allModules->pluck('id'));

        $modules = [];
        $previousTaught = true;

        foreach ($allModules as $module) {
            if (! $previousTaught) break;

            $modules[] = $module;
            $previousTaught = $taughtModuleIds->has($module->id);
        }

        return response()->json($modules);
    }

    public function show(int $id): JsonResponse
    {
        $module = LmsModule::with('contents')
            ->where('id', $id)
            ->where('status', 'published')
            ->firstOrFail();

        $prevModules = LmsModule::where('course_id', $module->course_id)
            ->where('status', 'published')
            ->where('sort_order', '<', $module->sort_order)
            ->orderBy('sort_order')
            ->get();

        if ($prevModules->isNotEmpty()) {
            $taughtModuleIds = $this->taughtModuleIds($prevModules->pluck('id'));

            foreach ($prevModules as $prev) {
                if (! $taughtModuleIds->has($prev->id)) {
                    return response()->json(['message' => 'Previous module not yet taught.'], 403);
                }
            }
        }

        return response()->json($module);
    }

    public function timetable(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) return response()->json(['message' => 'Unauthorized'], 401);

        $student = \App\Models\LmsStudent::find($session->user_id);
        if (! $student) return response()->json(['message' => 'Student not found'], 404);

        $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $session->user_id)->first();
        $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;

        if (! $courseId) {
            return response()->json([]);
        }

        $oldClasses = \App\Models\LmsClassroom::query()
            ->where('course_id', $courseId)
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'class_type' => 'classroom',
                'title' => $c->title,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'meeting_id' => $c->meeting_id,
                'meeting_url' => $c->meeting_url,
                'meeting_password' => $c->meeting_password,
                'session_thumbnail_url' => $c->session_thumbnail_url,
                'recording_url' => $c->recording_url,
                'module_id' => null,
            ]);

        $scheduledClasses = LmsScheduledClass::with(['module.course'])
            ->whereHas('module', fn($q) => $q->where('course_id', $courseId))
            ->where('status', '!=', 'cancelled')
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'class_type' => 'scheduled',
                'title' => $c->title,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'meeting_id' => $c->meeting_id,
                'meeting_url' => $c->meeting_url,
                'meeting_password' => $c->meeting_password,
                'session_thumbnail_url' => null,
                'recording_url' => null,
                'module_id' => $c->module_id,
            ]);

        $timetable = $oldClasses->concat($scheduledClasses)->sortBy('starts_at')->values();

        return response()->json($timetable);
    }
}
