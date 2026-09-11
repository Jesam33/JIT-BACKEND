<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsAttendance;
use App\Models\LmsCourse;
use App\Models\LmsCourseReview;
use App\Models\LmsMaterial;
use App\Models\LmsNotification;
use App\Models\LmsTeacherNotification;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentDashboardController extends BaseLmsController
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $studentId = $session->user_id;
        $student = LmsStudent::query()->findOrFail($studentId);

        $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $studentId)->first();
        $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;

        $courseId = $student->selected_course_id ?? $track?->course_id;
        $course = $courseId ? LmsCourse::query()->find($courseId) : null;

        // Delivered classes across BOTH delivery types: legacy course classrooms
        // and module-based scheduled classes. Only classes that have already
        // started (and weren't cancelled) count toward the rate, so future
        // classes don't drag it down before they happen.
        $classroomIds = collect();
        $scheduledIds = collect();
        if ($course) {
            $classroomIds = \App\Models\LmsClassroom::query()
                ->where('course_id', $course->id)
                ->where('starts_at', '<=', now())
                ->pluck('id');

            $scheduledIds = \App\Models\LmsScheduledClass::query()
                ->whereHas('module', fn($q) => $q->where('course_id', $course->id))
                ->where('status', '!=', 'cancelled')
                ->where('starts_at', '<=', now())
                ->pluck('id');
        }

        $classesTotal = $classroomIds->count() + $scheduledIds->count();
        // A row only exists because the student joined the room, but an
        // instant join-and-leave computes to status 'absent' — that should
        // NOT count as attended. Unclosed rows (student still inside / never
        // left via the close-out) read as present, matching the attendance
        // endpoint's own semantics.
        $attendedCount = LmsAttendance::query()
            ->where('student_id', $studentId)
            ->where(fn ($q) => $q->whereNull('status')->orWhereIn('status', ['present', 'partial', 'late', 'made_up']))
            ->where(function ($q) use ($classroomIds, $scheduledIds) {
                $q->where(function ($w) use ($classroomIds) {
                    $w->where('class_type', 'classroom')->whereIn('classroom_id', $classroomIds);
                })->orWhere(function ($w) use ($scheduledIds) {
                    $w->where('class_type', 'scheduled')->whereIn('scheduled_class_id', $scheduledIds);
                });
            })
            ->count();
        $attendanceRate = $classesTotal > 0 ? round(($attendedCount / $classesTotal) * 100) : 0;

        $upcomingClass = null;
        if ($course) {
            $upcomingClassroom = \App\Models\LmsClassroom::query()
                ->where('course_id', $course->id)
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->first();

            if ($upcomingClassroom) {
                $upcomingClass = [
                    'id' => $upcomingClassroom->id,
                    'class_type' => 'classroom',
                    'title' => $upcomingClassroom->title,
                    'starts_at' => $upcomingClassroom->starts_at->toIso8601String(),
                    'ends_at' => $upcomingClassroom->ends_at?->toIso8601String(),
                    'meeting_id' => $upcomingClassroom->meeting_id,
                    'meeting_url' => $upcomingClassroom->meeting_url,
                    'join_opens_at' => optional($this->classroomJoinOpensAt($upcomingClassroom))->toIso8601String(),
                    'join_enabled' => $this->canStudentJoinClassroom($upcomingClassroom),
                ];
            }

            $upcomingScheduled = \App\Models\LmsScheduledClass::query()
                ->whereHas('module', fn($q) => $q->where('course_id', $course->id))
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->first();

            if ($upcomingScheduled) {
                $upcomingClass = [
                    'id' => $upcomingScheduled->id,
                    'class_type' => 'scheduled',
                    'title' => $upcomingScheduled->title,
                    'starts_at' => $upcomingScheduled->starts_at->toIso8601String(),
                    'ends_at' => $upcomingScheduled->ends_at?->toIso8601String(),
                    'meeting_id' => $upcomingScheduled->meeting_id,
                    'meeting_url' => $upcomingScheduled->meeting_url,
                    'join_opens_at' => $upcomingScheduled->starts_at?->subMinutes(5)->toIso8601String(),
                    'join_enabled' => $upcomingScheduled->starts_at && $upcomingScheduled->starts_at->subMinutes(5)->isPast(),
                ];
            }
        }

        $nextLesson = null;
        if ($student->learning_mode === 'pre_recorded' && $course) {
            $nextLesson = LmsMaterial::query()
                ->where('course_id', $course->id)
                ->where('type', '!=', 'link')
                ->orderBy('id')
                ->first();

            if ($nextLesson) {
                $nextLesson = [
                    'id' => $nextLesson->id,
                    'title' => $nextLesson->title,
                    'file_url' => $nextLesson->file_url,
                    'course_title' => $course?->title,
                ];
            }
        }

        $timetable = [];
        if ($course) {
            $oldClasses = \App\Models\LmsClassroom::query()
                ->where('course_id', $course->id)
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

            $scheduledClasses = \App\Models\LmsScheduledClass::query()
                ->whereHas('module', fn($q) => $q->where('course_id', $course->id))
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
        }

        $totalModules = $course ? \App\Models\LmsModule::query()->where('course_id', $course->id)->count() : 0;
        $completedModules = 0;
        if ($totalModules > 0) {
            $completedModules = \App\Models\LmsModule::query()
                ->where('course_id', $course->id)
                ->whereHas('tasks.submissions', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)
                        ->where('score', '>=', 70);
                })
                ->count();
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

        $tasks = LmsTask::query()
            ->where('course_id', $courseId)
            ->orderByDesc('created_at')
            ->get();

        // Batch-load this student's submissions once (keyed by task) instead of a
        // query per task in the map below.
        $taskSubmissions = LmsTaskSubmission::query()
            ->where('student_id', $studentId)
            ->whereIn('task_id', $tasks->pluck('id'))
            ->get()
            ->keyBy('task_id');

        $tasks = $tasks
            ->map(function (LmsTask $task) use ($taskSubmissions) {
                $submission = $taskSubmissions->get($task->id);

                return [
                    'id' => $task->id,
                    'module_id' => $task->module_id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'instructions' => $task->instructions,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'submission_type' => $task->submission_type,
                    'status' => $submission?->graded_at ? 'graded' : ($submission ? 'submitted' : 'pending'),
                    'submitted_at' => $submission?->created_at?->toIso8601String(),
                    'score' => $submission?->score,
                ];
            });

        $notifications = LmsNotification::query()
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'is_read' => (bool) $n->is_read,
                'reference_type' => $n->reference_type,
                'reference_id' => $n->reference_id,
            ]);

        // The student's own rating for their course + the course aggregate, so
        // the dashboard can render a "Rate this course" control pre-filled with
        // any existing rating. Null when the student has no resolved course.
        $rating = null;
        if ($course) {
            $agg = LmsCourseReview::query()
                ->where('course_id', $course->id)
                ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS cnt')
                ->first();
            $yours = LmsCourseReview::query()
                ->where('course_id', $course->id)
                ->where('student_id', $studentId)
                ->value('rating');

            $rating = [
                'course_id' => $course->id,
                'your_rating' => $yours !== null ? (int) $yours : null,
                'average' => round((float) ($agg->avg_rating ?? 0), 1),
                'count' => (int) ($agg->cnt ?? 0),
            ];
        }

        return response()->json([
            'profile' => [
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'email' => $student->email,
                'learning_mode' => $student->learning_mode,
                'course_title' => $course?->title,
            ],
            'summary' => [
                'classes_total' => $classesTotal,
                'classes_attended' => $attendedCount,
                'attendance_rate' => $attendanceRate,
                'modules_total' => $totalModules,
                'modules_completed' => $completedModules,
            ],
            'upcoming_class' => $upcomingClass,
            'next_lesson' => $nextLesson,
            'timetable' => $timetable,
            'materials' => $materials,
            'tasks' => $tasks,
            'notifications' => $notifications,
            'rating' => $rating,
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = LmsStudent::query()->find($session->user_id);
        $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $session->user_id)->first();
        $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student?->selected_course_id ?? $track?->course_id;

        $tasks = LmsTask::query()
            ->where('course_id', $courseId)
            ->orderByDesc('created_at')
            ->get();

        // Batch-load submissions once (keyed by task) rather than per-task.
        $taskSubmissions = LmsTaskSubmission::query()
            ->where('student_id', $session->user_id)
            ->whereIn('task_id', $tasks->pluck('id'))
            ->get()
            ->keyBy('task_id');

        $tasks = $tasks
            ->map(function (LmsTask $task) use ($taskSubmissions) {
                $submission = $taskSubmissions->get($task->id);

                return [
                    'id' => $task->id,
                    'module_id' => $task->module_id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'instructions' => $task->instructions,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'submission_type' => $task->submission_type,
                    'status' => $submission?->graded_at ? 'graded' : ($submission ? 'submitted' : 'pending'),
                    'submitted_at' => $submission?->created_at?->toIso8601String(),
                    'score' => $submission?->score,
                ];
            });

        return response()->json($tasks);
    }

    public function taskDetail(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $task = LmsTask::query()->findOrFail($id);

        $submission = LmsTaskSubmission::query()
            ->where('task_id', $task->id)
            ->where('student_id', $session->user_id)
            ->first();

        return response()->json([
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'instructions' => $task->instructions,
            'due_at' => $task->due_at?->toIso8601String(),
            'submission_type' => $task->submission_type,
            'submission' => $submission ? [
                'submitted_link' => $submission->submitted_link,
                'submitted_file_url' => $submission->submitted_file_url,
                'submitted_at' => $submission->created_at?->toIso8601String(),
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'graded_at' => $submission->graded_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function submitTask(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $task = LmsTask::query()->findOrFail($id);

        $submittedFileUrl = null;
        $submittedLink = null;

        if ($request->hasFile('submitted_file')) {
            $path = $request->file('submitted_file')->store('task-submissions', 'public');
            $submittedFileUrl = $this->publicFileUrl($path);
        } else {
            $validated = $request->validate([
                'submitted_file_url' => ['nullable', 'string', 'max:2048'],
                'submitted_link' => ['nullable', 'string', 'max:2048'],
            ]);
            $submittedFileUrl = $validated['submitted_file_url'] ?? null;
            $submittedLink = $validated['submitted_link'] ?? null;
        }

        $submission = LmsTaskSubmission::query()->updateOrCreate(
            ['task_id' => $task->id, 'student_id' => $session->user_id],
            [
                'submitted_file_url' => $submittedFileUrl,
                'submitted_link' => $submittedLink,
                'submitted_at' => now(),
            ]
        );

        $teacherId = $task->teacher_id;
        if (! $teacherId) {
            $enrollment = \App\Models\LmsEnrollment::query()->where('student_id', $session->user_id)->first();
            $track = $enrollment ? \App\Models\LmsTrack::query()->find($enrollment->track_id) : null;
            $teacherId = $track?->instructor_id;
        }
        if ($teacherId) {
            $student = LmsStudent::query()->find($session->user_id);
            $studentName = $student ? ($student->first_name . ' ' . $student->last_name) : 'A student';
            LmsTeacherNotification::query()->create([
                'teacher_id' => $teacherId,
                'type' => 'task_submission',
                'title' => 'Task Submitted: ' . $task->title,
                'body' => $studentName . ' has submitted a task.',
                'reference_type' => 'task_submission',
                'reference_id' => $submission->id,
            ]);
        }

        return response()->json([
            'message' => 'Task submitted.',
            'submission' => [
                'id' => $submission->id,
                'status' => 'submitted',
                'submitted_at' => $submission->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notifications = LmsNotification::query()
            ->where('student_id', $session->user_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'is_read' => (bool) $n->is_read,
                'reference_type' => $n->reference_type,
                'reference_id' => $n->reference_id,
            ]);

        return response()->json($notifications);
    }

    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notification = LmsNotification::query()
            ->where('id', $id)
            ->where('student_id', $session->user_id)
            ->firstOrFail();

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function attendance(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Attendance across BOTH delivery types (legacy classrooms + module
        // scheduled classes). class_id/class_type are the pair to key on in
        // the frontend; classroom_id is kept for the legacy shape only.
        $records = LmsAttendance::query()
            ->where('student_id', $session->user_id)
            ->with(['classroom', 'scheduledClass'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'class_type' => $a->class_type ?? 'classroom',
                'class_id' => ($a->class_type ?? 'classroom') === 'scheduled' ? $a->scheduled_class_id : $a->classroom_id,
                'classroom_id' => $a->classroom_id,
                'scheduled_class_id' => $a->scheduled_class_id,
                'class_title' => $a->classroom?->title ?? $a->scheduledClass?->title,
                'starts_at' => ($a->classroom?->starts_at ?? $a->scheduledClass?->starts_at)?->toIso8601String(),
                'status' => $a->calculated_at ? ($a->status ?? 'present') : ($a->joined_at ? 'present' : 'absent'),
                'total_seconds' => $a->total_seconds ?? 0,
                'first_joined_at' => ($a->first_joined_at ?? $a->joined_at)?->toIso8601String(),
                'calculated_at' => $a->calculated_at?->toIso8601String(),
            ]);

        return response()->json($records);
    }
}
