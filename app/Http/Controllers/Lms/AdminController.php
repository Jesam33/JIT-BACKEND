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
use App\Models\CeoForum;
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
    /**
     * The HOST dashboard: a platform-wide view of every academy, every naira and
     * every agent. Deliberately NOT an academy-ops view (task completion and
     * student activation belong to each academy's own analytics), it answers the
     * host's four standing questions: how is the business doing, what needs my
     * action right now, how is each academy performing, and how is the agent
     * economy doing.
     *
     * IMPORTANT: ResolveTenant binds the PRIMARY institute on this host (the api
     * subdomain is reserved and falls back to it), so every tenant-scoped model
     * below must explicitly drop the TenantScope or the numbers silently become
     * jorsas-only. Same rule as transactionsPage(). Every query is batched
     * (grouped/aggregated in SQL), never per-tenant loops.
     */
    public function index(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $scope = \App\Scopes\TenantScope::class;

        $tenants = Tenant::query()->orderBy('name')->get();
        $tenantMap = $tenants->keyBy('id');

        // ─── Money ledgers ────────────────────────────────────────────────────
        // What the platform has been paid by institutes (paid signups + upgrades).
        $platformRevenue = (float) \App\Models\PlatformTransaction::query()->where('status', 'success')->sum('amount');
        $pendingPlatform = (float) \App\Models\PlatformTransaction::query()->where('status', 'pending')->sum('amount');
        $platformTxCount = (int) \App\Models\PlatformTransaction::query()->where('status', 'success')->count();

        // Successful course-fee payments across ALL academies, grouped per tenant.
        $paidByTenant = Payment::query()
            ->withoutGlobalScope($scope)
            ->where('status', 'success')
            ->selectRaw('tenant_id, COUNT(*) as sales, SUM(amount) as gross')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $courseGross = round((float) $paidByTenant->sum('gross'), 2);
        $salesCount = (int) $paidByTenant->sum('sales');
        $salesThisMonth = (float) Payment::query()
            ->withoutGlobalScope($scope)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        // Platform scale, students/staff/courses per academy, all unscoped.
        $studentsByTenant = LmsStudent::query()->withoutGlobalScope($scope)
            ->selectRaw('tenant_id, COUNT(*) as aggregate')->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');
        $coursesByTenant = LmsCourse::query()->withoutGlobalScope($scope)
            ->selectRaw('tenant_id, COUNT(*) as aggregate')->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');

        $studentCount = (int) $studentsByTenant->sum();
        $teacherCount = (int) LmsTeacher::query()->withoutGlobalScope($scope)->count();
        $courseCount = (int) $coursesByTenant->sum();
        $trackCount = (int) LmsTrack::query()->withoutGlobalScope($scope)->count();
        $newTenantsThisMonth = (int) Tenant::query()->where('created_at', '>=', now()->startOfMonth())->count();

        // ─── Per-academy performance (gross, commission at that academy's plan rate) ──
        $academyRows = $tenants->map(function (Tenant $t) use ($paidByTenant, $studentsByTenant, $coursesByTenant) {
            $row = $paidByTenant->get($t->id);
            $gross = (float) ($row->gross ?? 0);
            $rate = $t->commissionPercent();

            return [
                'id' => $t->id,
                'name' => $t->name,
                'is_primary' => $t->isPrimary(),
                'plan' => $t->planSlug(),
                'state' => $t->subscriptionState(),
                'students' => (int) ($studentsByTenant[$t->id] ?? 0),
                'courses' => (int) ($coursesByTenant[$t->id] ?? 0),
                'sales' => (int) ($row->sales ?? 0),
                'gross' => round($gross, 2),
                'commission' => round($gross * ($rate / 100), 2),
                'created_at' => $t->created_at,
            ];
        })->sortByDesc('gross')->values();

        $totalCommission = round($academyRows->sum('commission'), 2);
        $sellingAcademies = (int) $academyRows->where('gross', '>', 0)->count();

        // ─── Agent economy ────────────────────────────────────────────────────
        // One grouped query per ledger question: commissions EARNED (type
        // referral/default, positive), payouts AWAITING the host (withdrawal,
        // requested) and payouts already MADE (withdrawal, paid). COALESCE guards
        // pre-default rows where type is null.
        $agentsByTenant = \App\Models\Agent::query()->withoutGlobalScope($scope)
            ->selectRaw('tenant_id, COUNT(*) as aggregate')->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');

        $earningsByTenant = \App\Models\AgentCommission::query()->withoutGlobalScope($scope)
            ->selectRaw("tenant_id,
                COUNT(*) as deals,
                SUM(CASE WHEN COALESCE(type, 'referral') <> 'withdrawal' THEN commission_amount ELSE 0 END) as earned,
                SUM(CASE WHEN type = 'withdrawal' AND status = 'withdrawal_requested' THEN ABS(commission_amount) ELSE 0 END) as pending_payout,
                SUM(CASE WHEN type = 'withdrawal' AND status = 'paid' THEN ABS(commission_amount) ELSE 0 END) as paid_out")
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $agentRows = $tenants
            ->filter(fn ($t) => isset($agentsByTenant[$t->id]) || $earningsByTenant->has($t->id))
            ->map(function (Tenant $t) use ($agentsByTenant, $earningsByTenant) {
                $e = $earningsByTenant->get($t->id);
                $earned = (float) ($e->earned ?? 0);
                $pendingPayout = (float) ($e->pending_payout ?? 0);
                $paidOut = (float) ($e->paid_out ?? 0);

                return [
                    'name' => $t->name,
                    'is_primary' => $t->isPrimary(),
                    'agents' => (int) ($agentsByTenant[$t->id] ?? 0),
                    'deals' => (int) ($e->deals ?? 0),
                    'earned' => $earned,
                    'pending_payout' => $pendingPayout,
                    'paid_out' => $paidOut,
                    // What agents of THIS academy can still withdraw.
                    'balance' => round($earned - $pendingPayout - $paidOut, 2),
                ];
            })
            ->sortByDesc('earned')
            ->values();

        $agentEarnedTotal = round((float) $agentRows->sum('earned'), 2);
        $agentPayoutPending = round((float) $agentRows->sum('pending_payout'), 2);
        $agentCount = (int) $agentsByTenant->sum();

        // The split the host asked for by name: the primary institute's agents
        // versus every other academy's agents.
        $primaryAgentRow = $agentRows->firstWhere('is_primary', true);
        $otherAgentRows = $agentRows->where('is_primary', false)->values();
        $jorsasAgents = [
            'agents' => (int) ($primaryAgentRow['agents'] ?? 0),
            'earned' => (float) ($primaryAgentRow['earned'] ?? 0),
            'pending_payout' => (float) ($primaryAgentRow['pending_payout'] ?? 0),
        ];
        $otherAgents = [
            'agents' => (int) $otherAgentRows->sum('agents'),
            'earned' => (float) $otherAgentRows->sum('earned'),
            'pending_payout' => (float) $otherAgentRows->sum('pending_payout'),
        ];

        // Top earners across the whole platform (batched: one grouped query +
        // one whereIn for the agent rows, never per-agent lookups).
        $topEarners = \App\Models\AgentCommission::query()->withoutGlobalScope($scope)
            ->whereRaw("COALESCE(type, 'referral') <> 'withdrawal'")
            ->selectRaw('agent_id, SUM(commission_amount) as earned, COUNT(*) as deals')
            ->groupBy('agent_id')
            ->orderByDesc('earned')
            ->limit(8)
            ->get();

        $topAgentModels = \App\Models\Agent::query()->withoutGlobalScope($scope)
            ->whereIn('id', $topEarners->pluck('agent_id')->filter())
            ->get()
            ->keyBy('id');

        $topAgents = $topEarners->map(function ($e) use ($topAgentModels, $tenantMap) {
            $agent = $topAgentModels->get($e->agent_id);

            return [
                'name' => $agent?->name ?? ('Agent #' . $e->agent_id),
                'academy' => $tenantMap->get($agent->tenant_id ?? 0)?->name ?? '-',
                'status' => $agent?->status ?? 'unknown',
                'earned' => (float) $e->earned,
                'deals' => (int) $e->deals,
            ];
        })->values();

        // ─── Attention center: what needs the host's action right now ─────────
        $withdrawalRequests = (int) \App\Models\AgentCommission::query()->withoutGlobalScope($scope)
            ->where('type', 'withdrawal')->where('status', 'withdrawal_requested')->count();
        $withdrawalTotal = abs((float) \App\Models\AgentCommission::query()->withoutGlobalScope($scope)
            ->where('type', 'withdrawal')->where('status', 'withdrawal_requested')->sum('commission_amount'));
        $pendingRegistrations = (int) TrainingRegistration::query()->withoutGlobalScope($scope)
            ->where('status', 'pending')->count();
        $pendingAgentApps = (int) \App\Models\Agent::query()->withoutGlobalScope($scope)
            ->where('status', 'pending')->count();
        $attentionAcademies = (int) $tenants->filter(
            fn ($t) => in_array($t->subscriptionState(), ['grace', 'frozen'])
        )->count();

        $pendingPlatformTx = (int) \App\Models\PlatformTransaction::query()->where('status', 'pending')->count();

        $attention = [
            [
                'label' => 'Agent payout requests',
                'detail' => '₦' . number_format($withdrawalTotal) . ' owed to agents, waiting on you',
                'count' => $withdrawalRequests,
                'href' => route('admin.lms.agents.withdrawals'),
            ],
            [
                'label' => 'Pending student registrations',
                'detail' => 'Students waiting to be approved into their course',
                'count' => $pendingRegistrations,
                'href' => route('admin.lms.intake.pending'),
            ],
            [
                'label' => 'Agent applications',
                'detail' => 'Applications awaiting your review',
                'count' => $pendingAgentApps,
                'href' => route('admin.lms.agents.index'),
            ],
            [
                'label' => 'Academies in grace or frozen',
                'detail' => 'Paid period ended, they need to renew',
                'count' => $attentionAcademies,
                'href' => route('admin.lms.institutes.index'),
            ],
            [
                'label' => 'Pending platform payments',
                'detail' => 'Signups and upgrades started but not confirmed',
                'count' => $pendingPlatformTx,
                'href' => route('admin.lms.transactions.index'),
            ],
        ];

        // ─── Newest academies (owner emails batched, no per-tenant query) ─────
        $newestTenants = Tenant::query()->orderByDesc('created_at')->limit(5)->get();
        $ownerLinks = DB::table('tenant_admins')
            ->whereIn('tenant_id', $newestTenants->pluck('id'))
            ->get()
            ->groupBy('tenant_id');
        $ownerUsers = User::query()
            ->whereIn('id', $ownerLinks->flatten()->pluck('user_id')->unique())
            ->get()
            ->keyBy('id');

        $newestAcademies = $newestTenants->map(function (Tenant $t) use ($ownerLinks, $ownerUsers) {
            $link = $ownerLinks->get($t->id)?->first();

            return [
                'name' => $t->name,
                'plan' => $t->planSlug(),
                'state' => $t->subscriptionState(),
                'owner_email' => $link ? ($ownerUsers->get($link->user_id)?->email ?? '-') : '-',
                'created_at' => $t->created_at,
            ];
        });

        // ─── Course-sales trend, REAL data (the old chart used rand()) ────────
        $monthlyRaw = Payment::query()->withoutGlobalScope($scope)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(amount) as gross")
            ->groupBy('ym')
            ->pluck('gross', 'ym');

        $chartWidth = 600;
        $chartHeight = 270;
        $chartBaseline = $chartHeight - 20;
        $monthlySales = [];
        $chartAreaPoints = '';
        $chartLinePoints = '';
        $chartPoints = [];
        $chartTicks = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthlySales[] = [
                'value' => (float) ($monthlyRaw[$month->format('Y-m')] ?? 0),
                'label' => $month->format('M'),
            ];
        }

        $maxVal = max(1.0, (float) max(array_column($monthlySales, 'value')));
        $tickCount = 4;
        // Compact naira labels for the y axis (₦1.2M / ₦250k / ₦900).
        $tickLabel = fn ($v) => $v >= 1000000
            ? round($v / 1000000, 1) . 'M'
            : ($v >= 1000 ? round($v / 1000) . 'k' : (string) round($v));

        for ($i = 0; $i <= $tickCount; $i++) {
            $val = ($maxVal / $tickCount) * $i;
            $y = $chartBaseline - (($val / $maxVal) * ($chartHeight - 50));
            $chartTicks[] = ['y' => $y, 'value' => $tickLabel($val)];
        }

        $pointWidth = $chartWidth / max(count($monthlySales) - 1, 1);

        foreach ($monthlySales as $index => $point) {
            $x = $index * $pointWidth;
            $y = $chartBaseline - (($point['value'] / $maxVal) * ($chartHeight - 50));
            $chartPoints[] = ['x' => $x, 'y' => $y, 'label' => $point['label']];
            $chartAreaPoints .= ($chartAreaPoints ? ' ' : '') . "{$x},{$y}";
            $chartLinePoints .= ($chartLinePoints ? ' ' : '') . "{$x},{$y}";
        }

        $chartAreaPoints .= " {$chartWidth},{$chartBaseline} 0,{$chartBaseline}";

        return view('admin.lms.index', [
            'adminDir' => config('saas.admin_dir', 'admin'),
            // Sidebar counts, platform-wide (the layout reads these with ?? 0).
            'studentCount' => $studentCount,
            'tracks' => $trackCount,
            'courses' => $courseCount,
            'teachers' => $teacherCount,
            'tenantCount' => $tenants->count(),

            // Money.
            'platformRevenue' => $platformRevenue,
            'pendingPlatform' => $pendingPlatform,
            'platformTxCount' => $platformTxCount,
            'courseGross' => $courseGross,
            'salesCount' => $salesCount,
            'salesThisMonth' => $salesThisMonth,
            'totalCommission' => $totalCommission,
            'sellingAcademies' => $sellingAcademies,

            // Platform scale.
            'newTenantsThisMonth' => $newTenantsThisMonth,

            // Agent economy.
            'agentRows' => $agentRows,
            'agentEarnedTotal' => $agentEarnedTotal,
            'agentPayoutPending' => $agentPayoutPending,
            'agentCount' => $agentCount,
            'jorsasAgents' => $jorsasAgents,
            'otherAgents' => $otherAgents,
            'topAgents' => $topAgents,

            // Academies.
            'academyRows' => $academyRows,
            'newestAcademies' => $newestAcademies,

            // Attention center.
            'attention' => $attention,
            'withdrawalRequests' => $withdrawalRequests,
            'withdrawalTotal' => $withdrawalTotal,

            // Chart.
            'chartWidth' => $chartWidth,
            'chartHeight' => $chartHeight,
            'chartBaseline' => $chartBaseline,
            'monthlySales' => $monthlySales,
            'chartAreaPoints' => $chartAreaPoints,
            'chartLinePoints' => $chartLinePoints,
            'chartPoints' => $chartPoints,
            'chartTicks' => $chartTicks,
        ]);
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
                'meeting_url' => $classroom->meeting_url,
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
     *  1. Platform revenue, what Jorsas has been paid by institutes (paid signups
     *     + plan upgrades). Read straight from `platform_transactions` (the ledger
     *     the signup/billing/webhook confirmation paths all write, idempotent on
     *     reference), so the "success" total reconciles 1:1 against Paystack.
     *
     *  2. Per-institute course earnings, what each institute has earned from
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

        // perform delete (hard delete), consider soft delete if required
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
     * Platform announcements, the jorsastech host broadcasts a single message to
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

    /**
     * CEO's Forum, the platform-hosted live meetings for institute owners. This
     * page lists upcoming + past forums and holds the create form. Emails (invite
     * + reminder) are sent by the `lms:send-ceo-forum-emails` command, not here,
     * so the request stays fast and the send is retry-safe.
     */
    public function ceoForumsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $forums = CeoForum::query()
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get();

        return view('admin.lms.forums.index', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'forums',
            'forums' => $forums,
        ]));
    }

    public function createCeoForum(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'host_name' => ['nullable', 'string', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:2048'],
            'cover_image' => ['nullable', 'url', 'max:2048'],
        ]);

        $user = $request->user();

        CeoForum::query()->create([
            'title' => $validated['title'],
            'topic' => $validated['topic'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'],
            'host_name' => $validated['host_name'] ?? ($user?->name ?? 'Jorsas Tech'),
            'recording_url' => $validated['recording_url'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
            'status' => CeoForum::STATUS_SCHEDULED,
            'created_by' => $user?->getKey(),
            'created_by_name' => $user?->name ?? $user?->email ?? 'Platform',
        ]);

        $message = 'Forum scheduled. Every institute owner will be emailed an invitation within a minute, and a reminder about an hour before it starts.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $message], 201);
        }

        return redirect()
            ->route('admin.lms.forums.index')
            ->with('status', $message);
    }

    public function updateCeoForum(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $forum = CeoForum::query()->findOrFail($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'host_name' => ['nullable', 'string', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:2048'],
            'cover_image' => ['nullable', 'url', 'max:2048'],
        ]);

        // If the start time moved, let the reminder fire again for the new time.
        $rescheduled = $forum->scheduled_at
            && $forum->scheduled_at->ne(\Illuminate\Support\Carbon::parse($validated['scheduled_at']));

        $forum->fill([
            'title' => $validated['title'],
            'topic' => $validated['topic'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'],
            'host_name' => $validated['host_name'] ?? $forum->host_name,
            'recording_url' => $validated['recording_url'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
        ]);

        if ($rescheduled) {
            $forum->reminder_sent_at = null;
            if ($forum->status === CeoForum::STATUS_ENDED) {
                $forum->status = CeoForum::STATUS_SCHEDULED;
            }
        }

        $forum->save();

        return redirect()
            ->route('admin.lms.forums.index')
            ->with('status', 'Forum updated.');
    }

    public function cancelCeoForum(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $forum = CeoForum::query()->findOrFail($id);
        $forum->status = CeoForum::STATUS_CANCELLED;
        $forum->save();

        return redirect()
            ->route('admin.lms.forums.index')
            ->with('status', 'Forum cancelled.');
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

        if (config('saas.training_email_enabled')) {
            $baseUrl = config('saas.frontend_url');
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
