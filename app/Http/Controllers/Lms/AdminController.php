<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsTeacherCredentialsMail;
use App\Models\Batch;
use App\Models\LmsClassroom;
use App\Models\LmsCourse;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\TrainingRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminController extends BaseLmsController
{
    public function index(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $shell = $this->adminShellData();

        $assignedTaskSlots = \App\Models\LmsTask::query()->count();
        $completedTaskSlots = \App\Models\LmsTaskSubmission::query()->whereNotNull('graded_at')->count();
        $completionRate = $assignedTaskSlots > 0 ? round(($completedTaskSlots / $assignedTaskSlots) * 100) : 0;

        $approvedRegistrations = TrainingRegistration::query()->where('status', 'approved')->count();
        $onboardedStudents = LmsStudent::query()->where('onboarding_completed', true)->count();
        $activationRate = $approvedRegistrations > 0 ? round(($onboardedStudents / $approvedRegistrations) * 100) : 0;

        $enrolledStudents = LmsEnrollment::query()->count();
        $enrollmentRate = $shell['studentCount'] > 0 ? round(($enrolledStudents / $shell['studentCount']) * 100) : 0;

        $chartWidth = 600;
        $chartHeight = 270;
        $chartBaseline = $chartHeight - 20;
        $monthlyActiveLearners = [];
        $chartAreaPoints = '';
        $chartLinePoints = '';
        $chartPoints = [];
        $chartTicks = [];

        for ($i = 11; $i >= 0; $i--) {
            $monthlyActiveLearners[] = ['value' => max(1, rand(5, 30)), 'label' => now()->subMonths($i)->format('M')];
        }

        $maxVal = max(array_column($monthlyActiveLearners, 'value'));
        $tickCount = 4;

        for ($i = 0; $i <= $tickCount; $i++) {
            $val = round(($maxVal / $tickCount) * $i);
            $y = $chartBaseline - (($val / max($maxVal, 1)) * ($chartHeight - 50));
            $chartTicks[] = ['y' => $y, 'value' => $val];
        }

        $pointWidth = $chartWidth / max(count($monthlyActiveLearners) - 1, 1);

        foreach ($monthlyActiveLearners as $index => $point) {
            $x = $index * $pointWidth;
            $y = $chartBaseline - (($point['value'] / max($maxVal, 1)) * ($chartHeight - 50));
            $chartPoints[] = ['x' => $x, 'y' => $y, 'label' => $point['label']];
            $chartAreaPoints .= ($chartAreaPoints ? ' ' : '') . "{$x},{$y}";
            $chartLinePoints .= ($chartLinePoints ? ' ' : '') . "{$x},{$y}";
        }

        $chartAreaPoints .= " {$chartWidth},{$chartBaseline} 0,{$chartBaseline}";

        $leaderboard = LmsStudent::query()
            ->withCount(['taskSubmissions as graded_count' => function ($q) {
                $q->whereNotNull('graded_at');
            }])
            ->orderByDesc('graded_count')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'name' => $s->first_name . ' ' . $s->last_name,
                'in_progress' => $s->taskSubmissions()->whereNull('graded_at')->count(),
                'complete' => $s->graded_count,
                'progress' => 0,
            ]);

        $popularCourses = LmsCourse::query()
            ->withCount('students')
            ->orderByDesc('students_count')
            ->get()
            ->map(function ($course) {
                $enrolled = LmsEnrollment::query()
                    ->whereHas('track', fn ($q) => $q->where('course_id', $course->id))
                    ->count();

                $started = LmsStudent::query()
                    ->where('selected_course_id', $course->id)
                    ->where('onboarding_completed', true)
                    ->count();

                return [
                    'title' => $course->title,
                    'is_active' => $course->is_active,
                    'created_at' => $course->created_at,
                    'updated_at' => $course->updated_at,
                    'enrolled' => $enrolled,
                    'started' => $started,
                    'completed' => 0,
                    'average_progress' => 0,
                ];
            });

        return view('admin.lms.index', array_merge($shell, [
            'completionRate' => $completionRate,
            'assignedTaskSlots' => $assignedTaskSlots,
            'completedTaskSlots' => $completedTaskSlots,
            'activationRate' => $activationRate,
            'approvedRegistrations' => $approvedRegistrations,
            'onboardedStudents' => $onboardedStudents,
            'enrollmentRate' => $enrollmentRate,
            'enrolledStudents' => $enrolledStudents,
            'chartWidth' => $chartWidth,
            'chartHeight' => $chartHeight,
            'chartBaseline' => $chartBaseline,
            'monthlyActiveLearners' => $monthlyActiveLearners,
            'chartAreaPoints' => $chartAreaPoints,
            'chartLinePoints' => $chartLinePoints,
            'chartPoints' => $chartPoints,
            'chartTicks' => $chartTicks,
            'leaderboard' => $leaderboard,
            'popularCourses' => $popularCourses,
        ]));
    }

    public function coursesPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $studentCounts = LmsStudent::query()
            ->whereNotNull('selected_course_id')
            ->selectRaw('selected_course_id, COUNT(*) as aggregate')
            ->groupBy('selected_course_id')
            ->pluck('aggregate', 'selected_course_id');

        $classroomCounts = LmsClassroom::query()
            ->selectRaw('course_id, COUNT(*) as aggregate')
            ->groupBy('course_id')
            ->pluck('aggregate', 'course_id');

        $taskCounts = \App\Models\LmsTask::query()
            ->selectRaw('course_id, COUNT(*) as aggregate')
            ->groupBy('course_id')
            ->pluck('aggregate', 'course_id');

        $coursesList = LmsCourse::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (LmsCourse $course) use ($studentCounts, $classroomCounts, $taskCounts) {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'price' => (float) $course->price,
                    'max_students' => $course->max_students,
                    'registered_count' => $course->registered_count,
                    'is_live_available' => $course->is_live_available,
                    'is_prerecorded_available' => $course->is_prerecorded_available,
                    'is_active' => $course->is_active,
                    'students' => (int) ($studentCounts[$course->id] ?? 0),
                    'classrooms' => (int) ($classroomCounts[$course->id] ?? 0),
                    'tasks' => (int) ($taskCounts[$course->id] ?? 0),
                    'created_at' => $course->created_at,
                ];
            });

        return view('admin.lms.courses.index', array_merge($this->adminShellData(), [
            'coursesList' => $coursesList,
        ]));
    }

    public function createCourse(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'is_live_available' => ['nullable', 'boolean'],
            'is_prerecorded_available' => ['nullable', 'boolean'],
        ]);

        $course = LmsCourse::query()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'requirements' => $validated['requirements'] ?? null,
            'price' => $validated['price'] ?? 0,
            'max_students' => (int) ($validated['max_students'] ?? 0),
            'is_live_available' => $validated['is_live_available'] ?? true,
            'is_prerecorded_available' => $validated['is_prerecorded_available'] ?? true,
            'is_active' => true,
        ]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($course, 201);
        }

        return redirect()->back()->with('status', 'Course created successfully.');
    }

    public function deleteCourse(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $course = LmsCourse::query()->findOrFail($id);
        $course->delete();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Course deleted.']);
        }

        return redirect()->back()->with('status', 'Course deleted.');
    }

    public function createTeacherPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        return view('admin.lms.teachers.index', array_merge(
            $this->adminShellData(),
            ['teachersList' => LmsTeacher::query()->orderByDesc('created_at')->get()]
        ));
    }

    public function createTeacher(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:lms_teachers,email'],
        ]);

        $password = Str::random(12);

        $teacher = LmsTeacher::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        Mail::to($teacher->email)->send(new LmsTeacherCredentialsMail($teacher, $password));

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($teacher, 201);
        }

        return redirect()->back()->with('status', "Teacher created. Credentials sent to {$teacher->email}.");
    }

    public function classroomsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $coursesList = LmsCourse::query()->orderBy('title')->get();
        $teachersList = LmsTeacher::query()->where('is_active', true)->orderBy('name')->get();

        $courseMap = $coursesList->keyBy('id');
        $teacherMap = $teachersList->keyBy('id');
        $trackMap = LmsTrack::query()->get()->keyBy('id');

        $classroomsList = LmsClassroom::query()->orderByDesc('created_at')->get()->map(function ($classroom) use ($courseMap, $teacherMap) {

            return [
                'id' => $classroom->id,
                'title' => $classroom->title,
                'starts_at' => $classroom->starts_at,
                'ends_at' => $classroom->ends_at,
                'meeting_id' => $classroom->meeting_id,
                'course_title' => $courseMap->get($classroom->course_id)?->title,
                'teacher_name' => $teacherMap->get($classroom->teacher_id)?->name,
            ];
        });

        return view('admin.lms.classrooms.index', array_merge($this->adminShellData(), [
            'coursesList' => $coursesList,
            'teachersList' => $teachersList,
            'classroomsList' => $classroomsList,
        ]));
    }

    public function createClassroom(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'track_id' => ['required', 'integer', 'exists:lms_tracks,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $classroom = LmsClassroom::query()->create($validated + ['is_active' => true]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($classroom, 201);
        }

        return redirect()->back()->with('status', 'Classroom created successfully.');
    }

    public function deleteClassroom(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $classroom = LmsClassroom::query()->findOrFail($id);
        $classroom->delete();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Classroom deleted.']);
        }

        return redirect()->back()->with('status', 'Classroom deleted.');
    }

    public function tracksPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $teachersList = LmsTeacher::query()->where('is_active', true)->orderBy('name')->get();
        $coursesList = LmsCourse::query()->orderBy('title')->get();
        $batchesList = Batch::query()->orderByDesc('id')->get();

        $teacherMap = $teachersList->keyBy('id');
        $courseMap = $coursesList->keyBy('id');
        $batchMap = $batchesList->keyBy('id');

        $tracksList = LmsTrack::query()->orderByDesc('created_at')->get()->map(function (LmsTrack $track) use ($teacherMap, $courseMap, $batchMap) {
            return [
                'id' => $track->id,
                'name' => $track->name,
                'teacher_name' => $teacherMap->get($track->instructor_id)?->name,
                'course_title' => $courseMap->get($track->course_id)?->title,
                'batch_name' => $batchMap->get($track->batch_id)?->name,
                'created_at' => $track->created_at,
            ];
        });

        return view('admin.lms.tracks.index', array_merge($this->adminShellData(), [
            'teachersList' => $teachersList,
            'coursesList' => $coursesList,
            'batchesList' => $batchesList,
            'tracksList' => $tracksList,
        ]));
    }

    public function createTrack(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'instructor_id' => ['required', 'integer', 'exists:lms_teachers,id'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'starts_at' => ['nullable', 'date'],
        ]);

        $track = LmsTrack::query()->create($validated);

        LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($track, 201);
        }

        return redirect()->back()->with('status', 'Track created successfully.');
    }

    public function deleteTrack(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $track = LmsTrack::query()->findOrFail($id);
        $track->delete();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Track deleted.']);
        }

        return redirect()->back()->with('status', 'Track deleted.');
    }

    public function listTracks(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tracks = LmsTrack::query()
            ->with(['teacher', 'course', 'batch'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'teacher_name' => $t->teacher?->name,
                'course_title' => $t->course?->title,
                'batch_name' => $t->batch?->name,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return response()->json($tracks);
    }

    public function apiCreateTrack(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'instructor_id' => ['required', 'integer', 'exists:lms_teachers,id'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
        ]);

        $track = LmsTrack::query()->create($validated);

        LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        return response()->json($track, 201);
    }

    public function updateTrack(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $track = LmsTrack::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'instructor_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
        ]);

        $track->update($validated);

        return response()->json($track);
    }

    public function deleteTrackApi(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        LmsTrack::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Track deleted.']);
    }

    public function listBatches(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $batches = Batch::query()
            ->withCount('tracks')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($batches);
    }

    public function createBatch(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registration_starts_at' => ['nullable', 'date'],
            'registration_ends_at' => ['nullable', 'date', 'after:registration_starts_at'],
        ]);

        $batch = Batch::query()->create($validated);

        return response()->json($batch, 201);
    }

    public function createBatchAnnouncement(Request $request, int $batchId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $batch = Batch::query()->findOrFail($batchId);

        $announcement = $batch->announcements()->create($validated);

        return response()->json($announcement, 201);
    }

    public function enrollStudent(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:lms_students,id'],
            'track_id' => ['required', 'integer', 'exists:lms_tracks,id'],
        ]);

        $enrollment = LmsEnrollment::query()->updateOrCreate(
            ['student_id' => $validated['student_id']],
            ['track_id' => $validated['track_id']]
        );

        $track = LmsTrack::query()->findOrFail($validated['track_id']);

        LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        LmsDmThread::query()->firstOrCreate([
            'student_id' => $validated['student_id'],
            'instructor_id' => $track->instructor_id,
            'track_id' => $track->id,
        ]);

        return response()->json($enrollment, 201);
    }

    public function studentsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $query = LmsStudent::query()
            ->with('course')
            ->withCount('enrollments');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('selected_course_id', $courseId);
        }

        if ($trackId = $request->input('track_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('track_id', $trackId));
        }

        $students = $query->orderBy('created_at', 'desc')->paginate(50)->withQueryString();

        $coursesList = LmsCourse::query()->orderBy('title')->get(['id', 'title']);
        $tracksList  = LmsTrack::query()->orderBy('name')->get(['id', 'name']);

        $shell = $this->adminShellData();

        return view('admin.lms.students.index', array_merge($shell, [
            'students'    => $students,
            'coursesList' => $coursesList,
            'tracksList'  => $tracksList,
        ]));
    }

    public function deleteStudent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $student = LmsStudent::query()->findOrFail($id);
        $student->delete();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Student deleted.']);
        }

        return redirect()->back()->with('status', 'Student deleted successfully.');
    }

    public function agentsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agents = \App\Models\Agent::query()
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        $shell = $this->adminShellData();

        return view('admin.lms.agents', array_merge($shell, [
            'agents' => $agents,
        ]));
    }

    public function approveAgent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agent = \App\Models\Agent::query()->findOrFail($id);
        $agent->update(['status' => 'approved', 'approved_at' => now()]);

        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $baseUrl = rtrim(env('LMS_BASE_URL', 'http://127.0.0.1:3000'), '/');
            try {
                \Illuminate\Support\Facades\Mail::to($agent->email)
                    ->send(new \App\Mail\AgentApplicationApprovedMail($agent, $baseUrl . '/lms/agent/login'));
            } catch (\Throwable $e) {
                // log
            }
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Agent approved.', 'agent' => $agent]);
        }

        return redirect()->back()->with('status', 'Agent approved successfully.');
    }

    public function rejectAgent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        \App\Models\Agent::query()->findOrFail($id)->update(['status' => 'rejected']);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Agent rejected.']);
        }

        return redirect()->back()->with('status', 'Agent rejected.');
    }
}
