<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsNotification;
use App\Models\LmsSession;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StaffTaskController extends BaseLmsController
{
    // Courses the teacher has a cohort (LmsTrack) on — the same scope
    // listTasks/showTask in StaffPortalController use, so a staffer can never
    // touch another instructor's task by guessing its id.
    private function getCourseIds(LmsTeacher $teacher): array
    {
        return LmsTrack::query()
            ->where('instructor_id', $teacher->id)
            ->pluck('course_id')
            ->filter()
            ->toArray();
    }

    // Task attachments (up to 5 files students download while working on the
    // task). The bytes are already on the public disk by the time this runs
    // (StaffPortalController::uploadMaterialFile); here we only validate the
    // pointers. Normalized to exactly these keys so junk fields never land in
    // the JSON column.
    private function validateAttachments(Request $request): ?array
    {
        $validated = $request->validate([
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.url' => ['required', 'string', 'max:2048'],
            'attachments.*.path' => ['nullable', 'string', 'max:255'],
            'attachments.*.size' => ['nullable', 'integer'],
        ]);

        if (! array_key_exists('attachments', $validated)) {
            return null;
        }

        return array_map(fn ($a) => [
            'name' => $a['name'],
            'url' => $a['url'],
            'path' => $a['path'] ?? null,
            'size' => $a['size'] ?? null,
        ], $validated['attachments']);
    }

    // Delete the public-disk file behind every attachment that carries one
    // (externally hosted pointers are left alone).
    private function deleteAttachmentFiles(?array $attachments): void
    {
        foreach ($attachments ?? [] as $attachment) {
            if (! empty($attachment['path'])) {
                Storage::disk('public')->delete($attachment['path']);
            }
        }
    }

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
            'description' => ['required', 'string', 'max:5000'],
            'instructions' => ['required', 'string', 'max:10000'],
            'due_at' => ['required', 'date'],
            'submission_type' => ['required', 'in:link,file_upload'],
        ]);

        $validated['teacher_id'] = $session->user_id;
        $validated['attachments'] = $this->validateAttachments($request);

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

    public function updateTask(Request $request, int $taskId): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->find($session->user_id);
        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Scoped to the teacher's assigned courses (same as listTasks/
        // showTask): a task outside them resolves to 404, never 403, so
        // guessing ids reveals nothing.
        $task = LmsTask::query()
            ->whereIn('course_id', $this->getCourseIds($teacher))
            ->findOrFail($taskId);

        $validated = $request->validate([
            // course_id is immutable on edit: accepting a new one would let a
            // staffer move a task onto a course they don't teach, so only
            // validate it matches the task's own course.
            'course_id' => ['nullable', 'integer', Rule::in([$task->course_id])],
            'module_id' => ['required', 'integer', 'exists:lms_modules,id'],
            'track_id' => ['nullable', 'integer', 'exists:lms_tracks,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'instructions' => ['required', 'string', 'max:10000'],
            'due_at' => ['required', 'date'],
            'submission_type' => ['required', 'in:link,file_upload'],
        ]);

        $attachments = $this->validateAttachments($request);

        // Files dropped from the attachment list die with the edit (the same
        // cleanup deleteMaterial does); newly added ones were already uploaded
        // by the client. Diffing by path, never by url — external pointers
        // carry no path and are left alone either way.
        if ($attachments !== null) {
            $keptPaths = collect($attachments)->pluck('path')->filter()->all();
            foreach ($task->attachments ?? [] as $old) {
                if (! empty($old['path']) && ! in_array($old['path'], $keptPaths, true)) {
                    Storage::disk('public')->delete($old['path']);
                }
            }
        }

        $task->update([
            'module_id' => $validated['module_id'],
            'track_id' => $validated['track_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'submission_type' => $validated['submission_type'],
            'attachments' => $attachments,
        ]);

        return response()->json($task);
    }

    public function deleteTask(Request $request, int $taskId): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->find($session->user_id);
        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $task = LmsTask::query()
            ->whereIn('course_id', $this->getCourseIds($teacher))
            ->findOrFail($taskId);

        // The uploaded files die with the task; submissions cascade via the
        // task_id FK.
        $this->deleteAttachmentFiles($task->attachments);
        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    // Every submission across the teacher's tasks — the backing for the staff
    // Submissions tab. Scoping is by course (same as listTasks/showTask), then
    // the task relationship carries the title the row groups under.
    public function submissions(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->find($session->user_id);
        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $submissions = LmsTaskSubmission::query()
            ->whereHas('task', fn ($q) => $q->whereIn('course_id', $this->getCourseIds($teacher)))
            ->with(['student', 'task:id,title,course_id,due_at,submission_type'])
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json($submissions);
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
