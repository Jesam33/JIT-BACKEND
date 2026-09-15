<?php

namespace App\Support;

use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTrack;

/**
 * Who has "completed" a cohort, for the ended-cohort certificate flow.
 *
 * Completion follows the same rule the student dashboard has always used: a
 * module counts as completed when its task is graded at >= 70%; a student has
 * completed the cohort when every module of the course is completed. Courses
 * with no modules have no completion signal — nobody is "completed" there, and
 * the owner ticks students manually in the issue panel.
 *
 * Batched on purpose: two queries per cohort regardless of cohort size, never
 * one per student (the lesson from the dashboard N+1 pass).
 */
class CohortCompletion
{
    /**
     * Per-student module completion for one cohort.
     *
     * @return array{
     *     modules_total: int,
     *     per_student: array<int,int>,  // student_id => completed module count
     * }
     */
    public static function forTrack(LmsTrack $track): array
    {
        $courseId = $track->course_id;
        if (! $courseId) {
            return ['modules_total' => 0, 'per_student' => []];
        }

        $modulesTotal = (int) \App\Models\LmsModule::query()->where('course_id', $courseId)->count();
        if ($modulesTotal === 0) {
            return ['modules_total' => 0, 'per_student' => []];
        }

        $studentIds = $track->enrollments()->pluck('student_id')->unique()->values();
        if ($studentIds->isEmpty()) {
            return ['modules_total' => $modulesTotal, 'per_student' => []];
        }

        // module_id by task_id for this course's module-linked tasks…
        $taskModules = LmsTask::query()
            ->where('course_id', $courseId)
            ->whereNotNull('module_id')
            ->pluck('module_id', 'id');

        if ($taskModules->isEmpty()) {
            return ['modules_total' => $modulesTotal, 'per_student' => $studentIds->mapWithKeys(fn ($id) => [$id => 0])->all()];
        }

        // …then one pass over the passing submissions to count DISTINCT modules
        // per student.
        $passes = LmsTaskSubmission::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('task_id', $taskModules->keys())
            ->where('score', '>=', 70)
            ->get(['student_id', 'task_id']);

        $perStudent = $studentIds->mapWithKeys(fn ($id) => [$id => 0])->all();
        $seen = [];
        foreach ($passes as $p) {
            $moduleId = $taskModules[$p->task_id] ?? null;
            if ($moduleId === null) {
                continue;
            }
            $key = $p->student_id . ':' . $moduleId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $perStudent[$p->student_id] = ($perStudent[$p->student_id] ?? 0) + 1;
        }

        return ['modules_total' => $modulesTotal, 'per_student' => $perStudent];
    }

    /** Whether the given per-student count completes the cohort. */
    public static function isCompleted(int $modulesTotal, int $completedModules): bool
    {
        return $modulesTotal > 0 && $completedModules >= $modulesTotal;
    }
}
