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
use App\Models\Payment;
use App\Models\PlatformAnnouncement;
use App\Models\TrainingRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Notifications\OnboardingCompleted;

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

        $this->notifyStudentsOfNewCourse($course);

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

    public function institutesPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenants = Tenant::query()->orderByDesc('created_at')->get()->map(function (Tenant $t) {
            $ownerRow = DB::table('tenant_admins')->where('tenant_id', $t->id)->first();
            $owner = $ownerRow ? User::find($ownerRow->user_id) : null;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'status' => $t->status,
                'owner_email' => $owner?->email,
                'created_at' => $t->created_at,
            ];
        });

        return view('admin.lms.institutes.index', array_merge($this->adminShellData(), [
            'tenantsList' => $tenants,
            'tenantCount' => $tenants->count(),
        ]));
    }

    /**
     * Host revenue view. Two ledgers, both live off real funds:
     *
     *  1. Platform revenue — what Jorsas has been paid by institutes (paid signups
     *     + plan upgrades). Read straight from `platform_transactions` (the ledger
     *     the signup/billing/webhook confirmation paths all write, idempotent on
     *     reference), so the "success" total reconciles 1:1 against Paystack.
     *
     *  2. Per-institute course earnings — what each institute has earned from
     *     student course fees, and the platform commission owed on that, derived
     *     LIVE from the tenant-scoped `payments` table (successful rows only) times
     *     each tenant's per-plan commission percent. Nothing is stored/duplicated:
     *     re-open the page and the numbers reflect the funds as they stand now.
     *
     * Both cross tenant boundaries (this is the super-admin's platform-wide view),
     * so `payments` is queried with the tenant scope removed and grouped by tenant.
     */
    public function transactionsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        // --- Ledger 1: platform subscription revenue (institute → Jorsas) --------
        $transactions = \App\Models\PlatformTransaction::query()
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (\App\Models\PlatformTransaction $t) => [
                'id' => $t->id,
                'tenant_name' => $t->tenant_name ?? '—',
                'plan' => $t->plan,
                'purpose' => $t->purpose,
                'reference' => $t->reference,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'status' => $t->status,
                'paid_at' => $t->paid_at,
                'created_at' => $t->created_at,
            ]);

        $platformRevenue = (float) \App\Models\PlatformTransaction::query()
            ->where('status', 'success')
            ->sum('amount');
        $pendingPlatform = (float) \App\Models\PlatformTransaction::query()
            ->where('status', 'pending')
            ->sum('amount');
        $successCount = \App\Models\PlatformTransaction::query()->where('status', 'success')->count();

        // --- Ledger 2: per-institute course earnings + commission (live) ---------
        // Successful course-fee payments across ALL tenants (scope removed), summed
        // per tenant. Commission is applied per-tenant using that tenant's own plan
        // rate, so the platform's cut reflects each institute's actual plan.
        $paidByTenant = Payment::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('status', 'success')
            ->selectRaw('tenant_id, COUNT(*) as sales, SUM(amount) as gross')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $instituteEarnings = Tenant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $t) use ($paidByTenant) {
                $row = $paidByTenant->get($t->id);
                $gross = (float) ($row->gross ?? 0);
                $rate = $t->commissionPercent();
                $commission = round($gross * ($rate / 100), 2);

                return [
                    'tenant_name' => $t->name,
                    'plan' => $t->planSlug(),
                    'commission_percent' => $rate,
                    'sales' => (int) ($row->sales ?? 0),
                    'gross' => round($gross, 2),
                    'commission' => $commission,
                    'net_to_institute' => round($gross - $commission, 2),
                ];
            })
            ->filter(fn ($r) => $r['sales'] > 0 || $r['gross'] > 0)
            ->values();

        $totalCourseGross = round($instituteEarnings->sum('gross'), 2);
        $totalCommission = round($instituteEarnings->sum('commission'), 2);

        return view('admin.lms.transactions.index', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'transactions',
            'transactions' => $transactions,
            'platformRevenue' => $platformRevenue,
            'pendingPlatform' => $pendingPlatform,
            'successCount' => $successCount,
            'instituteEarnings' => $instituteEarnings,
            'totalCourseGross' => $totalCourseGross,
            'totalCommission' => $totalCommission,
        ]));
    }

    public function deleteInstitute(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::query()->findOrFail($id);
        // before deleting tenant, clean up related owner links and invitations
        $ownerRow = DB::table('tenant_admins')->where('tenant_id', $tenant->id)->first();
        if ($ownerRow && isset($ownerRow->user_id)) {
            $userId = $ownerRow->user_id;
            // remove tenant_admins link for this tenant
            DB::table('tenant_admins')->where('tenant_id', $tenant->id)->delete();

            // remove any owner invitations for this tenant (table may not exist in older installs)
            if (Schema::hasTable('owner_invitations')) {
                DB::table('owner_invitations')->where('tenant_id', $tenant->id)->delete();
            }

            // if this user is not attached to any other tenant, delete the user record as well
            $stillLinked = DB::table('tenant_admins')->where('user_id', $userId)->exists();
            if (! $stillLinked) {
                try {
                    \App\Models\User::where('id', $userId)->delete();
                } catch (\Throwable $e) {
                    logger()->warning('Failed deleting owner user during tenant delete', ['err' => $e->getMessage()]);
                }
            }
        }

        // perform delete (hard delete) — consider soft delete if required
        $tenant->delete();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Institute deleted.']);
        }

        return redirect()->back()->with('status', 'Institute deleted.');
    }

    public function resendInstituteOnboarding(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::query()->findOrFail($id);
        $ownerRow = DB::table('tenant_admins')->where('tenant_id', $tenant->id)->first();
        $owner = $ownerRow ? User::find($ownerRow->user_id) : null;

        if (! $owner) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => 'No tenant owner found.'], 404);
            }
            return redirect()->back()->with('error', 'No tenant owner found for this institute.');
        }

        try {
            $owner->notify(new OnboardingCompleted($tenant));
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => 'Onboarding notification resent.']);
            }
            return redirect()->back()->with('status', 'Onboarding notification resent to ' . $owner->email);
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => 'Failed to send notification', 'err' => $e->getMessage()], 500);
            }
            return redirect()->back()->with('error', 'Failed to send notification: ' . $e->getMessage());
        }
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

    /**
     * Platform announcements — the jorsastech host broadcasts a single message to
     * everybody (students, staff, agents) across EVERY institute. This page only
     * enqueues the announcement; the `lms:dispatch-announcements` command fans it
     * out into per-recipient notifications (which the email sweep then delivers).
     */
    public function platformAnnouncementsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $announcements = PlatformAnnouncement::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.lms.announcements.index', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'announcements',
            'announcements' => $announcements,
            'audienceOptions' => PlatformAnnouncement::AUDIENCES,
        ]));
    }

    public function createPlatformAnnouncement(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*' => ['string', 'in:' . implode(',', PlatformAnnouncement::AUDIENCES)],
        ]);

        // Normalise to the canonical audience list (dedupe, drop anything unknown).
        $audiences = array_values(array_intersect(PlatformAnnouncement::AUDIENCES, $validated['audiences']));

        $user = $request->user();

        PlatformAnnouncement::query()->create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audiences' => $audiences,
            'status' => PlatformAnnouncement::STATUS_QUEUED,
            'created_by' => $user?->getKey(),
            'created_by_name' => $user?->name ?? $user?->email ?? 'Platform',
        ]);

        $message = 'Announcement queued for ' . implode(', ', $audiences)
            . '. It will be delivered to every institute within a minute.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $message], 201);
        }

        return redirect()
            ->route('admin.lms.announcements.index')
            ->with('status', $message);
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

        $password = \Illuminate\Support\Str::random(12);
        $agent->update([
            'status' => 'approved',
            'approved_at' => now(),
            'password' => bcrypt($password),
        ]);

        if (filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $baseUrl = rtrim(env('LMS_BASE_URL', 'http://127.0.0.1:3000'), '/');
            try {
                \Illuminate\Support\Facades\Mail::to($agent->email)
                    ->send(new \App\Mail\AgentApplicationApprovedMail(
                        $agent,
                        $baseUrl . '/lms/agent/login?email=' . urlencode($agent->email),
                        $password
                    ));
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

    public function deleteAgent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agent = \App\Models\Agent::query()->findOrFail($id);

        $agent->sessions()->delete();
        $agent->notifications()->delete();
        $agent->commissions()->delete();
        $agent->delete();

        return redirect()->back()->with('status', 'Agent deleted successfully.');
    }

    public function withdrawalRequestsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $withdrawals = \App\Models\AgentCommission::query()
            ->where('type', 'withdrawal')
            ->where('status', 'withdrawal_requested')
            ->with('agent')
            ->orderByDesc('id')
            ->paginate(50);

        $paid = \App\Models\AgentCommission::query()
            ->where('type', 'withdrawal')
            ->where('status', 'paid')
            ->with('agent')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'paid-page');

        $shell = $this->adminShellData();

        return view('admin.lms.agent-withdrawals', array_merge($shell, [
            'withdrawals' => $withdrawals,
            'paid' => $paid,
        ]));
    }

    public function payCommissions(Request $request, int $agentId)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        \App\Models\AgentCommission::where('agent_id', $agentId)
            ->where('type', 'withdrawal')
            ->whereIn('status', ['withdrawal_requested'])
            ->update(['status' => 'paid', 'paid_at' => now()]);

        return redirect()->back()->with('status', 'Withdrawal marked as paid.');
    }
}
