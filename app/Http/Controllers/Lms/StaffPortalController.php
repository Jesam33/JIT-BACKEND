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
    private function getTeacher(Request $request): ?LmsTeacher
    {
        $session = $this->sessionFromRequest($request, 'staff');
        if (! $session) return null;
        return LmsTeacher::query()->find($session->user_id);
    }

    private function getTrackIds(LmsTeacher $teacher): array
    {
        return LmsTrack::query()
            ->where('instructor_id', $teacher->id)
            ->pluck('id')
            ->toArray();
    }

    private function getCourseIds(LmsTeacher $teacher): array
    {
        return LmsTrack::query()
            ->where('instructor_id', $teacher->id)
            ->pluck('course_id')
            ->filter()
            ->toArray();
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
            ->with('batch')
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
        $classroomIds = LmsClassroom::query()
            ->where('teacher_id', $teacher->id)
            ->pluck('id');

        $scheduledIds = LmsScheduledClass::query()
            ->where('teacher_id', $teacher->id)
            ->pluck('id');

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

        return response()->json($records);
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

        return response()->json($announcement, 201);
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
        $classroomIds = LmsClassroom::query()
            ->where('teacher_id', $teacher->id)
            ->pluck('id');

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
            ->where('teacher_id', $teacher->id)
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
