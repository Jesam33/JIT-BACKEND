<?php

namespace App\Http\Controllers\Lms;

use App\Models\BatchAnnouncement;
use App\Models\Batch;
use App\Models\LmsAttendance;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsCertificate;
use App\Models\LmsClassroom;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsMaterial;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StaffPortalController extends BaseLmsController
{
    /**
     * The acting teacher: a staff session's own row, or the academy owner's
     * academy-wide mirror (see BaseLmsController::staffActor). Everything below
     * takes its scope from the actor helpers on the base class, so an owner sees
     * the whole academy and a staffer sees only their assigned cohorts.
     */
    private function getTeacher(Request $request): ?LmsTeacher
    {
        return $this->staffActor($request);
    }

    private function getTrackIds(LmsTeacher $teacher): array
    {
        return $this->actorTrackIds($teacher);
    }

    private function getCourseIds(LmsTeacher $teacher): array
    {
        return $this->actorCourseIds($teacher);
    }

    /**
     * Announcements are keyed on Batch, but owner-provisioned cohorts are
     * LmsTracks that never create one, so the "cohort" dropdown comes back
     * empty and there's nothing to announce to. This lazily backfills a Batch
     * per track that lacks one (named after the cohort) and pins track.batch_id,
     * making the dropdown populate and announcements postable with no schema
     * change. Idempotent: a second call finds no null batch_ids and no-ops.
     */
    private function ensureTrackBatches(array $trackIds): void
    {
        if (empty($trackIds)) return;

        $tracks = LmsTrack::query()
            ->whereIn('id', $trackIds)
            ->whereNull('batch_id')
            ->get();

        foreach ($tracks as $track) {
            $batch = Batch::query()->create([
                'name' => $track->name ?: ('Cohort #' . $track->id),
            ]);
            $track->batch_id = $batch->id;
            $track->save();
        }
    }

    // ─── Students ─────────────────────────────────────────────

    public function students(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $trackIds = $this->getTrackIds($teacher);

        $studentIds = LmsEnrollment::query()
            ->whereIn('track_id', $trackIds)
            ->pluck('student_id');

        $students = LmsStudent::query()
            ->whereIn('id', $studentIds)
            ->get();

        return response()->json($students);
    }

    // ─── Assigned Courses ─────────────────────────────────────

    public function assignedCourses(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = $this->getCourseIds($teacher);

        $courses = LmsCourse::query()
            ->whereIn('id', $courseIds)
            ->get();

        return response()->json($courses);
    }

    // ─── Assigned Tracks ──────────────────────────────────────

    public function assignedTracks(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $trackIds = $this->getTrackIds($teacher);
        $this->ensureTrackBatches($trackIds);

        $tracks = LmsTrack::query()
            ->whereIn('id', $trackIds)
            // `course` as well as `batch`: an academy owner holds every cohort in
            // the academy, and cohort names alone ("Batch A", "Evening") do not
            // say which course they belong to when picking one from a list.
            ->with(['batch', 'course'])
            ->orderBy('id')
            ->get();

        return response()->json($tracks);
    }

    // ─── Tasks ───────────────────────────────────────────────

    public function listTasks(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = $this->getCourseIds($teacher);

        $tasks = LmsTask::query()
            ->whereIn('course_id', $courseIds)
            ->withCount('submissions')
            ->withCount(['submissions as ungraded_count' => fn ($q) => $q->whereNull('graded_at')])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tasks);
    }

    public function showTask(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = $this->getCourseIds($teacher);

        $task = LmsTask::query()
            ->whereIn('course_id', $courseIds)
            ->with(['submissions.student', 'course'])
            ->findOrFail($id);

        return response()->json($task);
    }

    // ─── Materials ───────────────────────────────────────────

    public function materials(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = $this->getCourseIds($teacher);

        $materials = LmsMaterial::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($materials);
    }

    public function storeMaterial(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:pdf,doc,video,link,other'],
            'file_url' => ['required', 'string', 'max:2048'],
            // Local uploaded file (see uploadMaterialFile), stored so the file
            // can be deleted with the material.
            'file_path' => ['nullable', 'string', 'max:255'],
            'session_id' => ['nullable', 'integer', 'exists:lms_classrooms,id'],
            // Externally-hosted video (Bunny Stream) pointers, set by the client
            // after a direct upload finishes. file_url carries the embed URL.
            'provider' => ['nullable', 'string', 'max:40'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'duration_seconds' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        // Scope the write to the teacher's own courses (the list and delete
        // queries already scope this way): without this a staffer could attach
        // a material to ANY course in the institute by guessing its id.
        if (! in_array($validated['course_id'], $this->getCourseIds($teacher))) {
            return response()->json(['message' => 'Course not in your assignment'], 403);
        }

        // Video materials are a paid feature (Basic+); other material types are
        // available on every plan. Gate only the video type.
        if (($validated['type'] ?? null) === 'video') {
            \App\Support\PlanGate::ensureFeature($this->currentTenantOrPrimary(), 'pre_recorded_video');
        }

        $material = LmsMaterial::query()->create($validated);

        return response()->json($material, 201);
    }

    // Upload a non-video material file (PDF, document, slides, archive…) from
    // the staffer's PC onto the platform's public disk, the same storage the
    // module contents and task submissions already use. Videos deliberately do
    // NOT come through here: their bytes go straight from the browser to Bunny
    // Stream (see StaffVideoController), so big files never touch this server.
    // The mimes allowlist keeps executable-ish types (html/svg) off the
    // same-origin storage host.
    public function uploadMaterialFile(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,csv,txt,rtf,zip,png,jpg,jpeg,webp,gif,mp3,wav,m4a',
            ],
        ]);

        $path = $validated['file']->store('materials', 'public');

        return response()->json([
            'url' => $this->publicFileUrl($path),
            'path' => $path,
        ]);
    }

    public function deleteMaterial(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        // Scope to the teacher's own courses so one staffer can't delete another
        // instructor's material by guessing an id (matches the list query above).
        $material = LmsMaterial::query()
            ->whereIn('course_id', $this->getCourseIds($teacher))
            ->findOrFail($id);

        // A locally uploaded file dies with its material; externally hosted
        // pointers (Bunny, pasted URLs) are left alone.
        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }

        $material->delete();

        return response()->json(['message' => 'Material deleted.']);
    }

    // ─── Attendance ──────────────────────────────────────────

    public function attendance(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        // Both delivery types: legacy course classrooms (teacher_id on the
        // classroom) and module scheduled classes (teacher_id on the class).
        // Actor-aware: the owner's mirror covers the whole academy.
        $classroomIds = $this->actorClassroomIds($teacher);

        $scheduledIds = $this->actorScheduledClassIds($teacher);

        $records = LmsAttendanceRecord::query()
            ->where(function ($q) use ($classroomIds, $scheduledIds) {
                $q->where(function ($w) use ($classroomIds) {
                    $w->where('class_type', 'classroom')->whereIn('classroom_id', $classroomIds);
                })->orWhere(function ($w) use ($scheduledIds) {
                    $w->where('class_type', 'scheduled')->whereIn('scheduled_class_id', $scheduledIds);
                });
            })
            ->with(['student', 'classroom', 'scheduledClass'])
            ->orderBy('created_at', 'desc')
            ->get();

        // "Stayed / lasted" per record: minutes the student was in the room against
        // the minutes the class was scheduled to run, from the same shared rule the
        // status was computed with (App\Support\AttendanceDuration). Set as plain
        // attributes (never saved) so the whole existing row shape is preserved.
        $records->each(function ($r) {
            $r->setAttribute('attended_minutes', (int) round(($r->total_seconds ?? 0) / 60));
            $r->setAttribute(
                'duration_minutes',
                \App\Support\AttendanceDuration::minutesFor($r->classroom ?? $r->scheduledClass)
            );
        });

        return response()->json($records);
    }

    // ─── Leaderboard ─────────────────────────────────────────

    /**
     * Students ranked by performance: average graded-task score first, then
     * modules completed, then attendance. Scoped to the teacher's assigned
     * courses (optionally narrowed with ?course_id=). Batched like the rest
     * of the speed pass: a fixed number of queries, never one per student.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = array_values(array_unique($this->getCourseIds($teacher)));
        if ($request->filled('course_id')) {
            $courseId = $request->integer('course_id');
            $courseIds = in_array($courseId, $courseIds) ? [$courseId] : [];
        }
        if (empty($courseIds)) {
            return response()->json(['rows' => [], 'modules_total' => 0]);
        }

        // Everyone enrolled in one of the teacher's (filtered) courses.
        $studentIds = LmsEnrollment::query()
            ->whereHas('track', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->pluck('student_id')
            ->unique()
            ->values();
        if ($studentIds->isEmpty()) {
            return response()->json(['rows' => [], 'modules_total' => 0]);
        }

        $taskIds = LmsTask::query()->whereIn('course_id', $courseIds)->pluck('id');

        // Average graded score per student (ungraded submissions don't count).
        $avgScore = LmsTaskSubmission::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('task_id', $taskIds)
            ->whereNotNull('score')
            ->groupBy('student_id')
            ->selectRaw('student_id, ROUND(AVG(score), 1) as avg_score, COUNT(*) as graded_count')
            ->get()
            ->keyBy('student_id');

        // Modules completed per student, using the same >= 70% pass rule the
        // dashboard and certificates use. A module counts once no matter how
        // many of its tasks were passed.
        $moduleTotal = (int) LmsModule::query()->whereIn('course_id', $courseIds)->count();
        $taskModules = LmsTask::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('module_id')
            ->pluck('module_id', 'id');
        $modulePasses = LmsTaskSubmission::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('task_id', $taskModules->keys())
            ->where('score', '>=', 70)
            ->get(['student_id', 'task_id']);
        $modulesDone = [];
        foreach ($modulePasses as $p) {
            $modulesDone[$p->student_id][$taskModules[$p->task_id]] = true;
        }

        // Attendance across this actor's sessions (both delivery types):
        // present = 1, partial = 0.5, absent = 0. Academy-wide for the owner.
        $classroomIds = $this->actorClassroomIds($teacher);
        $scheduledIds = $this->actorScheduledClassIds($teacher);
        $attendance = LmsAttendanceRecord::query()
            ->where(function ($q) use ($classroomIds, $scheduledIds) {
                $q->where(function ($w) use ($classroomIds) {
                    $w->where('class_type', 'classroom')->whereIn('classroom_id', $classroomIds);
                })->orWhere(function ($w) use ($scheduledIds) {
                    $w->where('class_type', 'scheduled')->whereIn('scheduled_class_id', $scheduledIds);
                });
            })
            ->whereIn('student_id', $studentIds)
            ->get(['student_id', 'status']);
        $attendanceScore = []; // student_id => [weighted, sessions]
        foreach ($attendance as $a) {
            $s = $a->student_id;
            $attendanceScore[$s] = $attendanceScore[$s] ?? [0.0, 0];
            $attendanceScore[$s][0] += $a->status === 'present' ? 1 : ($a->status === 'partial' ? 0.5 : 0);
            $attendanceScore[$s][1] += 1;
        }

        $students = LmsStudent::query()->whereIn('id', $studentIds)->get()->keyBy('id');

        $rows = $studentIds->map(function ($id) use ($students, $avgScore, $modulesDone, $attendanceScore) {
            $student = $students[$id] ?? null;
            $avg = $avgScore[$id] ?? null;
            [$weighted, $sessions] = $attendanceScore[$id] ?? [0, 0];
            return [
                'student_id' => $id,
                'name' => $student ? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')) : ('Student #' . $id),
                'email' => $student->email ?? null,
                'avg_score' => $avg !== null ? (float) $avg->avg_score : null,
                'graded_count' => (int) ($avg->graded_count ?? 0),
                'modules_completed' => isset($modulesDone[$id]) ? count($modulesDone[$id]) : 0,
                'attendance_pct' => $sessions > 0 ? (int) round($weighted / $sessions * 100) : null,
                'attendance_sessions' => $sessions,
            ];
        });

        // Rank: average score, then modules completed, then attendance.
        $rows = $rows->sort(function ($a, $b) {
            return [$b['avg_score'] ?? -1, $b['modules_completed'], $b['attendance_pct'] ?? -1]
                <=> [$a['avg_score'] ?? -1, $a['modules_completed'], $a['attendance_pct'] ?? -1];
        })->values();

        return response()->json(['rows' => $rows, 'modules_total' => $moduleTotal]);
    }

    // ─── Certificates ────────────────────────────────────────

    public function certificates(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $courseIds = $this->getCourseIds($teacher);

        $certificates = LmsCertificate::query()
            ->whereIn('course_id', $courseIds)
            ->with('student')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($certificates);
    }

    public function issueCertificate(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:lms_students,id'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'file_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $certificate = LmsCertificate::query()->create([
            'student_id' => $validated['student_id'],
            'course_id' => $validated['course_id'],
            'title' => $validated['title'],
            'file_url' => $validated['file_url'] ?? null,
            'issued_at' => now(),
        ]);

        return response()->json($certificate, 201);
    }

    // ─── Announcements ───────────────────────────────────────

    public function announcements(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $trackIds = $this->getTrackIds($teacher);
        $batchIds = LmsTrack::query()->whereIn('id', $trackIds)->pluck('batch_id')->filter()->unique();

        $announcements = BatchAnnouncement::query()
            ->whereIn('batch_id', $batchIds)
            ->with('batch')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($announcements);
    }

    public function createAnnouncement(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        // Backfill batches for this teacher's cohorts, then only allow posting to
        // a batch that belongs to one of them, a staffer can't announce into
        // another instructor's (or another tenant's) cohort by guessing an id.
        $trackIds = $this->getTrackIds($teacher);
        $this->ensureTrackBatches($trackIds);
        $allowedBatchIds = LmsTrack::query()
            ->whereIn('id', $trackIds)
            ->pluck('batch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $validated = $request->validate([
            'batch_id' => ['required', 'integer', Rule::in($allowedBatchIds)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
        ]);

        $announcement = BatchAnnouncement::query()->create([
            'batch_id' => $validated['batch_id'],
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'is_published' => true,
        ]);

        $notified = $this->notifyAnnouncement($announcement);

        // `notified` rides along on the announcement object the frontend already
        // reads, so the page can say how many students it actually reached. A
        // count of 0 is a real answer, not an error: the cohort has no students
        // yet, and saying so beats a silent success.
        return response()->json(array_merge($announcement->toArray(), ['notified' => $notified]), 201);
    }

    /**
     * Turn a posted announcement into student-visible notifications.
     *
     * Without this, posting was silent end to end: the announcement panel on the
     * student dashboard and the student notifications page both read
     * lms_notifications, and nothing anywhere converted a BatchAnnouncement into
     * one. The owner posted, the row appeared under "Posted Announcements", and no
     * student was ever told — the announcement existed but had no audience.
     *
     * The batch decides the audience: its cohorts' courses, then every student in
     * them (see studentIdsInCourses). Honours the student's own
     * notify_announcements switch, the one the profile page exposes; a NULL flag
     * predates that switch and counts as opted in, so nobody is silently dropped
     * for having an older row.
     *
     * Returns how many notifications were created.
     */
    private function notifyAnnouncement(BatchAnnouncement $announcement): int
    {
        $courseIds = LmsTrack::query()
            ->where('batch_id', $announcement->batch_id)
            ->pluck('course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $studentIds = $this->studentIdsInCourses($courseIds);

        if ($studentIds === []) {
            return 0;
        }

        $recipients = LmsStudent::query()
            ->whereIn('id', $studentIds)
            ->where(fn ($q) => $q->where('notify_announcements', true)->orWhereNull('notify_announcements'))
            ->pluck('id');

        foreach ($recipients as $studentId) {
            LmsNotification::query()->create([
                'student_id' => $studentId,
                // Its own type, so the frontend can style/route an announcement
                // differently from a task alert, and so the email sweep (which
                // skips only `mention`) delivers it by mail like everything else.
                'type' => 'announcement',
                'title' => $announcement->title,
                'body' => $announcement->body,
                'reference_type' => 'batch_announcement',
                'reference_id' => $announcement->id,
            ]);
        }

        return $recipients->count();
    }

    public function deleteAnnouncement(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        // BatchAnnouncement is not TenantAware, so scope the delete to this
        // teacher's own cohorts' batches, otherwise any staffer could delete any
        // announcement in any tenant by guessing an id (mirrors createAnnouncement).
        $allowedBatchIds = LmsTrack::query()
            ->whereIn('id', $this->getTrackIds($teacher))
            ->pluck('batch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $announcement = BatchAnnouncement::query()
            ->whereIn('batch_id', $allowedBatchIds)
            ->findOrFail($id);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    // ─── Reports ─────────────────────────────────────────────

    public function reports(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $trackIds = $this->getTrackIds($teacher);
        $courseIds = $this->getCourseIds($teacher);
        // Academy-wide for the owner's mirror; own classrooms for a staffer.
        $classroomIds = $this->actorClassroomIds($teacher);

        $totalStudents = LmsEnrollment::query()->whereIn('track_id', $trackIds)->count();
        $totalClasses = count($classroomIds);
        $totalTasks = LmsTask::query()->whereIn('course_id', $courseIds)->count();
        $totalSubmissions = LmsTaskSubmission::query()
            ->whereIn('task_id', LmsTask::query()->whereIn('course_id', $courseIds)->pluck('id'))
            ->count();
        $gradedSubmissions = LmsTaskSubmission::query()
            ->whereIn('task_id', LmsTask::query()->whereIn('course_id', $courseIds)->pluck('id'))
            ->whereNotNull('graded_at')
            ->count();

        $recentClassrooms = LmsClassroom::query()
            ->whereIn('id', $classroomIds)
            ->orderBy('starts_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_students' => $totalStudents,
                'total_classes' => $totalClasses,
                'total_tasks' => $totalTasks,
                'total_submissions' => $totalSubmissions,
                'graded_submissions' => $gradedSubmissions,
            ],
            'recent_classes' => $recentClassrooms,
        ]);
    }
}
