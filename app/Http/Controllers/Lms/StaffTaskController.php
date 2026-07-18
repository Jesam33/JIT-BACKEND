<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsNotification;
use App\Models\LmsSession;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffTaskController extends BaseLmsController
{
    public function createTask(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'module_id' => ['required', 'integer', 'exists:lms_modules,id'],
            'track_id' => ['nullable', 'integer', 'exists:lms_tracks,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'submission_type' => ['required', 'in:link,file_upload'],
        ]);

        $validated['teacher_id'] = $session->user_id;

        $task = LmsTask::query()->create($validated);

        $enrollments = \App\Models\LmsEnrollment::query()
            ->whereHas('track', fn ($q) => $q->where('course_id', $validated['course_id']))
            ->with('student')
            ->get();

        foreach ($enrollments as $enrollment) {
            if ($enrollment->student) {
                LmsNotification::query()->create([
                    'student_id' => $enrollment->student->id,
                    'type' => 'new_task',
                    'title' => 'New Task: ' . $validated['title'],
                    'body' => 'A new task has been assigned to you.',
                    'reference_type' => 'task',
                    'reference_id' => $task->id,
                ]);
            }
        }

        return response()->json($task, 201);
    }

    public function gradeSubmission(Request $request, int $taskId, int $submissionId): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission = LmsTaskSubmission::query()
            ->where('task_id', $taskId)
            ->findOrFail($submissionId);

        $submission->update([
            'score' => $validated['score'],
            'feedback' => $validated['feedback'] ?? null,
            'graded_by_teacher_id' => $session->user_id,
            'graded_at' => now(),
        ]);

        $task = LmsTask::query()->find($taskId);

        if ($task && $submission->student_id) {
            LmsNotification::query()->create([
                'student_id' => $submission->student_id,
                'type' => 'task_graded',
                'title' => 'Task Graded: ' . $task->title,
                'body' => 'Your submission has been graded. Score: ' . $validated['score'],
                'reference_type' => 'task',
                'reference_id' => $task->id,
            ]);
        }

        return response()->json([
            'message' => 'Submission graded.',
            'submission' => [
                'id' => $submission->id,
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'status' => $submission->status,
            ],
        ]);
    }
}
