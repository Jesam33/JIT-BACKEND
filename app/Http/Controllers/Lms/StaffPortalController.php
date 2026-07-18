<?php

namespace App\Http\Controllers\Lms;

use App\Models\BatchAnnouncement;
use App\Models\LmsAttendance;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsCertificate;
use App\Models\LmsClassroom;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsMaterial;
use App\Models\LmsStudent;
use App\Models\LmsTask;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'session_id' => ['nullable', 'integer', 'exists:lms_classrooms,id'],
        ]);

        $material = LmsMaterial::query()->create($validated);

        return response()->json($material, 201);
    }

    public function deleteMaterial(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $material = LmsMaterial::query()->findOrFail($id);
        $material->delete();

        return response()->json(['message' => 'Material deleted.']);
    }

    // ─── Attendance ──────────────────────────────────────────

    public function attendance(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->getTeacher($request);
        if (! $teacher) return response()->json(['message' => 'Unauthorized'], 401);

        $classroomIds = LmsClassroom::query()
            ->where('teacher_id', $teacher->id)
            ->pluck('id');

        $records = LmsAttendanceRecord::query()
            ->whereIn('classroom_id', $classroomIds)
            ->with(['student', 'classroom'])
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

        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
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

        $announcement = BatchAnnouncement::query()->findOrFail($id);
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
