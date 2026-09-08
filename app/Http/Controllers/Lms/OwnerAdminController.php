<?php

namespace App\Http\Controllers\Lms;

use App\Mail\LmsPasswordResetMail;
use App\Mail\StudentCourseInviteMail;
use App\Models\CeoForum;
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
use App\Support\CourseCards;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Read-only data for the institute owner's admin dashboard (the sidebar UI at
 * /lms/admin): headline counts plus the students / staff / courses / tracks
 * lists an owner manages.
 *
 * Like TenantBillingController these routes sit inside `tenant.required`, but
 * authorization does NOT trust the middleware-bound tenant. Each request derives
 * the tenant from the owner's own session row (tenant_id) and confirms
 * tenant_admins membership, a student or staff bearer token also binds a tenant,
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
     * recent students, everything the landing dashboard card grid needs.
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
            'plan_summary' => $tenant->planSummaryArray(),
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
     * Real analytics for the owner dashboard (fix #1, no dummy data). Every query
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

        [$tenant] = $context;

        // Snapshot totals + the registration funnel are STANDARD analytics, every
        // plan sees them. The 6-month trend charts (students/enrollments/revenue
        // over time) are ADVANCED analytics, a Pro+ feature: skip those queries
        // and return empty series on plans without it, so the dashboard can show
        // an upgrade prompt in place of the charts (see the `advanced` flag).
        $advanced = $tenant->planFeature('advanced_analytics');

        // Build the last 6 whole-month buckets, oldest → newest (e.g. Mar…Aug).
        // The axis labels are cheap (no query) so they're always returned.
        $start = now()->startOfMonth()->subMonths(5);
        $months = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 6; $i++) {
            $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M')];
            $cursor->addMonth();
        }

        $series = [
            'students' => array_fill(0, 6, 0),
            'enrollments' => array_fill(0, 6, 0),
            'revenue' => array_fill(0, 6, 0),
        ];

        if ($advanced) {
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

            $series = [
                'students' => $align($studentsByMonth),
                'enrollments' => $align($enrollByMonth),
                'revenue' => $align($revenueByMonth),
            ];
        }

        $regByStatus = TrainingRegistration::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return response()->json([
            'advanced' => $advanced,
            'months' => array_map(fn ($m) => $m['label'], $months),
            'series' => $series,
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
     * Re-send the "set your password" invite to a student, the same
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

        [$tenant] = $context;

        $student = LmsStudent::query()->findOrFail($id);
        if (! $student->email) {
            return response()->json(['message' => 'This student has no email address on file.'], 422);
        }

        $sent = false;
        try {
            $token = $this->createPasswordResetToken('student', $student->email);
            $link = $this->buildResetLink('student', $student->email, $token);
            $brand = $this->mailBranding($tenant);
            Mail::to($student->email)->send(new LmsPasswordResetMail($student->first_name ?: 'there', 'Student Portal', $link, $brand['name'], $brand['color'], $brand['reply_to']));
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
     * Invite one or more students straight into a specific course (Issue C).
     *
     * Unlike the bulk importer (which creates course-less accounts and emails a
     * bare set-password link), every invite here attaches a course, so the
     * student knows exactly what they're joining, and the owner decides per
     * invite whether it's paid:
     *
     *  - Paid (toggle on + a course fee > 0): a PENDING TrainingRegistration is
     *    created and the email links to the signup page, which launches payment
     *    through the academy's own Paystack subaccount. The account is
     *    provisioned, and the seat counted, only after Paystack confirms
     *    (completePayment), the exact pay-first pipeline the public storefront
     *    uses, so payment can't be bypassed.
     *  - Comped / free (toggle off, or a free course): the student is enrolled
     *    immediately into the course's active cohort and the email links to a
     *    set-password page. The seat is counted here, once, on first enrolment.
     *
     * Money safety: a non-primary academy that hasn't linked its payout bank yet
     * is refused a PAID invite up front, so fees can't land in the platform
     * account. Plan caps are honoured, the platform student cap holds new
     * accounts and a full course holds further comped seats, and a partial
     * invite still processes everyone who fits, reporting what was held back.
     */
    public function inviteStudentToCourse(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['string'],
            'course_id' => ['required', 'integer'],
            'learning_mode' => ['nullable', 'in:live,pre_recorded'],
            'requires_payment' => ['nullable', 'boolean'],
        ]);

        // Resolve the course inside the owner's tenant, ownerContext bound the
        // current tenant, so the global TenantScope stops another institute's id
        // from resolving here.
        $course = LmsCourse::query()->find($validated['course_id']);
        if (! $course) {
            return response()->json(['message' => 'Course not found for your institute.'], 404);
        }
        if (! $course->is_active) {
            return response()->json(['message' => 'That course is not active. Activate it before inviting students.'], 422);
        }

        // Learning mode falls back to live when the course doesn't offer
        // pre-recorded, so the price/mode we store always stays coherent.
        $mode = $validated['learning_mode'] ?? 'live';
        if ($mode === 'pre_recorded' && ! $course->is_prerecorded_available) {
            $mode = 'live';
        }

        // Base fee for the chosen mode (pre-recorded may be cheaper). A 0 base is
        // always free, whatever the toggle says.
        $base = ($mode === 'pre_recorded' && $course->prerecorded_price !== null)
            ? (float) $course->prerecorded_price
            : (float) $course->price;

        $mustPay = $request->boolean('requires_payment', true) && $base > 0;

        // Money-safety pre-guard: a non-primary academy must link its payout bank
        // before it can collect course fees, or the money would settle to the
        // platform account. Mirrors the defense in LmsIntakeController.
        $isNonPrimary = $tenant->slug && $tenant->slug !== config('saas.primary_slug', 'jorsas');
        if ($mustPay && $isNonPrimary && ! $this->payoutSubaccountCode($tenant)) {
            return response()->json([
                'message' => 'Link your bank account under Payment settings before inviting students to a paid course, so fees settle to you.',
            ], 409);
        }

        // Plan platform-student cap (null = unlimited). New accounts beyond the
        // cap are held; existing students (re-invites) never count. Mirrors
        // importStudents so invites can't quietly exceed the plan.
        $studentLimit = $tenant->planLimit('students');
        $studentCount = $studentLimit === null
            ? 0
            : LmsStudent::query()->withTenant($tenant->id)->count();

        // Per-course seat cap (0 max_students = unlimited). Only comped invites
        // take a seat here (paid seats are taken on payment), so we track the
        // remaining capacity and hold comped invites once the course is full.
        $seatCap = $course->max_students > 0 ? (int) $course->max_students : null;
        $seatsUsed = (int) $course->registered_count;

        $brand = $this->mailBranding($tenant);
        $priceDisplay = '₦' . number_format($base, 2);

        $invited = 0;
        $created = 0;
        $skipped = 0;
        $limited = 0;
        $courseFull = 0;
        $failed = [];

        foreach ($validated['emails'] as $rawEmail) {
            $email = strtolower(trim((string) $rawEmail));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $existing = LmsStudent::query()->where('email', $email)->first();
            $isNew = ! $existing;

            if ($isNew && $studentLimit !== null && $studentCount >= $studentLimit) {
                $limited++;
                continue;
            }

            // A comped seat must fit the course capacity (paid seats are reserved
            // on payment, not here).
            if (! $mustPay && $seatCap !== null && $seatsUsed >= $seatCap) {
                $courseFull++;
                continue;
            }

            // Upsert the student. A new account gets an unusable random password
            // (set for real via the emailed link) and stays un-onboarded; an
            // existing account only has its current course/mode pointed at this
            // invite, never its password or onboarding flag reset.
            $attrs = [
                'selected_course_id' => $course->id,
                'learning_mode' => $mode,
            ];
            if ($isNew) {
                $attrs['first_name'] = Str::before($email, '@');
                $attrs['last_name'] = '';
                $attrs['password'] = Hash::make(Str::random(40));
                $attrs['onboarding_completed'] = false;
            }
            $student = LmsStudent::query()->updateOrCreate(['email' => $email], $attrs);
            if ($isNew) {
                $created++;
                $studentCount++;
            }

            // One TrainingRegistration per invite carries the course + a fresh
            // invite_token. Paid → pending (provisioned on payment); comped/free →
            // approved now. course_price is frozen for the paid charge, in NGN, 
            // the academy's settlement currency (its Paystack subaccount is a
            // Nigerian bank).
            $registration = TrainingRegistration::query()->create([
                'first_name' => $student->first_name ?: Str::before($email, '@'),
                'last_name' => $student->last_name ?? '',
                'email' => $email,
                'course_id' => $course->id,
                'course_name' => $course->title,
                'learning_mode' => $mode,
                'course_price' => $mustPay ? $base : 0,
                'charge_currency' => 'NGN',
                'status' => $mustPay ? 'pending' : 'approved',
                'approved_by' => $mustPay ? null : ($owner->id ?? null),
                'approved_at' => $mustPay ? null : now(),
                'invite_token' => Str::random(64),
            ]);

            $student->update(['training_registration_id' => $registration->id]);

            // Comped/free: enrol into the course's active cohort now (the same
            // bridge the free intake path uses) and count the seat once, on the
            // first enrolment. Paid invites are enrolled + counted in
            // completePayment after Paystack confirms.
            if (! $mustPay) {
                $track = $this->findActiveTrackForCourse($course->id);
                if ($track) {
                    $enrollment = LmsEnrollment::query()->updateOrCreate(
                        ['student_id' => $student->id],
                        ['track_id' => $track->id],
                    );
                    if ($enrollment->wasRecentlyCreated) {
                        LmsCourse::query()->where('id', $course->id)->increment('registered_count');
                        $seatsUsed++;
                    }
                }
            }

            try {
                $link = $this->buildStudentSignupLink($email, $registration->invite_token);
                Mail::to($email)->send(new StudentCourseInviteMail($registration, $link, $mustPay, $priceDisplay));
                $invited++;
            } catch (\Throwable $e) {
                // The account/registration exists; only delivery failed. Surface
                // it so the owner can fix mail settings and resend, rather than
                // the failure vanishing into the log behind a success message.
                $failed[] = $email;
                Log::warning('Failed sending course invite', ['email' => $email, 'err' => $e->getMessage()]);
            }
        }

        DB::table('tenant_onboarding_audits')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id ?? null,
            'payload' => json_encode([
                'action' => 'students_invited_to_course',
                'course_id' => $course->id,
                'course' => $course->title,
                'requires_payment' => $mustPay,
                'invited' => $invited,
                'created' => $created,
                'limited' => $limited,
                'course_full' => $courseFull,
                'failed' => count($failed),
            ]),
            'status' => 'students_invited_to_course',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'invited' => $invited,
            'created' => $created,
            'skipped' => $skipped,
            'limited' => $limited,
            'course_full' => $courseFull,
            'failed' => $failed,
            'requires_payment' => $mustPay,
            'course' => $course->title,
            'price_display' => $priceDisplay,
            'message' => $this->summariseCourseInvite($invited, $mustPay, $course->title, $limited, $courseFull, $failed),
        ]);
    }

    /**
     * The institute's own Paystack subaccount code (its bank), or null when it
     * hasn't linked one. Paid course fees split to this so the money settles to
     * the academy, not the platform. Kept private + local to mirror
     * LmsIntakeController::tenantSubaccountCode (also private there).
     */
    private function payoutSubaccountCode(Tenant $tenant): ?string
    {
        $code = data_get($tenant->settings, 'paystack.subaccount_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Human summary of a course-invite batch for the owner's toast.
     */
    private function summariseCourseInvite(int $invited, bool $mustPay, string $course, int $limited, int $courseFull, array $failed): string
    {
        $parts = [];
        if ($invited > 0) {
            $verb = $mustPay ? 'Sent a payment invite to' : 'Invited';
            $parts[] = "{$verb} {$invited} student" . ($invited === 1 ? '' : 's') . " for {$course}";
        } else {
            $parts[] = "No invites were sent for {$course}";
        }
        if ($limited > 0) {
            $parts[] = "{$limited} held back, you've reached your plan's student limit";
        }
        if ($courseFull > 0) {
            $parts[] = "{$courseFull} held back, this course is full";
        }
        if (! empty($failed)) {
            $parts[] = count($failed) . ' email(s) failed to send, check your mail settings';
        }

        return implode('. ', $parts) . '.';
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
     * cascadeOnDelete, deleting a teacher who still leads a cohort would
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
     * Re-send the "set your password" invite to a staff member, the same setup
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

        [$tenant] = $context;

        $teacher = LmsTeacher::query()->findOrFail($id);
        if (! $teacher->email) {
            return response()->json(['message' => 'This staff member has no email address on file.'], 422);
        }

        $sent = false;
        try {
            $token = $this->createPasswordResetToken('staff', $teacher->email);
            $link = $this->buildSetupLink('staff', $teacher->email, $token);
            $brand = $this->mailBranding($tenant);
            Mail::to($teacher->email)->send(new LmsPasswordResetMail($teacher->name ?: 'there', 'Staff Portal', $link, $brand['name'], $brand['color'], $brand['reply_to']));
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
     * access WITHOUT deleting anything, the reversible alternative to removal,
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
            ->get(['id', 'title', 'slug', 'description', 'requirements', 'price', 'original_price', 'cover_image_path', 'max_students', 'registered_count', 'is_live_available', 'is_prerecorded_available', 'is_active']);

        // Build the card context (ratings/instructor/bestseller) once for the
        // whole list to avoid a per-course N+1.
        $cardCtx = CourseCards::context($courses->pluck('id')->all(), $tenant->name);

        $payload = $courses->map(fn (LmsCourse $c) => $this->coursePayload($c, $cardCtx));

        return response()->json(['tenant_id' => $tenant->id, 'courses' => $payload]);
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
            ->get(['id', 'name', 'course_id', 'instructor_id', 'batch_id', 'start_date', 'end_date', 'registration_deadline', 'created_at']);

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
            'start_date' => $t->start_date?->toDateString(),
            'end_date' => $t->end_date?->toDateString(),
            'registration_deadline' => $t->registration_deadline?->toDateString(),
            'registration_open' => $t->registrationOpen(),
            'registration_closes_at' => $t->registrationClosesAt()?->toIso8601String(),
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
     * CEO's Forum feed for the owner portal: the next upcoming session (with a
     * server-side `joinable` flag + the server clock for the countdown) plus the
     * archive of past sessions. Any confirmed owner of any active tenant may see
     * these; the forums are a platform-wide perk, not tenant-scoped.
     */
    public function forums(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $map = fn (CeoForum $f) => [
            'id' => $f->id,
            'title' => $f->title,
            'topic' => $f->topic,
            'scheduled_at' => optional($f->scheduled_at)->toIso8601String(),
            'duration_minutes' => (int) $f->duration_minutes,
            'host_name' => $f->host_name,
            'status' => $f->status,
            'cover_image' => $f->cover_image,
            'recording_url' => $f->recording_url,
            'joinable' => $f->isJoinable(),
        ];

        $upcoming = CeoForum::query()
            ->whereIn('status', [CeoForum::STATUS_SCHEDULED, CeoForum::STATUS_LIVE])
            ->orderBy('scheduled_at')
            ->get()
            ->reject(fn (CeoForum $f) => $f->isPast())
            ->values()
            ->map($map);

        $past = CeoForum::query()
            ->where(function ($q) {
                $q->where('status', CeoForum::STATUS_ENDED)
                    ->orWhereNotNull('recording_url');
            })
            ->where('status', '!=', CeoForum::STATUS_CANCELLED)
            ->orderByDesc('scheduled_at')
            ->limit(50)
            ->get()
            ->map($map);

        return response()->json([
            'now' => now()->toIso8601String(),
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }

    /**
     * Mint a participant JaaS token for an owner to join a forum in-portal.
     * Gated on the join window (opens 10 min before start, closes after the
     * end + grace) so tokens are only handed out when the room is live.
     */
    public function forumToken(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        $forum = CeoForum::find($id);
        if (! $forum || $forum->status === CeoForum::STATUS_CANCELLED) {
            return response()->json(['message' => 'Forum not found.'], 404);
        }

        if (! $forum->isJoinable()) {
            return response()->json([
                'message' => 'The forum room opens 10 minutes before the start time.',
            ], 403);
        }

        $cfg = $this->jitsiConfig();
        if (! $cfg) {
            return response()->json([
                'message' => 'Live meetings are not configured yet. Please try again later.',
            ], 503);
        }

        $room = $this->ensureRoom($forum, 'forum');

        $userName = trim(($owner?->first_name ?? '') . ' ' . ($owner?->last_name ?? ''))
            ?: ($owner?->email ?? 'Owner');

        $jwt = $this->mintJaasToken($cfg, $room, [
            'id' => 'owner-' . ($owner?->id ?? '0'),
            'name' => $userName,
            'email' => $owner?->email ?? '',
        ], false);

        return response()->json([
            'room' => $room,
            'jwt' => $jwt,
            'domain' => $cfg['domain'],
            'app_id' => $cfg['appId'],
            'user_name' => $userName,
            'moderator' => false,
        ]);
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
     * is deliberately frozen, it's the public web address students already
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
            // What this academy calls itself (e.g. "Institute", "Academy",
            // "School"). Customer-facing label only, never touches identifiers.
            'entity_label' => ['nullable', 'string', 'max:40'],
            'entity_label_plural' => ['nullable', 'string', 'max:40'],
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

        // Entity label: an empty submitted value reverts to the plan default
        // (Institute for the primary, Online Academy for everyone else); a
        // skipped key preserves what's stored. Plural is optional, when blank,
        // entityLabelArray() derives it from the singular via Str::plural.
        foreach (['entity_label', 'entity_label_plural'] as $key) {
            if ($request->has($key)) {
                $value = trim((string) ($validated[$key] ?? ''));
                if ($value === '') {
                    unset($branding[$key]);
                } else {
                    $branding[$key] = $value;
                }
            }
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

        $settings = (array) ($tenant->settings ?? []);
        $branding = (array) ($settings['branding'] ?? []);
        // Store the RELATIVE path; brandingArray() rebuilds the absolute URL against
        // the current host on read, so a host baked in at upload (localhost → live,
        // http → https) can't break the logo.
        $branding['logo_url'] = $path;
        $settings['branding'] = $branding;
        $tenant->update(['settings' => $settings]);

        $branding = $this->brandingFor($tenant->fresh());

        return response()->json(['url' => $branding['logo_url'], 'branding' => $branding]);
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
     * Update the institute's public profile, the content shown on its /i/{slug}
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
            // Teaching niche shown in the public Campuses filter. Free text (owners
            // may pick "Other" and type their own); the general dropdown is frontend-only.
            'niche' => ['nullable', 'string', 'max:80'],
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

        foreach (['tagline', 'about', 'niche'] as $key) {
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

        $settings = (array) ($tenant->settings ?? []);
        $profile = (array) ($settings['profile'] ?? []);
        // Store the RELATIVE path; profileArray() rebuilds the absolute URL against
        // the current host on read (see uploadLogo), so the cover can't break on a
        // host change.
        $profile['cover_url'] = $path;
        $settings['profile'] = $profile;
        $tenant->update(['settings' => $settings]);

        $profile = $tenant->fresh()->profileArray();

        return response()->json(['url' => $profile['cover_url'], 'profile' => $profile]);
    }

    /**
     * The OWNER's own login account (Personal details tab of the Profile page):
     * the name, email, and password they sign in with, plus the academy's
     * teaching niche (it lives on the tenant but is edited from this tab).
     * Distinct from the public-page profile() above (storefront content) and
     * from the tenant itself, this is the users-table row behind their owner
     * session.
     */
    public function account(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        return response()->json([
            'account' => [
                'first_name' => $owner?->first_name ?? '',
                'last_name' => $owner?->last_name ?? '',
                'email' => $owner?->email,
                'created_at' => $owner?->created_at,
                // The academy's teaching category (drives the public Campuses
                // directory filter). Lives on the TENANT's settings.profile, not
                // the user row, but it's edited from this same tab.
                'niche' => data_get($tenant->settings, 'profile.niche'),
            ],
        ]);
    }

    /**
     * Update the owner's personal details. An email change also rewrites
     * `username` (setup() seeds username = email, and the username is what the
     * users table's uniqueness actually rests on), and the owner must log in
     * with the new email from then on. Email must not collide with another
     * users-table row, otherwise the next owner to sign up with that address
     * would silently graft onto this account.
     */
    public function updateAccount(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        if (! $owner) {
            return response()->json(['message' => 'Owner account not found.'], 404);
        }

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // The academy's teaching category, free text (the general dropdown is
            // frontend-only), stored on the tenant exactly like updateProfile
            // stores it so both writers stay interchangeable.
            'niche' => ['nullable', 'string', 'max:80'],
        ]);

        $email = strtolower(trim($validated['email']));

        $collision = User::query()
            ->where('email', $email)
            ->whereKeyNot($owner->id)
            ->exists();
        if ($collision) {
            return response()->json(['message' => 'That email is already used by another account.'], 422);
        }

        $owner->update([
            'first_name' => trim((string) ($validated['first_name'] ?? '')),
            'last_name' => trim((string) ($validated['last_name'] ?? '')),
            'email' => $email,
            'username' => $email,
        ]);

        // The niche rides on the tenant, not the user row. Same normalization as
        // updateProfile (blank → null) so clearing it here clears it everywhere.
        if (array_key_exists('niche', $validated)) {
            $niche = trim((string) ($validated['niche'] ?? ''));
            $settings = (array) ($tenant->settings ?? []);
            $profile = (array) ($settings['profile'] ?? []);
            $profile['niche'] = $niche === '' ? null : $niche;
            $settings['profile'] = $profile;
            $tenant->update(['settings' => $settings]);
        }

        return response()->json([
            'message' => 'Personal details updated.',
            'account' => [
                'first_name' => $owner->first_name ?? '',
                'last_name' => $owner->last_name ?? '',
                'email' => $owner->email,
                'created_at' => $owner->created_at,
                'niche' => data_get($tenant->fresh()->settings, 'profile.niche'),
            ],
        ]);
    }

    /**
     * Change the owner's login password. Requires the current password so a
     * borrowed/unlocked browser can't silently take the account over. The
     * bearer session stays valid (sessions are separate rows), matching the
     * student/staff changePassword behaviour.
     */
    public function changeAccountPassword(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant, $owner] = $context;

        if (! $owner) {
            return response()->json(['message' => 'Owner account not found.'], 404);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $owner->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        $owner->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'Password updated.']);
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
     * platform account, this is the "institute collects the money, not Jorsas"
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
                // Whether we created this subaccount (its split follows the plan) or
                // the owner pasted a code (its split is their own, shown as unknown).
                'managed' => $tenant->payoutSubaccountManaged(),
                // The split actually recorded on the linked subaccount, if known.
                'subaccount_commission_percent' => isset($paystack['percentage_charge']) ? (float) $paystack['percentage_charge'] : null,
                'business_name' => $paystack['business_name'] ?? null,
                'first_name' => $paystack['first_name'] ?? null,
                'last_name' => $paystack['last_name'] ?? null,
                'bank_code' => $paystack['bank_code'] ?? null,
                'bank_name' => $paystack['bank_name'] ?? null,
                'account_number_masked' => $maskedAccount ?: null,
                'account_name' => $paystack['account_name'] ?? null,
            ],
            'platform_commission_percent' => $tenant->commissionPercent(),
            // The cut this academy pays its own admission agents on each sale, and
            // whether the agent programme is even part of this plan (the UI only
            // shows the control when it is). Distinct from the platform fee above.
            'agent_commission_percent' => $tenant->agentCommissionPercent(),
            'agent_program_enabled' => (bool) $tenant->planFeature('admission_marketer'),
            'gateway_ready' => $gatewayReady,
            // The bank picker only needs codes when linking a fresh subaccount, and
            // the list is a live Paystack round-trip, only fetch it when the
            // gateway is configured and no subaccount is linked yet.
            'banks' => ($gatewayReady && empty($paystack['subaccount_code'])) ? $service->listBanks() : [],
        ]);
    }

    /**
     * Link (or replace) the institute's Paystack subaccount so course fees settle
     * to its own bank. Two ways in: paste an existing `subaccount_code` directly,
     * or supply bank_code + account_number + the owner's legal first/last name and
     * we create the subaccount via Paystack (platform commission from config). The
     * legal name, not a free-text business name, is what we register as the
     * subaccount name so it matches the settlement account and Paystack can
     * auto-verify it. Mirrors updateBranding's read-merge-save on the `settings`
     * JSON blob so nothing else stored there is disturbed.
     */
    public function updatePaymentSettings(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        // Rate-only update: the owner is just changing the agent commission from
        // the payments page, not touching the payout bank. Handle it up front so it
        // never triggers the subaccount validation below. Gated on the plan actually
        // including the agent programme, and clamped 0..100 so a bad value can never
        // produce a negative or runaway payout.
        if ($request->has('agent_commission_percent')
            && ! $request->hasAny(['subaccount_code', 'bank_code', 'account_number', 'disconnect'])) {
            if (! $tenant->planFeature('admission_marketer')) {
                return response()->json(['message' => 'The admission agent programme is not part of your current plan.'], 403);
            }
            $rate = $request->validate([
                'agent_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            ]);
            $settings = (array) ($tenant->settings ?? []);
            $settings['agent_commission_percent'] = round((float) $rate['agent_commission_percent'], 2);
            $tenant->update(['settings' => $settings]);

            return response()->json([
                'message' => 'Agent commission updated.',
                'agent_commission_percent' => $settings['agent_commission_percent'],
            ]);
        }

        $validated = $request->validate([
            'subaccount_code' => ['nullable', 'string', 'max:120'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:20'],
            'business_name' => ['nullable', 'string', 'max:255'],
            // The owner's legal name on the settlement bank account. Paystack won't
            // auto-verify a subaccount whose name doesn't match the bank record, so
            // we send this (not the free-text business name) as the subaccount name.
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'disconnect' => ['nullable', 'boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $paystack = (array) ($settings['paystack'] ?? []);

        // Explicit disconnect: course fees revert to the platform account.
        if ($request->boolean('disconnect')) {
            // Tear the subaccount down on Paystack too, so a disconnected bank
            // doesn't linger as an active subaccount on the dashboard. Only for
            // subaccounts WE created (managed): a pasted code may live on the
            // owner's own Paystack account and be used elsewhere, so we never
            // touch it. Best-effort and non-throwing, the local disconnect must
            // succeed even if the gateway is down or already forgot the code.
            $existingCode = $paystack['subaccount_code'] ?? null;
            if ($existingCode && ! empty($paystack['managed'])) {
                app(PaystackService::class)->deactivateSubaccount((string) $existingCode);
            }

            unset($paystack['subaccount_code'], $paystack['business_name'], $paystack['first_name'], $paystack['last_name'], $paystack['bank_code'], $paystack['bank_name'], $paystack['account_number'], $paystack['account_name'], $paystack['managed'], $paystack['percentage_charge']);
            $settings['paystack'] = $paystack;
            $tenant->update(['settings' => $settings]);

            return response()->json(['message' => 'Payout account disconnected. Course fees will settle to the platform account.', 'configured' => false]);
        }

        // Path A, a subaccount code was pasted directly. Trust it as-is. Marked
        // unmanaged: it may live on another Paystack account and carries whatever
        // split the owner set there, so a plan change must never rewrite it, and
        // we don't know its %, so clear any recorded split.
        if (! empty($validated['subaccount_code'])) {
            $paystack['subaccount_code'] = trim($validated['subaccount_code']);
            $paystack['managed'] = false;
            unset($paystack['percentage_charge']);
            if (! empty($validated['business_name'])) {
                $paystack['business_name'] = trim($validated['business_name']);
            }
        } else {
            // Path B, create a subaccount from bank details. We send the owner's
            // LEGAL name (first + last) as the subaccount name, not a free-text
            // business name: Paystack flags a subaccount for manual "verify" when
            // its name doesn't match the settlement account's bank record, so a name
            // that tallies with the account holder is what lets it settle cleanly.
            $first = trim((string) ($validated['first_name'] ?? ''));
            $last = trim((string) ($validated['last_name'] ?? ''));
            $fullName = trim("{$first} {$last}");
            $missing = empty($validated['bank_code']) || empty($validated['account_number']) || $first === '' || $last === '';
            if ($missing) {
                return response()->json(['message' => 'Provide a subaccount code, or your bank, account number, and the legal first and last name on the account.'], 422);
            }

            $service = app(PaystackService::class);
            if (! $service->isConfigured()) {
                return response()->json(['message' => 'The payment gateway is not configured on the platform yet. Try again later.'], 503);
            }

            // Per-plan commission (the platform's cut of this institute's course
            // sales), set on the split at creation. Because this subaccount is
            // platform-managed, a later plan change re-syncs the split to the new
            // plan's commission (Tenant::syncPayoutCommission), so it won't go stale.
            $commission = $tenant->commissionPercent();
            $result = $service->createSubaccount(
                $fullName,
                trim($validated['bank_code']),
                trim($validated['account_number']),
                $commission,
            );

            if (! ($result['status'] ?? false) || empty($result['data']['subaccount_code'])) {
                $message = $result['message'] ?? 'Could not create the payout account. Check the bank and account number.';

                return response()->json(['message' => $message], 422);
            }

            $paystack['subaccount_code'] = $result['data']['subaccount_code'];
            // Store the legal name (both the parts and the combined name Paystack
            // now carries). business_name is kept as the combined name so every
            // surface that already reads it keeps working.
            $paystack['first_name'] = $first;
            $paystack['last_name'] = $last;
            $paystack['business_name'] = $fullName;
            $paystack['bank_code'] = trim($validated['bank_code']);
            $paystack['bank_name'] = $validated['bank_name'] ?? ($result['data']['settlement_bank'] ?? null);
            $paystack['account_number'] = trim($validated['account_number']);
            $paystack['account_name'] = $result['data']['account_name'] ?? null;
            // Platform-created → we own the split and keep it in step with the plan
            // on every change (Tenant::syncPayoutCommission). Record the % applied.
            $paystack['managed'] = true;
            $paystack['percentage_charge'] = $commission;
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
     * Confirmatory only, it links nothing and never 500s: an unresolvable account
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

    // ─── Custom domains (Pro/Enterprise: point learn.theiracademy.com at the
    //     platform). Every read/write is tenant-scoped via ownerContext() and
    //     gated on the custom_domain plan feature. A domain resolves a tenant
    //     (ResolveTenant) only after its DNS TXT record is verified. ──

    /**
     * Shape a TenantDomain row for the owner UI, including the DNS record the
     * owner must publish. Never leaks another tenant's rows, callers always
     * scope by the resolved owner's tenant_id.
     */
    private function domainPayload(\App\Models\TenantDomain $d): array
    {
        return [
            'id' => $d->id,
            'host' => $d->host,
            'status' => $d->status,
            'verified' => $d->isVerified(),
            'is_primary' => (bool) $d->is_primary,
            'verified_at' => optional($d->verified_at)->toIso8601String(),
            'dns' => $d->dnsInstructions(),
        ];
    }

    /**
     * List this academy's custom domains + whether the plan allows them. The UI
     * shows the add form and DNS instructions only when `enabled` is true.
     */
    public function domains(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $domains = \App\Models\TenantDomain::where('tenant_id', $tenant->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Models\TenantDomain $d) => $this->domainPayload($d))
            ->all();

        return response()->json([
            'enabled' => (bool) $tenant->planFeature('custom_domain'),
            'domains' => $domains,
            // Shown as the DNS target the owner points a CNAME at. The platform's
            // own apex is the safe default; ops can override via APP_DOMAIN.
            'cname_target' => (string) (env('APP_DOMAIN') ?: parse_url((string) config('saas.frontend_url'), PHP_URL_HOST)),
        ]);
    }

    /**
     * Add a custom domain (status=pending) and return the DNS TXT record the
     * owner must publish to verify control. Gated on the custom_domain feature;
     * a host can only ever belong to one academy (unique), so a host already
     * claimed elsewhere is refused.
     */
    public function addDomain(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        if (! $tenant->planFeature('custom_domain')) {
            return response()->json(['message' => 'Custom domains are not part of your current plan.'], 403);
        }

        $validated = $request->validate([
            'host' => ['required', 'string', 'max:253'],
        ]);

        $host = \App\Models\TenantDomain::normalizeHost($validated['host']);
        if (! $host) {
            return response()->json(['message' => 'Enter a valid domain, for example learn.youracademy.com.'], 422);
        }

        // Never let a custom domain shadow the platform's own hosts.
        $appDomain = strtolower((string) env('APP_DOMAIN'));
        if ($appDomain !== '' && ($host === $appDomain || str_ends_with($host, '.' . $appDomain))) {
            return response()->json(['message' => 'That domain is reserved by the platform. Use a domain you own.'], 422);
        }

        $existing = \App\Models\TenantDomain::where('host', $host)->first();
        if ($existing) {
            if ((int) $existing->tenant_id === (int) $tenant->id) {
                return response()->json([
                    'message' => 'This domain is already added.',
                    'domain' => $this->domainPayload($existing),
                ], 200);
            }

            return response()->json(['message' => 'This domain is already in use.'], 422);
        }

        $domain = \App\Models\TenantDomain::create([
            'tenant_id' => $tenant->id,
            'host' => $host,
            'status' => \App\Models\TenantDomain::STATUS_PENDING,
            'verification_token' => \App\Models\TenantDomain::newToken(),
            'is_primary' => false,
        ]);

        return response()->json([
            'message' => 'Domain added. Publish the DNS records below, then verify.',
            'domain' => $this->domainPayload($domain),
        ], 201);
    }

    /**
     * Verify a pending domain by checking its DNS TXT record matches the token
     * we issued. On success the row flips to verified (and becomes primary if it
     * is the academy's first verified domain) so ResolveTenant will honour it.
     * DNS lookups can fail transiently, an unverified result is a soft 422, not
     * an error, so the owner can simply retry once propagation completes.
     */
    public function verifyDomain(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $domain = \App\Models\TenantDomain::where('tenant_id', $tenant->id)->whereKey($id)->first();
        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        if ($domain->isVerified()) {
            return response()->json(['message' => 'Domain already verified.', 'domain' => $this->domainPayload($domain)]);
        }

        $recordHost = '_jorsas-verify.' . $domain->host;
        $found = false;
        try {
            $records = @dns_get_record($recordHost, DNS_TXT) ?: [];
            foreach ($records as $rec) {
                $txt = trim((string) ($rec['txt'] ?? ''));
                if ($txt !== '' && hash_equals($domain->verification_token, $txt)) {
                    $found = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $found = false;
        }

        if (! $found) {
            return response()->json([
                'message' => 'We could not find the verification record yet. DNS changes can take a while to propagate, please try again shortly.',
                'domain' => $this->domainPayload($domain),
            ], 422);
        }

        // First verified domain for this academy becomes its primary (canonical) host.
        $hasPrimary = \App\Models\TenantDomain::where('tenant_id', $tenant->id)
            ->where('status', \App\Models\TenantDomain::STATUS_VERIFIED)
            ->where('is_primary', true)
            ->exists();

        $domain->update([
            'status' => \App\Models\TenantDomain::STATUS_VERIFIED,
            'verified_at' => now(),
            'is_primary' => ! $hasPrimary,
        ]);

        return response()->json([
            'message' => 'Domain verified. Your academy is now reachable at this address.',
            'domain' => $this->domainPayload($domain->fresh()),
        ]);
    }

    /** Remove a custom domain. Tenant-scoped so an owner can only delete their own. */
    public function deleteDomain(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        $domain = \App\Models\TenantDomain::where('tenant_id', $tenant->id)->whereKey($id)->first();
        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        $wasPrimary = (bool) $domain->is_primary;
        $domain->delete();

        // If the canonical host was removed, promote the next verified domain so
        // the academy still has a primary custom host where one exists.
        if ($wasPrimary) {
            $next = \App\Models\TenantDomain::where('tenant_id', $tenant->id)
                ->where('status', \App\Models\TenantDomain::STATUS_VERIFIED)
                ->orderBy('id')
                ->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        return response()->json(['message' => 'Domain removed.']);
    }

    // ─── Certificates (institute-issued, admin-only, moved off the staff
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

        // Let the student know in-app (best-effort, never block issuance on it).
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
     * Revoke (delete) a certificate. Tenant-scoped findOrFail, an id belonging
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
    /**
     * Clamp a course's requested capacity to the academy's plan student cap.
     *
     * A single course can never seat more students than the plan allows for the
     * whole academy, so this returns min(requested, planCap). A requested value of
     * 0 means "unlimited", honoured only on an unlimited plan (null cap); on a
     * capped plan 0 becomes the plan cap so it isn't silently unbounded.
     */
    private function capCourseCapacity(Tenant $tenant, int $requested): int
    {
        // A course seats no more than the tightest of the academy-wide student
        // cap and the per-class cap, either may be null (unlimited). Resulting
        // per-class ceiling: Free = 1 (dominated by its 1-student academy cap),
        // Basic = 30, Pro = 250, Enterprise = unlimited.
        $limits = array_filter(
            [$tenant->planLimit('students'), $tenant->planLimit('per_course')],
            fn ($v) => $v !== null
        );
        $max = empty($limits) ? 0 : min($limits);   // 0 = unlimited (both null)

        if ($requested <= 0) {
            return $max;                              // blank = "as many as the plan allows"
        }

        return $max === 0 ? $requested : min($requested, $max);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;

        // Enforce the plan's course cap before creating (throws a 402 with an
        // upgrade hint when the institute is at its limit). Unlimited plans no-op.
        PlanGate::ensureCanAddCourse($tenant);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            // Courses can't be free, the platform earns a commission % on each
            // sale, so a ₦0 course would earn nothing and can't be sold.
            'price' => ['required', 'numeric', 'min:0.01'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            // Optional cheaper price for the pre-recorded mode (item 6). Only
            // persisted when pre-recorded is actually offered on this plan.
            'prerecorded_price' => ['nullable', 'numeric', 'min:0.01'],
            // Capacity is at least 1 seat; the plan-cap clamp below bounds the top.
            'max_students' => ['required', 'integer', 'min:1'],
            'is_live_available' => ['nullable', 'boolean'],
            'is_prerecorded_available' => ['nullable', 'boolean'],
        ], [
            'price.min' => 'Enter a price greater than ₦0. Courses can\'t be free.',
        ]);

        // A course cannot seat more students than the plan allows for the whole
        // academy: clamp capacity to the plan's student cap so a Free academy (1
        // student) can't advertise 1000 slots. On a capped plan the request is
        // clamped down to the cap; unlimited plans keep the requested number.
        $maxStudents = $this->capCourseCapacity($tenant, (int) $validated['max_students']);

        // Pre-recorded video is a Pro+ feature, never persist it as available on
        // a plan that doesn't include it, regardless of what the client sent.
        $prerecorded = ($validated['is_prerecorded_available'] ?? false)
            && $tenant->planFeature('pre_recorded_video');

        // The separate pre-recorded price only means anything while pre-recorded
        // is offered; otherwise store null so a hidden mode can't carry a price.
        $prerecordedPrice = $prerecorded ? ($validated['prerecorded_price'] ?? null) : null;

        $course = LmsCourse::query()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'requirements' => $validated['requirements'] ?? null,
            'price' => $validated['price'],
            'original_price' => $validated['original_price'] ?? null,
            'prerecorded_price' => $prerecordedPrice,
            'max_students' => $maxStudents,
            'is_live_available' => $validated['is_live_available'] ?? true,
            'is_prerecorded_available' => $prerecorded,
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

        [$tenant] = $context;

        $course = LmsCourse::query()->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            // No free courses, the platform earns a commission % on each sale.
            'price' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'prerecorded_price' => ['nullable', 'numeric', 'min:0.01'],
            'max_students' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_live_available' => ['nullable', 'boolean'],
            'is_prerecorded_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'price.min' => 'Enter a price greater than ₦0. Courses can\'t be free.',
        ]);

        // Apply only the keys the client actually sent (validated() omits absent
        // fields), so a partial save never blanks untouched columns.
        $data = array_intersect_key($validated, array_flip([
            'title', 'description', 'requirements', 'price', 'original_price', 'prerecorded_price',
            'max_students', 'is_live_available', 'is_prerecorded_available', 'is_active',
        ]));
        if (array_key_exists('max_students', $data)) {
            // Same plan-cap clamp as create (see storeCourse): capacity can't exceed
            // the academy's plan student cap.
            $data['max_students'] = $this->capCourseCapacity($tenant, (int) $data['max_students']);
        }
        if (array_key_exists('is_prerecorded_available', $data)) {
            // Pre-recorded video is Pro+; force it off on plans without the feature.
            $data['is_prerecorded_available'] = (bool) $data['is_prerecorded_available']
                && $tenant->planFeature('pre_recorded_video');
        }
        // A pre-recorded price is only meaningful while pre-recorded is offered.
        // Use the EFFECTIVE availability (the value being set this request, else the
        // course's current one) and null the price out whenever pre-recorded is off,
        // so disabling the mode can't leave a stale cheaper price behind.
        if (array_key_exists('prerecorded_price', $data) || array_key_exists('is_prerecorded_available', $data)) {
            $effectivePrerecorded = array_key_exists('is_prerecorded_available', $data)
                ? $data['is_prerecorded_available']
                : (bool) $course->is_prerecorded_available;
            if (! $effectivePrerecorded) {
                $data['prerecorded_price'] = null;
            }
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

    /**
     * Upload (or remove) a course's storefront cover image. Mirrors uploadCover
     * (public disk + asset() URL) but writes to the course's own
     * cover_image_path column. findOrFail is tenant-scoped, so an id from another
     * institute 404s. `remove_cover=true` clears it (the card then falls back to
     * the branded placeholder). Returns the refreshed coursePayload.
     */
    public function uploadCourseCover(Request $request, int $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $course = LmsCourse::query()->findOrFail($id);

        if ($request->boolean('remove_cover')) {
            $course->update(['cover_image_path' => null]);

            return response()->json(['course' => $this->coursePayload($course->fresh())]);
        }

        $request->validate(['file' => ['required', 'image', 'max:4096']]);

        $path = $request->file('file')->store('course-covers', 'public');
        $course->update(['cover_image_path' => $path]);

        return response()->json(['course' => $this->coursePayload($course->fresh())]);
    }

    // ─── Track / cohort management (this is where staff get assigned to a
    //     cohort via instructor_id, mirrors AdminController::apiCreateTrack) ──

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
            // Cohort scheduling (all optional): registration closes at the
            // deadline when set, else the start date, else the cohort stays
            // always-open like the pre-date behaviour.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_deadline' => ['nullable', 'date'],
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
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'registration_deadline' => $validated['registration_deadline'] ?? null,
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
     * Update a cohort, rename it, move it to another course, or (re)assign the
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_deadline' => ['nullable', 'date'],
        ]);

        $data = [];
        if (($validated['name'] ?? null) !== null) {
            $data['name'] = $validated['name'];
        }
        // Cohort dates: each key present in the request is applied (empty string
        // clears it), absent keys keep the stored value.
        foreach (['start_date', 'end_date', 'registration_deadline'] as $dateKey) {
            if ($request->has($dateKey)) {
                $data[$dateKey] = $validated[$dateKey] ?? null;
            }
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
     *
     * `$cardCtx` is the batch-built card context (ratings/instructor/bestseller)
     * from CourseCards::context(). The list method builds it once for all courses
     * (no N+1); create/update pass null so it's built for the single course.
     */
    private function coursePayload(LmsCourse $course, ?array $cardCtx = null): array
    {
        // The list query already eager-counts via withCount; only load here when
        // called with a fresh model (create/update) that hasn't been counted yet,
        // so the list doesn't re-run two count queries per course.
        if (! isset($course->students_count) || ! isset($course->tracks_count)) {
            $course->loadCount(['students', 'tracks']);
        }

        if ($cardCtx === null) {
            $fallback = app()->bound('currentTenant') && app('currentTenant')
                ? app('currentTenant')->name
                : null;
            $cardCtx = CourseCards::context([$course->id], $fallback);
        }
        $card = CourseCards::fieldsFor($cardCtx, $course->id);

        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'requirements' => $course->requirements,
            'price' => $course->price,
            'original_price' => $course->original_price,
            'prerecorded_price' => $course->prerecorded_price,
            'cover_image_url' => $course->cover_image_url,
            'rating_average' => $card['rating_average'],
            'rating_count' => $card['rating_count'],
            'instructor_name' => $card['instructor_name'],
            'is_bestseller' => $card['is_bestseller'],
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
            'start_date' => $track->start_date?->toDateString(),
            'end_date' => $track->end_date?->toDateString(),
            'registration_deadline' => $track->registration_deadline?->toDateString(),
            'registration_open' => $track->registrationOpen(),
            'registration_closes_at' => $track->registrationClosesAt()?->toIso8601String(),
            'created_at' => $track->created_at,
        ];
    }
}
