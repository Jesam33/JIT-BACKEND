<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsPasswordResetMail;
use App\Models\LmsCourse;
use App\Models\LmsCertificate;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsNotification;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Read-only data for the institute owner's admin dashboard (the sidebar UI at
 * /lms/admin): headline counts plus the students / staff / courses / tracks
 * lists an owner manages.
 *
 * Like TenantBillingController these routes sit inside `tenant.required`, but
 * authorization does NOT trust the middleware-bound tenant. Each request derives
 * the tenant from the owner's own session row (tenant_id) and confirms
 * tenant_admins membership — a student or staff bearer token also binds a tenant,
 * so binding alone is not authorization. Once the owner is confirmed we (re)bind
 * that exact tenant into the container so every TenantAware query below is scoped
 * to their organisation.
 */
class OwnerAdminController extends BaseLmsController
{
    /**
     * Resolve the authenticated owner and their tenant from the bearer session,
     * binding the tenant so TenantScope filters the list queries. Returns
     * [Tenant, User] or null when the caller is not an owner of a tenant.
     */
    protected function ownerContext(Request $request): ?array
    {
        $session = $this->sessionFromRequest($request, 'owner');
        if (! $session) {
            return null;
        }

        $tenantId = $session->tenant_id
            ?? (app()->bound('currentTenant') && app('currentTenant') ? app('currentTenant')->id : null);
        if (! $tenantId) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        $isAdmin = DB::table('tenant_admins')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $session->user_id)
            ->exists();
        if (! $isAdmin) {
            return null;
        }

        // Bind the confirmed tenant so every TenantAware read below is scoped to
        // this owner's organisation, independent of middleware ordering.
        app()->instance('currentTenant', $tenant);

        return [$tenant, User::find($session->user_id)];
    }

    /**
     * Dashboard overview: headline counts, plan/subscription state, and the most
     * recent students — everything the landing dashboard card grid needs.
     */
    public function overview(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        $recentStudents = LmsStudent::query()
            ->latest('id')
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at'])
            ->map(fn (LmsStudent $s) => [
                'id' => $s->id,
                'name' => trim("{$s->first_name} {$s->last_name}") ?: ($s->email ?? 'Student'),
                'email' => $s->email,
                'created_at' => $s->created_at,
            ]);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ],
            'owner' => [
                'email' => $owner?->email,
                'name' => trim(($owner?->first_name ?? '') . ' ' . ($owner?->last_name ?? '')) ?: $owner?->email,
            ],
            'plan' => $tenant->plan ?? 'free',
            'subscription_status' => $tenant->subscription_status ?? 'active',
            'current_period_end' => $tenant->current_period_end,
            'counts' => [
                'students' => LmsStudent::query()->count(),
                'staff' => LmsTeacher::query()->count(),
                'courses' => LmsCourse::query()->count(),
                'tracks' => LmsTrack::query()->count(),
            ],
            'recent_students' => $recentStudents,
            'branding' => $this->brandingFor($tenant),
        ]);
    }

    /**
     * Real analytics for the owner dashboard (fix #1 — no dummy data). Every query
     * below is tenant-scoped by ownerContext() binding currentTenant, so these
     * aggregates cover ONLY this institute:
     *  - 6-month series: new students, new enrolments, successful-payment revenue
     *  - registration funnel: pending / approved / rejected
     *  - headline totals: lifetime revenue, students, enrolments, active courses
     */
    public function analytics(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        // Build the last 6 whole-month buckets, oldest → newest (e.g. Mar…Aug).
        $start = now()->startOfMonth()->subMonths(5);
        $months = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 6; $i++) {
            $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M')];
            $cursor->addMonth();
        }
        // Map a "Y-m => value" result onto the fixed 6-month axis (0 for gaps).
        $align = fn (array $map): array => array_map(fn ($m) => $map[$m['key']] ?? 0, $months);
        $bucket = "DATE_FORMAT(created_at, '%Y-%m')";

        $studentsByMonth = LmsStudent::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("$bucket as ym, COUNT(*) as c")
            ->groupByRaw($bucket)
            ->pluck('c', 'ym')
            ->map(fn ($v) => (int) $v)
            ->all();

        $enrollByMonth = LmsEnrollment::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("$bucket as ym, COUNT(*) as c")
            ->groupByRaw($bucket)
            ->pluck('c', 'ym')
            ->map(fn ($v) => (int) $v)
            ->all();

        $revenueByMonth = Payment::query()
            ->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->selectRaw("$bucket as ym, SUM(amount) as s")
            ->groupByRaw($bucket)
            ->pluck('s', 'ym')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        $regByStatus = TrainingRegistration::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return response()->json([
            'months' => array_map(fn ($m) => $m['label'], $months),
            'series' => [
                'students' => $align($studentsByMonth),
                'enrollments' => $align($enrollByMonth),
                'revenue' => $align($revenueByMonth),
            ],
            'totals' => [
                'revenue' => round((float) Payment::query()->where('status', 'success')->sum('amount'), 2),
                'students' => LmsStudent::query()->count(),
                'enrollments' => LmsEnrollment::query()->count(),
                'active_courses' => LmsCourse::query()->where('is_active', true)->count(),
            ],
            'registrations' => [
                'pending' => $regByStatus['pending'] ?? 0,
                'approved' => $regByStatus['approved'] ?? 0,
                'rejected' => $regByStatus['rejected'] ?? 0,
                'total' => array_sum($regByStatus),
            ],
            'currency' => '₦',
        ]);
    }

    /**
     * All students in the owner's institute.
     */
    public function students(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $students = LmsStudent::query()
            ->with('course:id,title')
            ->latest('id')
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'selected_course_id', 'learning_mode', 'onboarding_completed', 'created_at'])
            ->map(fn (LmsStudent $s) => [
                'id' => $s->id,
                'name' => trim("{$s->first_name} {$s->last_name}") ?: ($s->email ?? 'Student'),
                'email' => $s->email,
                'phone' => $s->phone,
                'course' => $s->course?->title,
                'learning_mode' => $s->learning_mode,
                'onboarding_completed' => (bool) $s->onboarding_completed,
                'created_at' => $s->created_at,
            ]);

        return response()->json(['tenant_id' => $tenant->id, 'students' => $students]);
    }

    /**
     * Remove a student from the owner's institute (tenant-scoped findOrFail).
     *
     * Both models are TenantAware, so an id belonging to another institute 404s
     * here rather than crossing tenants. We drop the student's cohort
     * enrollments first so no orphan roster rows linger after the delete.
     */
    public function destroyStudent(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $student = LmsStudent::query()->findOrFail($id);

        LmsEnrollment::query()->where('student_id', $student->id)->delete();
        $student->delete();

        return response()->json(['message' => 'Student removed.']);
    }

    /**
     * Re-send the "set your password" invite to a student — the same
     * password-reset link the bulk importer emails, so it lands on this
     * tenant's student setup page. Best-effort: the caller is told honestly
     * whether the email actually went out (mirrors inviteStaff).
     */
    public function resendStudentInvite(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $student = LmsStudent::query()->findOrFail($id);
        if (! $student->email) {
            return response()->json(['message' => 'This student has no email address on file.'], 422);
        }

        $sent = false;
        try {
            $token = $this->createPasswordResetToken('student', $student->email);
            $link = $this->buildResetLink('student', $student->email, $token);
            Mail::to($student->email)->send(new LmsPasswordResetMail($student->first_name ?: 'there', 'Student Portal', $link));
            $sent = true;
        } catch (\Throwable $e) {
            Log::warning('Failed to resend student invite', ['student_id' => $student->id, 'err' => $e->getMessage()]);
        }

        return response()->json([
            'email_sent' => $sent,
            'message' => $sent
                ? "Invite re-sent to {$student->email}."
                : "Could not send the invite email to {$student->email}. Check your mail settings and try again.",
        ]);
    }

    /**
     * All staff (teachers/admins) in the owner's institute.
     */
    public function staff(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $staff = LmsTeacher::query()
            ->latest('id')
            ->get(['id', 'name', 'email', 'role', 'phone', 'is_active', 'created_at'])
            ->map(fn (LmsTeacher $t) => [
                'id' => $t->id,
                'name' => $t->name ?: $t->email,
                'email' => $t->email,
                'role' => $t->role ?: 'teacher',
                'phone' => $t->phone,
                'is_active' => (bool) $t->is_active,
                'created_at' => $t->created_at,
            ]);

        return response()->json(['tenant_id' => $tenant->id, 'staff' => $staff]);
    }

    /**
     * Remove a staff member (teacher/admin) from the institute.
     *
     * SAFETY: lms_tracks.instructor_id is a NOT-NULL foreign key with
     * cascadeOnDelete — deleting a teacher who still leads a cohort would
     * silently cascade-delete that cohort (and, in turn, its enrolments, DM
     * threads and group chat). So we refuse while the teacher is assigned to any
     * cohort and tell the owner to reassign it first (Tracks & Cohorts page).
     * Every other teacher reference (tasks, modules, classrooms, graded-by) is
     * nullOnDelete; their own DM threads + notifications cascade harmlessly.
     */
    public function destroyStaff(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $teacher = LmsTeacher::query()->findOrFail($id);

        $cohortCount = LmsTrack::query()->where('instructor_id', $teacher->id)->count();
        if ($cohortCount > 0) {
            return response()->json([
                'message' => "{$teacher->name} leads {$cohortCount} cohort" . ($cohortCount === 1 ? '' : 's')
                    . '. Reassign ' . ($cohortCount === 1 ? 'it' : 'them') . ' to another instructor on the '
                    . 'Tracks & Cohorts page before removing this staff member.',
            ], 422);
        }

        $teacher->delete();

        return response()->json(['message' => "Removed {$teacher->name}."]);
    }

    /**
     * Re-send the "set your password" invite to a staff member — the same setup
     * link OwnerOnboardingController::inviteStaff emails, so it lands on this
     * tenant's staff activation page. Best-effort: the caller is told honestly
     * whether the email actually went out (mirrors resendStudentInvite).
     */
    public function resendStaffInvite(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $teacher = LmsTeacher::query()->findOrFail($id);
        if (! $teacher->email) {
            return response()->json(['message' => 'This staff member has no email address on file.'], 422);
        }

        $sent = false;
        try {
            $token = $this->createPasswordResetToken('staff', $teacher->email);
            $link = $this->buildSetupLink('staff', $teacher->email, $token);
            Mail::to($teacher->email)->send(new LmsPasswordResetMail($teacher->name ?: 'there', 'Staff Portal', $link));
            $sent = true;
        } catch (\Throwable $e) {
            Log::warning('Failed to resend staff invite', ['teacher_id' => $teacher->id, 'err' => $e->getMessage()]);
        }

        return response()->json([
            'email_sent' => $sent,
            'message' => $sent
                ? "Invite re-sent to {$teacher->email}."
                : "Could not send the invite email to {$teacher->email}. Check your mail settings and try again.",
        ]);
    }

    /**
     * Enable or suspend a staff member. is_active gates staff login
     * (StaffAuthController::login), so suspending immediately blocks their portal
     * access WITHOUT deleting anything — the reversible alternative to removal,
     * and the only way to bench an instructor who still leads a cohort.
     */
    public function setStaffActive(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $teacher = LmsTeacher::query()->findOrFail($id);
        $teacher->is_active = $validated['is_active'];
        $teacher->save();

        return response()->json([
            'message' => $teacher->is_active ? "Re-enabled {$teacher->name}." : "Suspended {$teacher->name}.",
            'staff' => [
                'id' => $teacher->id,
                'is_active' => (bool) $teacher->is_active,
            ],
        ]);
    }


    /**
     * All courses in the owner's institute, with student/track counts.
     */
    public function courses(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $courses = LmsCourse::query()
            ->withCount(['students', 'tracks'])
            ->latest('id')
            ->get(['id', 'title', 'slug', 'description', 'requirements', 'price', 'max_students', 'registered_count', 'is_live_available', 'is_prerecorded_available', 'is_active'])
            ->map(fn (LmsCourse $c) => $this->coursePayload($c));

        return response()->json(['tenant_id' => $tenant->id, 'courses' => $courses]);
    }

    /**
     * All tracks/cohorts in the owner's institute, with course + instructor name.
     */
    public function tracks(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $tracks = LmsTrack::query()
            ->with('course:id,title')
            ->latest('id')
            ->get(['id', 'name', 'course_id', 'instructor_id', 'batch_id', 'created_at']);

        // Resolve instructor names in one query (same tenant scope applies).
        $instructorNames = LmsTeacher::query()
            ->whereIn('id', $tracks->pluck('instructor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $payload = $tracks->map(fn (LmsTrack $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'course' => $t->course?->title,
            'course_id' => $t->course_id,
            'instructor' => $t->instructor_id ? ($instructorNames[$t->instructor_id] ?? null) : null,
            'instructor_id' => $t->instructor_id,
            'created_at' => $t->created_at,
        ]);

        return response()->json(['tracks' => $payload]);
    }

    /**
     * Recent institute activity for the topbar notifications bell. Synthesised
     * from existing tables (newest students + staff) so there's no separate
     * notifications table to maintain; the frontend tracks "seen" locally.
     */
    public function notifications(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $students = LmsStudent::query()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at'])
            ->map(fn (LmsStudent $s) => [
                'id' => 'student-' . $s->id,
                'type' => 'student_registered',
                'title' => 'New student joined',
                'body' => trim("{$s->first_name} {$s->last_name}") ?: ($s->email ?? 'A student'),
                'at' => optional($s->created_at)->toIso8601String(),
            ]);

        $staff = LmsTeacher::query()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(fn (LmsTeacher $t) => [
                'id' => 'staff-' . $t->id,
                'type' => 'staff_added',
                'title' => 'New staff member',
                'body' => $t->name ?: ($t->email ?? 'A staff member'),
                'at' => optional($t->created_at)->toIso8601String(),
            ]);

        $items = $students->concat($staff)
            ->filter(fn ($i) => $i['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take(12);

        return response()->json(['notifications' => $items]);
    }

    /**
     * Current white-label branding for this institute.
     */
    public function branding(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        return response()->json([
            'branding' => $this->brandingFor($tenant),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ]);
    }

    /**
     * Update branding (colors / font / logo removal) and, optionally, the
     * institute's display name. Branding is stored in the tenant's `settings`
     * JSON blob under `branding`; the name lives on the tenant row. The `slug`
     * is deliberately frozen — it's the public web address students already
     * have, so renaming never breaks existing links.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_family' => ['nullable', 'string', 'in:default,inter,system,serif,mono,rounded'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_background' => ['nullable', 'boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $branding = (array) ($settings['branding'] ?? []);

        foreach (['primary_color', 'secondary_color', 'background_color', 'font_family'] as $key) {
            if (($validated[$key] ?? null) !== null) {
                $branding[$key] = $validated[$key];
            }
        }
        if ($request->boolean('remove_logo')) {
            $branding['logo_url'] = null;
        }
        // A null background_color means "use the standard theme glow"; a skipped
        // key preserves the stored value, so clearing needs an explicit flag.
        if ($request->boolean('remove_background')) {
            $branding['background_color'] = null;
        }

        $settings['branding'] = $branding;

        $update = ['settings' => $settings];
        // Rename the institute if a non-empty name was sent (slug stays frozen).
        if (($validated['name'] ?? null) !== null && trim($validated['name']) !== '') {
            $update['name'] = trim($validated['name']);
        }
        $tenant->update($update);

        $fresh = $tenant->fresh();

        return response()->json([
            'branding' => $this->brandingFor($fresh),
            'name' => $fresh->name,
            'slug' => $fresh->slug,
        ]);
    }

    /**
     * Upload an institute logo. Mirrors the staff/student photo-upload pattern
     * (public disk + asset() URL) and stores the URL in branding.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $request->validate(['file' => ['required', 'image', 'max:2048']]);

        $path = $request->file('file')->store('tenant-logos', 'public');
        $url = asset('storage/' . $path);

        $settings = (array) ($tenant->settings ?? []);
        $branding = (array) ($settings['branding'] ?? []);
        $branding['logo_url'] = $url;
        $settings['branding'] = $branding;
        $tenant->update(['settings' => $settings]);

        return response()->json(['url' => $url, 'branding' => $this->brandingFor($tenant->fresh())]);
    }

    /**
     * Current public profile (hero/about/contact/socials) for this institute.
     */
    public function profile(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        return response()->json(['profile' => $tenant->profileArray()]);
    }

    /**
     * Update the institute's public profile — the content shown on its /i/{slug}
     * mini-site. Stored in the tenant's `settings` JSON blob under `profile`
     * (no dedicated columns), mirroring updateBranding(). Empty strings are
     * normalised to null so cleared fields disappear from the public page.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $validated = $request->validate([
            'tagline' => ['nullable', 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:5000'],
            'contact' => ['nullable', 'array'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'socials' => ['nullable', 'array'],
            'socials.website' => ['nullable', 'url', 'max:255'],
            'socials.facebook' => ['nullable', 'url', 'max:255'],
            'socials.instagram' => ['nullable', 'url', 'max:255'],
            'socials.twitter' => ['nullable', 'url', 'max:255'],
            'socials.linkedin' => ['nullable', 'url', 'max:255'],
            'remove_cover' => ['nullable', 'boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $profile = (array) ($settings['profile'] ?? []);

        $norm = fn ($v) => (is_string($v) && trim($v) === '') ? null : $v;

        foreach (['tagline', 'about'] as $key) {
            if (array_key_exists($key, $validated)) {
                $profile[$key] = $norm($validated[$key]);
            }
        }

        if (is_array($validated['contact'] ?? null)) {
            $contact = (array) ($profile['contact'] ?? []);
            foreach (['email', 'phone', 'whatsapp', 'address'] as $k) {
                if (array_key_exists($k, $validated['contact'])) {
                    $contact[$k] = $norm($validated['contact'][$k]);
                }
            }
            $profile['contact'] = $contact;
        }

        if (is_array($validated['socials'] ?? null)) {
            $socials = (array) ($profile['socials'] ?? []);
            foreach (['website', 'facebook', 'instagram', 'twitter', 'linkedin'] as $k) {
                if (array_key_exists($k, $validated['socials'])) {
                    $socials[$k] = $norm($validated['socials'][$k]);
                }
            }
            $profile['socials'] = $socials;
        }

        if ($request->boolean('remove_cover')) {
            $profile['cover_url'] = null;
        }

        $settings['profile'] = $profile;
        $tenant->update(['settings' => $settings]);

        return response()->json(['profile' => $tenant->fresh()->profileArray()]);
    }

    /**
     * Upload the institute's hero cover image for its public page. Mirrors
     * uploadLogo (public disk + asset() URL) but allows a larger file since a
     * cover is a full-width banner. Stored in settings.profile.cover_url.
     */
    public function uploadCover(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $request->validate(['file' => ['required', 'image', 'max:4096']]);

        $path = $request->file('file')->store('tenant-covers', 'public');
        $url = asset('storage/' . $path);

        $settings = (array) ($tenant->settings ?? []);
        $profile = (array) ($settings['profile'] ?? []);
        $profile['cover_url'] = $url;
        $settings['profile'] = $profile;
        $tenant->update(['settings' => $settings]);

        return response()->json(['url' => $url, 'profile' => $tenant->fresh()->profileArray()]);
    }

    /**
     * Merge stored branding over sensible defaults (the current red/blue theme).
     */
    protected function brandingFor(Tenant $tenant): array
    {
        return $tenant->brandingArray();
    }

    /**
     * Current course-fee payout configuration for this institute: whether a
     * Paystack subaccount (the institute's own bank) is linked, and the bank
     * picker data the UI needs. When a subaccount is linked, course fees settle
     * to the institute's bank (minus the platform commission) instead of the
     * platform account — this is the "institute collects the money, not Jorsas"
     * setting. Stored in the tenant's `settings` JSON under `paystack`.
     */
    public function paymentSettings(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $paystack = (array) (data_get($tenant->settings, 'paystack') ?? []);
        $service = app(PaystackService::class);
        $gatewayReady = $service->isConfigured();

        $account = (string) ($paystack['account_number'] ?? '');
        $maskedAccount = strlen($account) >= 4 ? str_repeat('•', max(0, strlen($account) - 4)) . substr($account, -4) : $account;

        return response()->json([
            'payment' => [
                'configured' => ! empty($paystack['subaccount_code']),
                'subaccount_code' => $paystack['subaccount_code'] ?? null,
                'business_name' => $paystack['business_name'] ?? null,
                'bank_code' => $paystack['bank_code'] ?? null,
                'bank_name' => $paystack['bank_name'] ?? null,
                'account_number_masked' => $maskedAccount ?: null,
                'account_name' => $paystack['account_name'] ?? null,
            ],
            'platform_commission_percent' => (float) config('saas.platform_commission_percent', 2),
            'gateway_ready' => $gatewayReady,
            // The bank picker only needs codes when linking a fresh subaccount, and
            // the list is a live Paystack round-trip — only fetch it when the
            // gateway is configured and no subaccount is linked yet.
            'banks' => ($gatewayReady && empty($paystack['subaccount_code'])) ? $service->listBanks() : [],
        ]);
    }

    /**
     * Link (or replace) the institute's Paystack subaccount so course fees settle
     * to its own bank. Two ways in: paste an existing `subaccount_code` directly,
     * or supply bank_code + account_number + business_name and we create the
     * subaccount via Paystack (platform commission from config). Mirrors
     * updateBranding's read-merge-save on the `settings` JSON blob so nothing else
     * stored there is disturbed.
     */
    public function updatePaymentSettings(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $validated = $request->validate([
            'subaccount_code' => ['nullable', 'string', 'max:120'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:20'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'disconnect' => ['nullable', 'boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $paystack = (array) ($settings['paystack'] ?? []);

        // Explicit disconnect: course fees revert to the platform account.
        if ($request->boolean('disconnect')) {
            unset($paystack['subaccount_code'], $paystack['business_name'], $paystack['bank_code'], $paystack['bank_name'], $paystack['account_number'], $paystack['account_name']);
            $settings['paystack'] = $paystack;
            $tenant->update(['settings' => $settings]);

            return response()->json(['message' => 'Payout account disconnected. Course fees will settle to the platform account.', 'configured' => false]);
        }

        // Path A — a subaccount code was pasted directly. Trust it as-is.
        if (! empty($validated['subaccount_code'])) {
            $paystack['subaccount_code'] = trim($validated['subaccount_code']);
            if (! empty($validated['business_name'])) {
                $paystack['business_name'] = trim($validated['business_name']);
            }
        } else {
            // Path B — create a subaccount from bank details.
            $missing = empty($validated['bank_code']) || empty($validated['account_number']) || empty($validated['business_name']);
            if ($missing) {
                return response()->json(['message' => 'Provide a subaccount code, or the bank, account number, and business name to link a payout account.'], 422);
            }

            $service = app(PaystackService::class);
            if (! $service->isConfigured()) {
                return response()->json(['message' => 'The payment gateway is not configured on the platform yet. Try again later.'], 503);
            }

            $commission = (float) config('saas.platform_commission_percent', 2);
            $result = $service->createSubaccount(
                trim($validated['business_name']),
                trim($validated['bank_code']),
                trim($validated['account_number']),
                $commission,
            );

            if (! ($result['status'] ?? false) || empty($result['data']['subaccount_code'])) {
                $message = $result['message'] ?? 'Could not create the payout account. Check the bank and account number.';

                return response()->json(['message' => $message], 422);
            }

            $paystack['subaccount_code'] = $result['data']['subaccount_code'];
            $paystack['business_name'] = trim($validated['business_name']);
            $paystack['bank_code'] = trim($validated['bank_code']);
            $paystack['bank_name'] = $validated['bank_name'] ?? ($result['data']['settlement_bank'] ?? null);
            $paystack['account_number'] = trim($validated['account_number']);
            $paystack['account_name'] = $result['data']['account_name'] ?? null;
        }

        $settings['paystack'] = $paystack;
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Payout account linked. Course fees now settle to your bank.',
            'configured' => true,
            'subaccount_code' => $paystack['subaccount_code'] ?? null,
            'account_name' => $paystack['account_name'] ?? null,
        ]);
    }

    /**
     * Resolve a bank account number to its holder name (Paystack /bank/resolve)
     * so the owner can CONFIRM the account before linking their payout subaccount.
     * Confirmatory only — it links nothing and never 500s: an unresolvable account
     * (typo / wrong bank) comes back as {account_name: null} with a soft message,
     * and a down/unconfigured gateway degrades the same way so linking is never blocked.
     */
    public function resolveBankAccount(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
            'bank_code' => ['required', 'string', 'max:20'],
        ]);

        $service = app(PaystackService::class);
        if (! $service->isConfigured()) {
            return response()->json([
                'account_name' => null,
                'message' => 'The payment gateway is not configured yet.',
            ]);
        }

        $data = $service->resolveAccount($validated['account_number'], $validated['bank_code']);
        $name = is_array($data) ? ($data['account_name'] ?? null) : null;

        return response()->json([
            'account_name' => $name,
            'message' => $name ? null : 'Could not resolve this account. Check the account number and the selected bank.',
        ]);
    }

    // ─── Certificates (institute-issued, admin-only — moved off the staff
    //     portal). Every read/write is tenant-scoped via ownerContext(). ──

    /**
     * Everything the admin certificates page needs in one call: the issued
     * certificates (with student + course names), the students who can receive
     * one (with their enrolled course pre-filled), and the course list.
     */
    public function certificates(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $certificates = LmsCertificate::query()
            ->with(['student:id,first_name,last_name,email', 'course:id,title'])
            ->latest('id')
            ->get()
            ->map(fn (LmsCertificate $c) => [
                'id' => $c->id,
                'student_id' => $c->student_id,
                'student_name' => $c->student
                    ? (trim("{$c->student->first_name} {$c->student->last_name}") ?: ($c->student->email ?? 'Student'))
                    : 'Student',
                'course_id' => $c->course_id,
                'course_title' => $c->course?->title,
                'title' => $c->title,
                'file_url' => $c->file_url,
                'issued_at' => optional($c->issued_at)->toIso8601String(),
            ]);

        $students = LmsStudent::query()
            ->with('course:id,title')
            ->latest('id')
            ->get(['id', 'first_name', 'last_name', 'email', 'selected_course_id'])
            ->map(fn (LmsStudent $s) => [
                'id' => $s->id,
                'name' => trim("{$s->first_name} {$s->last_name}") ?: ($s->email ?? 'Student'),
                'email' => $s->email,
                'course_id' => $s->selected_course_id,
                'course_title' => $s->course?->title,
            ]);

        $courses = LmsCourse::query()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (LmsCourse $c) => ['id' => $c->id, 'title' => $c->title]);

        return response()->json([
            'certificates' => $certificates,
            'students' => $students,
            'courses' => $courses,
        ]);
    }

    /**
     * Issue a certificate to one of this institute's students. Both ids are
     * re-checked against the tenant-scoped models so a foreign student/course
     * can't be smuggled in, and the student is notified so they see it in-app.
     */
    public function issueCertificate(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
            'course_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'file_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $student = LmsStudent::query()->find($validated['student_id']);
        if (! $student) {
            return response()->json(['message' => 'That student does not exist in your institute.'], 422);
        }

        $courseId = null;
        if (! empty($validated['course_id'])) {
            $course = LmsCourse::query()->find($validated['course_id']);
            if (! $course) {
                return response()->json(['message' => 'That course does not exist in your institute.'], 422);
            }
            $courseId = $course->id;
        }

        $certificate = LmsCertificate::query()->create([
            'student_id' => $student->id,
            'course_id' => $courseId,
            'title' => $validated['title'],
            'file_url' => $validated['file_url'] ?? null,
            'issued_at' => now(),
        ]);

        // Let the student know in-app (best-effort — never block issuance on it).
        try {
            LmsNotification::query()->create([
                'student_id' => $student->id,
                'type' => 'certificate',
                'title' => 'You earned a certificate',
                'body' => $validated['title'],
                'reference_type' => 'certificate',
                'reference_id' => $certificate->id,
            ]);
        } catch (\Throwable $e) {
            // Notification is a nicety; a schema/column hiccup must not 500 the issue.
        }

        $certificate->load(['student:id,first_name,last_name,email', 'course:id,title']);

        return response()->json([
            'certificate' => [
                'id' => $certificate->id,
                'student_id' => $certificate->student_id,
                'student_name' => trim("{$student->first_name} {$student->last_name}") ?: ($student->email ?? 'Student'),
                'course_id' => $certificate->course_id,
                'course_title' => $certificate->course?->title,
                'title' => $certificate->title,
                'file_url' => $certificate->file_url,
                'issued_at' => optional($certificate->issued_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Revoke (delete) a certificate. Tenant-scoped findOrFail — an id belonging
     * to another institute 404s.
     */
    public function revokeCertificate(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        LmsCertificate::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Certificate revoked.']);
    }

    // ─── Course management (mirrors the JIT super-admin course CRUD, but
    //     tenant-scoped: every write is stamped/filtered to the owner's org) ──
    /**
     * Create a course for this institute. The tenant is stamped automatically by
     * the TenantAware creating hook (ownerContext() bound currentTenant), so no
     * tenant_id is accepted from the client.
     */
    public function storeCourse(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

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

        return response()->json(['course' => $this->coursePayload($course)], 201);
    }

    /**
     * Update one of this institute's courses. findOrFail() is tenant-scoped, so
     * an id belonging to another institute 404s. Only fields present in the
     * request are changed.
     */
    public function updateCourse(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $course = LmsCourse::query()->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'is_live_available' => ['nullable', 'boolean'],
            'is_prerecorded_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Apply only the keys the client actually sent (validated() omits absent
        // fields), so a partial save never blanks untouched columns.
        $data = array_intersect_key($validated, array_flip([
            'title', 'description', 'requirements', 'price', 'max_students',
            'is_live_available', 'is_prerecorded_available', 'is_active',
        ]));
        if (array_key_exists('max_students', $data)) {
            $data['max_students'] = (int) ($data['max_students'] ?? 0);
        }
        if (array_key_exists('price', $data) && $data['price'] === null) {
            $data['price'] = 0;
        }

        $course->update($data);

        return response()->json(['course' => $this->coursePayload($course->fresh())]);
    }

    /**
     * Delete one of this institute's courses (tenant-scoped findOrFail).
     */
    public function destroyCourse(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        LmsCourse::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    // ─── Track / cohort management (this is where staff get assigned to a
    //     cohort via instructor_id — mirrors AdminController::apiCreateTrack) ──

    /**
     * Create a cohort (track) under one of this institute's courses, optionally
     * assigning an instructor now. The course/instructor ids are re-checked
     * against the tenant-scoped models so a foreign id can't be smuggled in.
     */
    public function storeTrack(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'course_id' => ['required', 'integer'],
            'instructor_id' => ['nullable', 'integer'],
        ]);

        $course = LmsCourse::query()->find($validated['course_id']);
        if (! $course) {
            return response()->json(['message' => 'That course does not exist in your institute.'], 422);
        }

        $instructorId = null;
        if (! empty($validated['instructor_id'])) {
            $instructor = LmsTeacher::query()->find($validated['instructor_id']);
            if (! $instructor) {
                return response()->json(['message' => 'That instructor does not exist in your institute.'], 422);
            }
            $instructorId = $instructor->id;
        }

        $track = LmsTrack::query()->create([
            'name' => $validated['name'],
            'course_id' => $course->id,
            'instructor_id' => $instructorId,
        ]);

        // Every cohort gets its group chat, same as the super-admin path.
        LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        // If an instructor was assigned now, pull the course's existing students
        // into this cohort so the instructor sees them immediately (registration
        // and staff-assignment are otherwise never reconciled).
        if ($instructorId) {
            $this->syncTrackEnrollments($track);
        }

        return response()->json(['track' => $this->trackPayload($track)], 201);
    }

    /**
     * Update a cohort — rename it, move it to another course, or (re)assign the
     * instructor. All ids are validated against this tenant's scoped models.
     */
    public function updateTrack(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $track = LmsTrack::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'course_id' => ['nullable', 'integer'],
            'instructor_id' => ['nullable', 'integer'],
        ]);

        $data = [];
        if (($validated['name'] ?? null) !== null) {
            $data['name'] = $validated['name'];
        }
        if (! empty($validated['course_id'])) {
            $course = LmsCourse::query()->find($validated['course_id']);
            if (! $course) {
                return response()->json(['message' => 'That course does not exist in your institute.'], 422);
            }
            $data['course_id'] = $course->id;
        }
        // instructor_id: 0/"" clears the assignment; a positive id must resolve
        // to one of this tenant's teachers.
        if ($request->has('instructor_id')) {
            if (empty($validated['instructor_id'])) {
                $data['instructor_id'] = null;
            } else {
                $instructor = LmsTeacher::query()->find($validated['instructor_id']);
                if (! $instructor) {
                    return response()->json(['message' => 'That instructor does not exist in your institute.'], 422);
                }
                $data['instructor_id'] = $instructor->id;
            }
        }

        $track->update($data);
        LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        // Whenever the cohort has an instructor (just assigned or already set),
        // reconcile enrollments so that instructor sees the course's students.
        if ($track->fresh()->instructor_id) {
            $this->syncTrackEnrollments($track->fresh());
        }

        return response()->json(['track' => $this->trackPayload($track->fresh())]);
    }

    /**
     * The course shape shared by the list, create, and update responses.
     */
    private function coursePayload(LmsCourse $course): array
    {
        // The list query already eager-counts via withCount; only load here when
        // called with a fresh model (create/update) that hasn't been counted yet,
        // so the list doesn't re-run two count queries per course.
        if (! isset($course->students_count) || ! isset($course->tracks_count)) {
            $course->loadCount(['students', 'tracks']);
        }

        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'requirements' => $course->requirements,
            'price' => $course->price,
            'max_students' => $course->max_students,
            'students_count' => $course->students_count,
            'tracks_count' => $course->tracks_count,
            'is_live_available' => (bool) $course->is_live_available,
            'is_prerecorded_available' => (bool) $course->is_prerecorded_available,
            'is_active' => (bool) $course->is_active,
        ];
    }

    /**
     * The track shape shared by the create + update responses (matches the keys
     * the tracks() list emits).
     */
    private function trackPayload(LmsTrack $track): array
    {
        $track->load('course:id,title');
        $instructorName = $track->instructor_id
            ? optional(LmsTeacher::query()->find($track->instructor_id))->name
            : null;

        return [
            'id' => $track->id,
            'name' => $track->name,
            'course' => $track->course?->title,
            'course_id' => $track->course_id,
            'instructor' => $instructorName,
            'instructor_id' => $track->instructor_id,
            'created_at' => $track->created_at,
        ];
    }
}
