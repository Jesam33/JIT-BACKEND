<?php

namespace App\Support;

use App\Models\LmsCourse;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\CourseBilling;

/**
 * One course per student per academy.
 *
 * A student account is keyed by email within an academy and holds ONE current
 * course (lms_students.selected_course_id, one enrolment row). Registering a
 * second time used to create a second registration and charge again: for the
 * same course the student paid twice for nothing, for another course the new
 * course silently replaced the one they had paid for.
 *
 * So registration (storefront, agent) and payment are both refused while the
 * email already has a course at this academy:
 *
 *   same course      → "you're already enrolled" (monthly: "pay from Billing")
 *   different course → "you're already enrolled in X at this academy"
 *
 * The same email at ANOTHER academy is a separate account and is unaffected. A
 * purged account no longer counts. Checked at registration AND again when the
 * payment starts, since two tabs can register before either pays.
 */
class EnrollmentGuard
{
    /**
     * Why this email may not take this course here, as a 409 payload, or null
     * when it may.
     *
     * @return array<string,mixed>|null
     */
    public static function conflict(?Tenant $tenant, string $email, int $courseId): ?array
    {
        $enrolment = static::currentEnrolment($tenant, $email);
        if (! $enrolment) {
            return null;
        }

        [$courseTitle, $enrolledCourseId, $registration] = $enrolment;

        if ($enrolledCourseId === $courseId) {
            $monthly = $registration && $registration->isMonthly()
                && $registration->billing_status !== CourseBilling::STATUS_ENDED;

            return [
                'already_enrolled' => true,
                'message' => $monthly
                    ? "You're already enrolled in {$courseTitle}. Log in to your student portal and open Billing to pay for your next month."
                    : "You're already enrolled in {$courseTitle}. Log in to your student portal to continue.",
                // Where the storefront's "Log in" button sends them after login.
                'portal_path' => $monthly ? '/lms/app/billing' : '/lms/app',
            ];
        }

        return [
            'enrolled_elsewhere' => true,
            'message' => "This email is already enrolled in {$courseTitle} at this academy. Each student can take one course per academy. Log in to continue with {$courseTitle}, or use a different email.",
            'portal_path' => '/lms/app',
        ];
    }

    /**
     * What this logged-in student is enrolled in at their academy, for the
     * public course pages ("Enrolled" badge / "Go to my course"), or null.
     *
     * @return array{course_id:int,course_title:string}|null
     */
    public static function summaryFor(LmsStudent $student): ?array
    {
        $tenant = $student->tenant_id ? Tenant::find($student->tenant_id) : null;
        $enrolment = static::currentEnrolment($tenant, (string) $student->email);

        return $enrolment ? ['course_id' => $enrolment[1], 'course_title' => $enrolment[0]] : null;
    }

    /**
     * The course this email is currently enrolled in at this academy:
     * [course title, course id, current registration|null], or null.
     *
     * Enrolled = a student account exists here (not purged) whose current
     * registration was approved (paid, free or comped), or, for accounts that
     * predate registrations (imports), that simply has a course set.
     *
     * @return array{0:string,1:int,2:?TrainingRegistration}|null
     */
    private static function currentEnrolment(?Tenant $tenant, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $student = LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $student || $student->lifecycleState() === 'purged') {
            return null;
        }

        $registration = CourseBilling::currentRegistration($student);

        $courseId = $registration
            ? ($registration->status === 'approved' ? (int) $registration->course_id : null)
            : ($student->selected_course_id ? (int) $student->selected_course_id : null);

        if (! $courseId) {
            return null;
        }

        $title = LmsCourse::query()->withoutGlobalScope(TenantScope::class)->whereKey($courseId)->value('title')
            ?? $registration?->course_name
            ?? 'a course';

        return [$title, $courseId, $registration];
    }

    /**
     * The unpaid registration this email already started for this course here,
     * if any, so a second attempt reuses it instead of piling up duplicates.
     */
    public static function pendingRegistration(?Tenant $tenant, string $email, int $courseId): ?TrainingRegistration
    {
        return TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->where('course_id', $courseId)
            ->where('status', 'pending')
            // Never reuse an owner invite: its token is the student's link.
            ->whereNull('invite_token')
            ->whereDoesntHave('payments', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('status', 'success'))
            ->latest('id')
            ->first();
    }
}
