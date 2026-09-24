<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsTeacherCredentialsMail;
use App\Mail\QaInviteMail;
use App\Models\AcademyReport;
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
use App\Models\QaEvent;
use App\Models\QaSlot;
use App\Models\QaTester;
use App\Models\RightsRequest;
use App\Models\CeoForum;
use App\Models\TrainingRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        // staffOnly() hides each academy's owner-mirror row, an actor rather than a hire.
        $teacherCount = (int) LmsTeacher::query()->withoutGlobalScope($scope)->staffOnly()->count();
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

        // Every academy's courses, not just Jorsas Tech's. The three count maps are
        // unscoped for the same reason: a course of another academy would otherwise
        // render with 0 students / 0 classrooms / 0 tasks.
        $studentCounts = $this->hostQuery(LmsStudent::class)
            ->whereNotNull('selected_course_id')
            ->selectRaw('selected_course_id, COUNT(*) as aggregate')
            ->groupBy('selected_course_id')
            ->pluck('aggregate', 'selected_course_id');

        $classroomCounts = $this->hostQuery(LmsClassroom::class)
            ->selectRaw('course_id, COUNT(*) as aggregate')
            ->groupBy('course_id')
            ->pluck('aggregate', 'course_id');

        $taskCounts = $this->hostQuery(\App\Models\LmsTask::class)
            ->selectRaw('course_id, COUNT(*) as aggregate')
            ->groupBy('course_id')
            ->pluck('aggregate', 'course_id');

        $query = $this->hostQuery(LmsCourse::class)->orderByDesc('created_at');

        if ($tenantId = $request->input('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $academyNames = $this->academyNames();

        $coursesList = $query->get()
            ->map(function (LmsCourse $course) use ($studentCounts, $classroomCounts, $taskCounts, $academyNames) {
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
                    'tenant_id' => $course->tenant_id,
                    'academy' => $academyNames[$course->tenant_id] ?? null,
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
            // The academy the course belongs to. Required: TenantAware would
            // otherwise stamp the PRIMARY tenant, so a course created while looking
            // at another academy's row would silently land on Jorsas Tech.
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'is_live_available' => ['nullable', 'boolean'],
            'is_prerecorded_available' => ['nullable', 'boolean'],
        ]);

        // createForTenant, not create(['tenant_id' => ...]): tenant_id is guarded
        // (see TenantAware), so mass assignment drops the key and TenantAware then
        // stamps the BOUND academy — the picker would silently do nothing and the
        // course would land on Jorsas Tech.
        $course = LmsCourse::createForTenant((int) $validated['tenant_id'], [
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

        // Unscoped: the host deletes a course of whichever academy owns it.
        $course = $this->hostQuery(LmsCourse::class)->findOrFail($id);
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
            ['teachersList' => $this->hostQuery(LmsTeacher::class)->staffOnly()->orderByDesc('created_at')->get()]
        ));
    }

    public function createTeacher(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            // See createCourse(): without this, TenantAware stamps the primary
            // tenant and the staffer silently joins Jorsas Tech.
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:lms_teachers,email'],
        ]);

        $password = Str::random(12);

        // createForTenant, not create(['tenant_id' => ...]) — tenant_id is guarded.
        // See TenantAware::createForTenant().
        $teacher = LmsTeacher::createForTenant((int) $validated['tenant_id'], [
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

        // Every academy's, so the pickers below can offer any of them.
        $coursesList = $this->hostQuery(LmsCourse::class)->orderBy('title')->get();
        // staffOnly(): never offer an academy's synthetic owner-mirror as an instructor.
        $teachersList = $this->hostQuery(LmsTeacher::class)->staffOnly()->where('is_active', true)->orderBy('name')->get();

        $courseMap = $coursesList->keyBy('id');
        $teacherMap = $teachersList->keyBy('id');
        $trackMap = $this->hostQuery(LmsTrack::class)->get()->keyBy('id');
        $academyNames = $this->academyNames();

        $classroomsList = $this->hostQuery(LmsClassroom::class)->orderByDesc('created_at')->get()->map(function ($classroom) use ($courseMap, $teacherMap, $academyNames) {

            // The classroom's academy is its course's academy; a classroom carries a
            // tenant_id of its own, and they agree (both are stamped on create).
            return [
                'id' => $classroom->id,
                'title' => $classroom->title,
                'starts_at' => $classroom->starts_at,
                'ends_at' => $classroom->ends_at,
                'meeting_id' => $classroom->meeting_id,
                'meeting_url' => $classroom->meeting_url,
                'course_title' => $courseMap->get($classroom->course_id)?->title,
                'teacher_name' => $teacherMap->get($classroom->teacher_id)?->name,
                'tenant_id' => $classroom->tenant_id,
                'academy' => $academyNames[$classroom->tenant_id] ?? null,
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

        // These rules now match the form that actually posts here. They previously
        // required `track_id` — a column lms_classrooms does not have — so every
        // submission failed validation, while the course, instructor and meeting
        // URL the form does send were silently discarded. Create Classroom has
        // been broken from this page since it was written.
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'meeting_url' => ['required', 'string', 'max:2048'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        // The academy is the course's, so a classroom can never be filed under an
        // academy that does not own its course.
        $course = $this->hostQuery(LmsCourse::class)->findOrFail($validated['course_id']);

        $this->maybeFillPasscode($validated);

        // createForTenant, not create + tenant_id: tenant_id is guarded, so mass
        // assignment would drop it and TenantAware would stamp the bound academy.
        $classroom = LmsClassroom::createForTenant($course->tenant_id, $validated);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($classroom, 201);
        }

        return redirect()->back()->with('status', 'Classroom created successfully.');
    }

    public function deleteClassroom(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $classroom = $this->hostQuery(LmsClassroom::class)->findOrFail($id);
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

        // staffOnly(): never offer an academy's synthetic owner-mirror as an instructor.
        $teachersList = $this->hostQuery(LmsTeacher::class)->staffOnly()->where('is_active', true)->orderBy('name')->get();
        $coursesList = $this->hostQuery(LmsCourse::class)->orderBy('title')->get();
        $batchesList = Batch::query()->orderByDesc('id')->get();

        $teacherMap = $teachersList->keyBy('id');
        $courseMap = $coursesList->keyBy('id');
        $batchMap = $batchesList->keyBy('id');
        $academyNames = $this->academyNames();

        $tracksList = $this->hostQuery(LmsTrack::class)->orderByDesc('created_at')->get()->map(function (LmsTrack $track) use ($teacherMap, $courseMap, $batchMap, $academyNames) {
            return [
                'id' => $track->id,
                'name' => $track->name,
                'teacher_name' => $teacherMap->get($track->instructor_id)?->name,
                'course_title' => $courseMap->get($track->course_id)?->title,
                'batch_name' => $batchMap->get($track->batch_id)?->name,
                'tenant_id' => $track->tenant_id,
                'academy' => $academyNames[$track->tenant_id] ?? null,
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
                // Lifecycle is reported separately from `status`, which stays what
                // it always was: a provisioning marker, not an availability one.
                'lifecycle' => $t->lifecycleState(),
                'deactivated_at' => $t->deactivated_at,
                'purge_after' => $t->purge_after,
                'purged_at' => $t->purged_at,
            ];
        });

        return view('admin.lms.institutes.index', array_merge($this->adminShellData(), [
            'tenantsList' => $tenants,
            'tenantCount' => $tenants->count(),
            // Read from the same config knob the sweep uses, so the window the
            // confirmation dialog promises is the window that actually runs.
            'defaultPurgeWindowDays' => Tenant::purgeWindowDays(),
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

    /**
     * Schedule an institute's closure, 30 days out.
     *
     * This used to be a one-click hard delete of the tenant row, which was worse
     * than it looked: there are no foreign keys from an institute's data to
     * `tenants`, so deleting one did not cascade — it ORPHANED every student,
     * course, enrolment and payment at a dangling tenant_id. `TenantScope` then made
     * them permanently unreachable and the revenue page (which iterates tenants)
     * silently dropped that institute's payments. Unrecoverable, and quietly wrong
     * in the books.
     *
     * Now it arms the same reversible window the rest of the feature uses: the
     * academy goes dark immediately, everything it holds stays exactly where it is,
     * and the platform can undo it from this page until the sweep runs. The owner
     * links and invitations are deliberately left alone for the same reason — they
     * are part of what makes the closure reversible.
     *
     * @see \App\Console\Commands\PurgeScheduledAccounts  what runs on the day
     */
    public function deleteInstitute(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::query()->findOrFail($id);

        if ($tenant->isAcademyPurged()) {
            return $this->instituteRedirect($request, 'That institute has already been closed.', 'error');
        }

        // purge_requested_by records that the PLATFORM asked for this, not the
        // owner, which is what the audit trail and the copy both turn on.
        $tenant->scheduleAcademyPurge(auth()->id());

        $fresh = $tenant->fresh();

        return $this->instituteRedirect(
            $request,
            "{$fresh->name} is scheduled for closure on {$fresh->purge_after->format('j F Y')}. "
                . 'Everything it holds is untouched and you can cancel this until then.',
            'status'
        );
    }

    /** Take an institute offline without scheduling anything. Reversible. */
    public function deactivateInstitute(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::query()->findOrFail($id);

        if ($tenant->isAcademyPurged()) {
            return $this->instituteRedirect($request, 'That institute has already been closed.', 'error');
        }

        $tenant->deactivateAcademy();

        return $this->instituteRedirect(
            $request,
            "{$tenant->name} is offline. Its public page is hidden and it cannot take new students, "
                . 'but its students and staff keep full access.',
            'status'
        );
    }

    /** Put an institute back online / cancel a scheduled closure. */
    public function reactivateInstitute(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::query()->findOrFail($id);

        if ($tenant->isAcademyPurged()) {
            return $this->instituteRedirect($request, 'That institute has already been closed.', 'error');
        }

        $wasScheduled = $tenant->isPurgeScheduled();
        $tenant->reactivateAcademy();

        return $this->instituteRedirect(
            $request,
            $wasScheduled
                ? "The scheduled closure of {$tenant->name} has been cancelled and it is live again."
                : "{$tenant->name} is live again.",
            'status'
        );
    }

    /** One response shape for both the JSON callers and the Blade buttons. */
    private function instituteRedirect(Request $request, string $message, string $key)
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([$key === 'error' ? 'message' : 'status' => $message], $key === 'error' ? 422 : 200);
        }

        return redirect()->back()->with($key, $message);
    }

    /* ---------------------------------------------------------------------
     | Platform safety queues
     |
     | Both of these cross tenant boundaries by definition — a report is about
     | one academy and read by the platform, and a rights request may have no
     | tenant at all. host.primary binds the PRIMARY tenant for every host
     | request, so both models' TenantScope is dropped explicitly; without that
     | these pages would quietly show only Jorsas' own academy's rows.
     --------------------------------------------------------------------- */

    /** Every academy a student has reported, grouped so one academy reads as one problem. */
    public function reportsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $showResolved = $request->boolean('resolved');

        $query = AcademyReport::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->orderByDesc('id');

        if (! $showResolved) {
            $query->open();
        }

        $reports = $query->limit(300)->get();

        // Students and schools are looked up in bulk rather than through the
        // relations: both models are tenant-scoped and the host request has the
        // primary tenant bound, so `$report->student` would come back null for
        // every academy except Jorsas' own.
        $students = LmsStudent::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->whereIn('id', $reports->pluck('student_id')->filter()->unique())
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->keyBy('id');

        $tenants = Tenant::query()
            ->whereIn('id', $reports->pluck('tenant_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $groups = $reports
            ->groupBy('tenant_id')
            ->map(fn ($rows, $tenantId) => [
                'tenant' => $tenants[$tenantId] ?? null,
                'reports' => $rows->map(function (AcademyReport $r) use ($students) {
                    $student = $students[$r->student_id] ?? null;

                    return [
                        'id' => $r->id,
                        'category_label' => AcademyReport::CATEGORIES[$r->category] ?? $r->category,
                        'details' => $r->details,
                        'status' => $r->status,
                        'status_class' => match ($r->status) {
                            'resolved' => 'active',
                            'dismissed' => 'danger',
                            'reviewing' => 'warning',
                            default => '',
                        },
                        'resolution_note' => $r->resolution_note,
                        'student' => $student
                            ? (trim("{$student->first_name} {$student->last_name}") ?: $student->email)
                            : 'A student (account since removed)',
                        'student_email' => $student?->email ?? '—',
                        'age' => $r->created_at?->diffForHumans(),
                        'settled' => in_array($r->status, ['resolved', 'dismissed'], true),
                    ];
                }),
            ])
            // Academies with the most open complaints first: the repeat offender
            // is the thing worth looking at, and it should not be found by
            // scrolling.
            ->sortByDesc(fn ($g) => $g['reports']->count())
            ->values();

        return view('admin.lms.reports.index', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'reports',
            'groups' => $groups,
            'showResolved' => $showResolved,
        ]));
    }

    /* ---------------------------------------------------------------------
     | QA testing passes
     |
     | Not tenant-scoped at all, deliberately: QaEvent/QaSlot/QaTester sit outside
     | the TenantAware trait, because the public register and join endpoints run
     | with nothing bound and a scoped lookup would fail closed on them. So there
     | is no TenantScope to drop here, unlike every other host page. See the class
     | docblock on App\Models\QaEvent.
     --------------------------------------------------------------------- */

    /**
     * Every QA testing event, its rooms, and who signed up for each.
     *
     * This is the sheet the team running the session works from: per room, who is
     * expected, how many actually opened the room, and the two things that go wrong
     * on the day, which are a link that never arrived (re-send) and someone who
     * should not be in the room (remove).
     */
    public function qaEventsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $events = QaEvent::query()->orderByDesc('id')->get();

        $slots = QaSlot::query()
            ->whereIn('qa_event_id', $events->pluck('id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Testers are fetched once for every event and grouped in memory rather
        // than per-slot: a slot list is short but the tester list is not, and one
        // query per slot is exactly the N+1 that makes a 250-row page crawl.
        $testers = QaTester::query()
            ->whereIn('qa_event_id', $events->pluck('id'))
            ->orderBy('name')
            ->get()
            ->groupBy('qa_slot_id');

        // Per-event totals counted once, not per event inside the map below.
        $testerTotals = $testers->flatten(1)->groupBy('qa_event_id')->map->count();

        // The academy behind each event, resolved from the one id => name map the
        // host shell already builds. Worth showing: the page lists every event on
        // the platform, and "iungo x Jorsas Tech QA Testing" is not the name a
        // second academy's event will have.
        $academyNames = $this->academyNames();

        $payload = $events->map(function (QaEvent $event) use ($slots, $testers, $testerTotals, $academyNames) {
            $eventSlots = $slots->where('qa_event_id', $event->id)->values();

            return [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'academy' => $academyNames[$event->tenant_id] ?? null,
                'is_open' => $event->isOpen(),
                'is_active' => (bool) $event->is_active,
                'starts_at' => $event->starts_at?->format('d M Y, H:i'),
                'ends_at' => $event->ends_at?->format('d M Y, H:i'),
                'register_url' => $event->registerUrl(),
                'host_url' => $event->hostUrl(),
                'total_testers' => (int) ($testerTotals[$event->id] ?? 0),
                'slots' => $eventSlots->map(function (QaSlot $slot) use ($testers) {
                    $rows = $testers->get($slot->id, collect());

                    return [
                        'id' => $slot->id,
                        'label' => $slot->label,
                        'window' => $slot->windowLabel(),
                        'is_open' => $slot->isOpen(),
                        'capacity' => $slot->capacity,
                        'signed_up' => $rows->whereNull('removed_at')->count(),
                        // The number that actually matters on the day: how many of
                        // the people who signed up ever opened the room.
                        'attended' => $rows->whereNotNull('joined_at')->whereNull('removed_at')->count(),
                        'testers' => $rows->map(fn (QaTester $t) => [
                            'id' => $t->id,
                            'name' => $t->name,
                            'email' => $t->email,
                            'phone' => $t->phone,
                            'join_url' => $t->joinUrl(),
                            'joined_at' => $t->joined_at?->format('d M H:i'),
                            'emailed_at' => $t->last_emailed_at?->diffForHumans(),
                            'removed' => (bool) $t->removed_at,
                        ])->values(),
                    ];
                })->values(),
            ];
        });

        return view('admin.lms.qa-events', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'qa-events',
            'events' => $payload,
            'qaEnabled' => (bool) config('saas.qa_events_enabled'),
        ]));
    }

    /** Re-send one tester's join link. The most common support request on the day. */
    public function resendQaInvite(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tester = QaTester::query()->with(['event', 'slot'])->findOrFail($id);

        if ($tester->removed_at) {
            // Says what to actually do rather than just refusing: a cancelled pass
            // has to be restored before its link means anything again, and the
            // button to do that is on the same row.
            return $this->instituteRedirect($request, 'That testing pass is cancelled. Restore it first, then resend.', 'error');
        }

        try {
            Mail::to($tester->email)->send(new QaInviteMail(
                $tester,
                $tester->event->testerJoinUrl($tester->token),
            ));
        } catch (\Throwable $e) {
            Log::warning('QA invite resend failed', ['qa_tester_id' => $tester->id, 'err' => $e->getMessage()]);

            return $this->instituteRedirect($request, 'The email could not be sent. Check the mail configuration.', 'error');
        }

        $tester->forceFill(['last_emailed_at' => now()])->save();

        // A host-triggered resend deliberately ignores the public endpoint's
        // cooldown: the host is looking at the person who is standing there without
        // a link, and "try again in two minutes" is not an answer they can use.
        return $this->instituteRedirect($request, "Join link sent to {$tester->email}.", 'status');
    }

    /**
     * Revoke a tester's link without deleting the row.
     *
     * Removal is a flag, not a delete, so the record of who registered survives
     * and the person cannot undo it by submitting the signup form again (the
     * register endpoint refuses a removed tester). Re-instating is a manual
     * decision, which is the point.
     */
    public function removeQaTester(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tester = QaTester::query()->findOrFail($id);

        $tester->forceFill(['removed_at' => now()])->save();

        return $this->instituteRedirect($request, "{$tester->name}'s testing pass has been cancelled.", 'status');
    }

    /**
     * Undo a removal.
     *
     * This exists because removing is a one-click guess made under time pressure,
     * and without it the only way back from removing the wrong person is a database
     * edit. The tester's own token was never rotated, so restoring is a single
     * field write and their existing link works again immediately.
     */
    public function restoreQaTester(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $tester = QaTester::query()->findOrFail($id);

        $tester->forceFill(['removed_at' => null])->save();

        return $this->instituteRedirect($request, "{$tester->name} is on the list again. Resend their link if they need it.", 'status');
    }

    /** Record a decision on one report. Does not email the student — see below. */
    public function updateReport(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', AcademyReport::STATUSES)],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = AcademyReport::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->findOrFail($id);

        // 'open' is a valid stored value but never a decision someone makes, so
        // it is not reachable from this form — the validation list is wider than
        // the buttons on purpose, because the column's vocabulary and the set of
        // actions a human can take are not the same list.
        if ($validated['status'] === 'open') {
            return $this->instituteRedirect($request, 'Choose reviewing, resolved or dismissed.', 'error');
        }

        $report->forceFill([
            'status' => $validated['status'],
            'resolution_note' => $validated['resolution_note'] ?: $report->resolution_note,
            'handled_by' => $request->user()?->getKey(),
            'handled_at' => now(),
        ])->save();

        // Deliberately NO email to the student here. A report is a complaint
        // about the academy's own staff, and telling the academy's student portal
        // that the platform has taken action is a message that needs a human's
        // wording, not a template's. The platform replies by email from the queue
        // once it has decided what to say.
        return $this->instituteRedirect(
            $request,
            'Report marked ' . $validated['status'] . '.',
            'status'
        );
    }

    /** The data-rights queue. */
    public function rightsRequestsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $showClosed = $request->boolean('closed');

        $query = RightsRequest::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->orderByDesc('id');

        if (! $showClosed) {
            $query->open();
        }

        $requests = $query->limit(300)->get();

        $tenants = Tenant::query()
            ->whereIn('id', $requests->pluck('tenant_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return view('admin.lms.rights.index', array_merge($this->adminShellData(), [
            'activeLmsPage' => 'rights',
            'showClosed' => $showClosed,
            'requests' => $requests->map(fn (RightsRequest $r) => [
                'id' => $r->id,
                'type_label' => RightsRequest::TYPES[$r->type] ?? $r->type,
                'details' => $r->details,
                'status' => $r->status,
                'status_class' => match ($r->status) {
                    'completed' => 'active',
                    'refused' => 'danger',
                    'in_progress' => 'warning',
                    default => '',
                },
                'response_note' => $r->response_note,
                'requester_role' => ucfirst($r->requester_role),
                'requester_email' => $r->requester_email,
                'academy' => $tenants[$r->tenant_id]?->name ?? null,
                'age' => $r->created_at?->diffForHumans(),
                'settled' => in_array($r->status, ['completed', 'refused'], true),
            ]),
        ]));
    }

    /**
     * Move a rights request on, and email the requester when the request is
     * SETTLED (completed or refused) — an email per status wobble would be noise,
     * and 'in_progress' is an internal note-to-self.
     *
     * The reply goes to the SNAPSHOT address on the row, not to any account: an
     * erasure request has to remain answerable after the account it names has
     * been anonymised, which is the whole reason the column exists.
     */
    public function updateRightsRequest(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', RightsRequest::STATUSES)],
            'response_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $rightsRequest = RightsRequest::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->findOrFail($id);

        if ($validated['status'] === 'open') {
            return $this->instituteRedirect($request, 'Choose in progress, completed or refused.', 'error');
        }

        // Completing or refusing is a reply to a person, so a note is required.
        // Without it the requester would receive an email saying their request
        // was answered and nothing else.
        $settling = in_array($validated['status'], ['completed', 'refused'], true);

        if ($settling && trim((string) $validated['response_note']) === '') {
            return $this->instituteRedirect(
                $request,
                'Write what you are telling the requester — completing or refusing sends it to them.',
                'error'
            );
        }

        $rightsRequest->forceFill([
            'status' => $validated['status'],
            'response_note' => $validated['response_note'] ?: $rightsRequest->response_note,
            'handled_by' => $request->user()?->getKey(),
            'handled_at' => now(),
        ])->save();

        $emailed = false;

        if ($settling && $rightsRequest->requester_email) {
            $tenant = $rightsRequest->tenant_id
                ? Tenant::query()->find($rightsRequest->tenant_id)
                : null;

            try {
                \Illuminate\Support\Facades\Mail::to($rightsRequest->requester_email)->send(
                    \App\Mail\RightsRequestMail::make('answered', [
                        'name' => 'there',
                        'academy' => $tenant?->name,
                        'tenant_id' => $tenant?->id,
                        'type' => RightsRequest::TYPES[$rightsRequest->type] ?? $rightsRequest->type,
                        'details' => $rightsRequest->details,
                        'response' => $rightsRequest->response_note,
                    ])
                );
                $emailed = true;
            } catch (\Throwable $e) {
                // The decision is saved either way; a mail failure must not make
                // the operator think their decision was lost.
                report($e);
            }
        }

        $message = 'Request marked ' . str_replace('_', ' ', $validated['status']) . '.';

        if ($settling) {
            $message .= $emailed
                ? ' The requester has been emailed.'
                : ' The requester could NOT be emailed — no address on file, or the mail failed. Check the log.';
        }

        return $this->instituteRedirect($request, $message, 'status');
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

        // The academy is the course's, so a track can never land in an academy
        // that does not own its course. Every course id here is also unbounded by
        // `exists`, which validates on the raw builder and ignores global scopes
        // — so the cross-tenant read below is deliberate, not a leak.
        $course = $this->hostQuery(LmsCourse::class)->findOrFail($validated['course_id']);

        $track = LmsTrack::createForTenant($course->tenant_id, $validated);

        LmsGroupChat::firstOrCreateForTenant($track->tenant_id, ['track_id' => $track->id]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($track, 201);
        }

        return redirect()->back()->with('status', 'Track created successfully.');
    }

    public function deleteTrack(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $track = $this->hostQuery(LmsTrack::class)->findOrFail($id);
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

        $tracks = $this->hostQuery(LmsTrack::class)
            // The eager-loaded relations carry TenantScope too, so without
            // dropping it on each one every non-JIT track would report a null
            // teacher and course — the row would render, but empty.
            ->with([
                'teacher' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
                'course' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
                'batch',
            ])
            ->orderByDesc('created_at')
            ->get();

        $academyNames = $this->academyNames();

        $tracks = $tracks->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'teacher_name' => $t->teacher?->name,
            'course_title' => $t->course?->title,
            'batch_name' => $t->batch?->name,
            'tenant_id' => $t->tenant_id,
            'academy' => $academyNames[$t->tenant_id] ?? null,
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

        // Same rule as createTrack: the academy follows the course.
        $course = $this->hostQuery(LmsCourse::class)->findOrFail($validated['course_id']);

        $track = LmsTrack::createForTenant($course->tenant_id, $validated);

        LmsGroupChat::firstOrCreateForTenant($track->tenant_id, ['track_id' => $track->id]);

        return response()->json($track, 201);
    }

    public function updateTrack(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $track = $this->hostQuery(LmsTrack::class)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'instructor_id' => ['nullable', 'integer', 'exists:lms_teachers,id'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
        ]);

        // Moving a track to another academy's course must move the track with it,
        // or the row stays filed under an academy that no longer owns its course.
        // forceFill because tenant_id is guarded and update() would drop it.
        if (! empty($validated['course_id'])) {
            $course = $this->hostQuery(LmsCourse::class)->findOrFail($validated['course_id']);
            $track->forceFill(['tenant_id' => $course->tenant_id]);
        }

        $track->fill($validated)->save();

        return response()->json($track);
    }

    public function deleteTrackApi(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $this->hostQuery(LmsTrack::class)->findOrFail($id)->delete();

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

        $student = $this->hostQuery(LmsStudent::class)->findOrFail($validated['student_id']);
        $track = $this->hostQuery(LmsTrack::class)->findOrFail($validated['track_id']);

        // A cohort placement only means something inside one academy. Enrolling a
        // student into another academy's cohort would file them into a classroom,
        // group chat and DM thread they can never reach — and both ids arrive from
        // the request, so nothing else stops the pairing.
        if ((int) $student->tenant_id !== (int) $track->tenant_id) {
            return response()->json([
                'message' => 'That student and that cohort belong to different academies.',
            ], 422);
        }

        // All three file under the COHORT's academy, via the trait's explicit-tenant
        // helpers: tenant_id is guarded, so passing it to updateOrCreate /
        // firstOrCreate is dropped on create and TenantAware then stamps whichever
        // academy the back office has bound.
        $enrollment = LmsEnrollment::query()->withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->first();

        if ($enrollment) {
            $enrollment->update(['track_id' => $track->id]);
        } else {
            $enrollment = LmsEnrollment::createForTenant($track->tenant_id, [
                'student_id' => $student->id,
                'track_id' => $track->id,
            ]);
        }

        LmsGroupChat::firstOrCreateForTenant($track->tenant_id, ['track_id' => $track->id]);

        LmsDmThread::firstOrCreateForTenant($track->tenant_id, [
            'student_id' => $student->id,
            'instructor_id' => $track->instructor_id,
            'track_id' => $track->id,
        ]);

        return response()->json($enrollment, 201);
    }

    public function studentsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        // Unscoped: the back office lists every academy's students, and
        // TenantScope is bound to the primary tenant here. The eager-loaded
        // course needs the same treatment or it resolves to null for every
        // student outside JIT.
        $query = $this->hostQuery(LmsStudent::class)
            ->with(['course' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
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

        if ($tenantId = $request->input('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $students = $query->orderBy('created_at', 'desc')->paginate(50)->withQueryString();

        // Which of the students on this page have paid, in ONE query rather than a
        // query per row. A paid student cannot be deleted (see
        // LmsStudent::purgeBlockedReason()), and the page has to say so up front
        // instead of letting the host find out from a 422.
        $registrationIds = $students->getCollection()
            ->pluck('training_registration_id')
            ->filter()
            ->unique()
            ->values();

        $paidRegistrations = $registrationIds->isEmpty()
            ? collect()
            : \App\Models\Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('registration_id', $registrationIds)
                ->where('status', 'success')
                ->pluck('registration_id')
                ->unique()
                ->flip();

        // Course and cohort titles repeat across academies, so each option is
        // labelled with its academy — otherwise the filter is a list of
        // indistinguishable "Web Development" entries.
        $academyNames = $this->academyNames();

        $coursesList = $this->hostQuery(LmsCourse::class)
            ->orderBy('title')
            ->get(['id', 'title', 'tenant_id'])
            ->each(fn ($c) => $c->academy = $academyNames[$c->tenant_id] ?? null);

        $tracksList = $this->hostQuery(LmsTrack::class)
            ->orderBy('name')
            ->get(['id', 'name', 'tenant_id'])
            ->each(fn ($t) => $t->academy = $academyNames[$t->tenant_id] ?? null);

        $shell = $this->adminShellData();

        return view('admin.lms.students.index', array_merge($shell, [
            'students'    => $students,
            'coursesList' => $coursesList,
            'tracksList'  => $tracksList,
            'paidRegistrations' => $paidRegistrations,
        ]));
    }

    public function deleteStudent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $student = $this->hostQuery(LmsStudent::class)->findOrFail($id);

        // Two bugs met in this one line. It hard-deleted, while every other delete
        // path on the platform schedules a 30-day reversible purge — so Jorsas'
        // back office could destroy a student's enrolments, attendance and
        // certificate serials outright, with no undo and no trace. And it skipped
        // the paid-student rule entirely, which is the whole of "once a student
        // has paid, the account cannot be deleted". Same guard the academy and the
        // student's own profile go through, so all three agree.
        if ($reason = $student->purgeBlockedReason()) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => $reason], 422);
            }

            return redirect()->back()->with('error', $reason);
        }

        $student->schedulePurge();

        $message = 'Student deletion scheduled. Their records stay intact for '
            . \App\Traits\HasAccountLifecycle::purgeWindowDays()
            . ' days and can be restored until then.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->back()->with('status', $message);
    }

    public function agentsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agents = $this->hostQuery(\App\Models\Agent::class)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        $academyNames = $this->academyNames();

        $shell = $this->adminShellData();

        return view('admin.lms.agents', array_merge($shell, [
            'agents' => $agents,
            'academyNames' => $academyNames,
        ]));
    }

    public function approveAgent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agent = $this->hostQuery(\App\Models\Agent::class)->findOrFail($id);

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

        $this->hostQuery(\App\Models\Agent::class)->findOrFail($id)->update(['status' => 'rejected']);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Agent rejected.']);
        }

        return redirect()->back()->with('status', 'Agent rejected.');
    }

    public function deleteAgent(Request $request, int $id)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $agent = $this->hostQuery(\App\Models\Agent::class)->findOrFail($id);

        // Each relation carries its own global scope, so deleting through the
        // relationship without dropping it would remove the agent's row while
        // leaving every session, notification and commission behind — orphaned
        // rows that keep counting toward an academy's agent payouts.
        $agent->sessions()->withoutGlobalScope(TenantScope::class)->delete();
        $agent->notifications()->withoutGlobalScope(TenantScope::class)->delete();
        $agent->commissions()->withoutGlobalScope(TenantScope::class)->delete();
        $agent->delete();

        return redirect()->back()->with('status', 'Agent deleted successfully.');
    }

    public function withdrawalRequestsPage(Request $request)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        // `agent` is eager-loaded through a tenant-scoped relation, so without
        // dropping the scope the withdrawal rows render with a blank agent.
        $withdrawals = $this->hostQuery(\App\Models\AgentCommission::class)
            ->where('type', 'withdrawal')
            ->where('status', 'withdrawal_requested')
            ->with(['agent' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderByDesc('id')
            ->paginate(50);

        $paid = $this->hostQuery(\App\Models\AgentCommission::class)
            ->where('type', 'withdrawal')
            ->where('status', 'paid')
            ->with(['agent' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'paid-page');

        $academyNames = $this->academyNames();

        $shell = $this->adminShellData();

        return view('admin.lms.agent-withdrawals', array_merge($shell, [
            'withdrawals' => $withdrawals,
            'paid' => $paid,
            'academyNames' => $academyNames,
        ]));
    }

    public function payCommissions(Request $request, int $agentId)
    {
        $this->ensureLmsEnabled();
        $this->ensureSuperAdmin($request);

        $this->hostQuery(\App\Models\AgentCommission::class)
            ->where('agent_id', $agentId)
            ->where('type', 'withdrawal')
            ->whereIn('status', ['withdrawal_requested'])
            ->update(['status' => 'paid', 'paid_at' => now()]);

        return redirect()->back()->with('status', 'Withdrawal marked as paid.');
    }
}
