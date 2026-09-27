<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\AgentNotification;
use App\Models\LmsCourse;
use App\Models\LmsNotification;
use App\Models\LmsOwnerNotification;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherNotification;
use App\Models\LmsTrack;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place an in-portal notification is raised.
 *
 * Three of the four bells predate this class and were written inline at their
 * call sites; the owner had no bell of its own at all. What was missing was not
 * another `::create()` call but the POLICY: who hears about what, and in which
 * of the platform's four voices. Scattering that decision across controllers is
 * exactly how a "notify the owner when a student enrols" change ends up applied
 * to two of the three enrolment paths.
 *
 * So this class is deliberately two layers:
 *
 *   PRIMITIVES (`owner` / `staff` / `student` / `agent`) address one of the four
 *   audiences. They know nothing about events.
 *
 *   BUCKETS (`enrolment`, `payment`, `courseChanged`, `cohortChanged`,
 *   `staffAdmin`, `studentAdmin`, `agentApplied`, `agentReviewed`,
 *   `agentRegistered`) are named for the four things the owner asked to be told
 *   about, and each encodes its own recipient set.
 *
 * Adding an event means adding one bucket method and calling it from every path
 * that performs that action, not inventing a recipient list at the call site.
 *
 * ── Delivery is asynchronous by design ──────────────────────────────────────
 * Every write here is IN-APP ONLY. The scheduled `lms:send-notification-emails`
 * sweep picks up each new row (emailed_at IS NULL) and mails it, so a controller
 * never pays for SMTP and a mail outage never fails the action the user took.
 * A recipient with no usable address is retried and then given up on by that
 * sweep, which is where that decision belongs.
 *
 * ── Failures never break the action ─────────────────────────────────────────
 * A notification is a courtesy, not part of the transaction. Every public method
 * swallows its own Throwable: a rejected write (a missing tenant context, a
 * table that has not migrated yet on a half-deployed host) must not roll back an
 * enrolment or 500 a payment confirmation. The cost of that choice is that a
 * genuinely broken fan-out is silent, so each catches to the log rather than to
 * nothing.
 */
class Notify
{
    /* ---------------------------------------------------------------------
     * Primitives
     * ------------------------------------------------------------------ */

    /**
     * Notify the academy owner (tenant-wide bell — see LmsOwnerNotification).
     *
     * $tenantId defaults to whichever tenant is bound, which is correct on every
     * authenticated path. Pass it explicitly from a context that creates a row
     * for ANOTHER academy (the platform back office).
     */
    public static function owner(
        string $type,
        string $title,
        ?string $body = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $tenantId = null
    ): void {
        static::guard(function () use ($type, $title, $body, $referenceType, $referenceId, $tenantId) {
            $tenantId ??= static::boundTenantId();
            if (! $tenantId) {
                return;
            }

            LmsOwnerNotification::createForTenant($tenantId, [
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    /**
     * Notify one or more staff members by LmsTeacher id.
     *
     * The academy owner's own mirror row (LmsTeacher::ownerMirror) is FILTERED
     * OUT by default: every bucket in this class notifies the owner through
     * `owner()` as well, so leaving the mirror in would deliver the same news
     * twice to the same human. Pass $includeOwnerMirror when a caller genuinely
     * wants staff-shaped delivery only.
     *
     * @param  int|iterable<int>  $teacherIds
     */
    public static function staff(
        int|iterable $teacherIds,
        string $type,
        string $title,
        ?string $body = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        bool $includeOwnerMirror = false
    ): void {
        static::guard(function () use ($teacherIds, $type, $title, $body, $referenceType, $referenceId, $includeOwnerMirror) {
            $rows = static::rows(LmsTeacher::class, static::ids($teacherIds));
            if (! $rows) {
                return;
            }

            if (! $includeOwnerMirror) {
                $rows = array_filter($rows, fn (array $r) => ! $r['is_academy_owner']);
            }

            foreach ($rows as $id => $row) {
                static::write(LmsTeacherNotification::class, $row['tenant_id'], [
                    'teacher_id' => $id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }
        });
    }

    /** @param int|iterable<int> $studentIds */
    public static function student(
        int|iterable $studentIds,
        string $type,
        string $title,
        ?string $body = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): void {
        static::guard(function () use ($studentIds, $type, $title, $body, $referenceType, $referenceId) {
            foreach (static::rows(LmsStudent::class, static::ids($studentIds)) as $id => $row) {
                static::write(LmsNotification::class, $row['tenant_id'], [
                    'student_id' => $id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }
        });
    }

    /** @param int|iterable<int> $agentIds */
    public static function agent(
        int|iterable $agentIds,
        string $type,
        string $title,
        ?string $body = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): void {
        static::guard(function () use ($agentIds, $type, $title, $body, $referenceType, $referenceId) {
            foreach (static::rows(Agent::class, static::ids($agentIds)) as $id => $row) {
                static::write(AgentNotification::class, $row['tenant_id'], [
                    'agent_id' => $id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Bucket 1 — enrolment + payment
     * ------------------------------------------------------------------ */

    /**
     * A student was enrolled onto a course. Owner + the staff who actually teach
     * it, because an instructor's first question is "who is in my class".
     */
    public static function enrolment(
        ?LmsCourse $course,
        string $studentName,
        ?string $detail = null,
        ?int $tenantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): void {
        $courseTitle = $course?->title ?: 'a course';
        static::owner(
            'enrolment',
            'New enrolment: ' . $studentName,
            trim($studentName . ' enrolled in ' . $courseTitle . '.' . ($detail ? ' ' . $detail : '')),
            $referenceType ?? ($course ? 'course' : null),
            $referenceId ?? $course?->id,
            $tenantId
        );
        static::staff(
            $course ? static::courseStaffIds($course) : [],
            'enrolment',
            'New student in your course',
            trim($studentName . ' enrolled in ' . $courseTitle . '.' . ($detail ? ' ' . $detail : '')),
            $referenceType ?? ($course ? 'course' : null),
            $referenceId ?? $course?->id
        );
    }

    /**
     * A payment landed. Owner only — the money settles into the academy's own
     * Paystack subaccount, so it is the owner's number, not an instructor's.
     */
    public static function payment(
        string $studentName,
        string $amount,
        ?string $courseTitle = null,
        ?int $tenantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): void {
        static::owner(
            'payment',
            'Payment received: ' . $amount,
            trim($studentName . ' paid ' . $amount . ($courseTitle ? ' for ' . $courseTitle : '') . '.'),
            $referenceType,
            $referenceId,
            $tenantId
        );
    }

    /* ---------------------------------------------------------------------
     * Bucket 2 — course / cohort changes
     * ------------------------------------------------------------------ */

    /**
     * A course was created, edited or deleted. Owner + the staff who teach it.
     *
     * $course arrives nullable for the DELETE case: by the time the row is gone
     * the caller still has the title, but not a model it can query staff from,
     * so $staffIds lets the caller pass the recipients it captured beforehand.
     *
     * @param  iterable<int>|null  $staffIds
     */
    public static function courseChanged(
        string $action,
        string $courseTitle,
        ?LmsCourse $course = null,
        ?iterable $staffIds = null,
        ?int $tenantId = null
    ): void {
        $body = 'Course "' . $courseTitle . '" was ' . $action . '.';
        static::owner('course_' . $action, 'Course ' . $action . ': ' . $courseTitle, $body, 'course', $course?->id, $tenantId);
        static::staff(
            $staffIds ?? ($course ? static::courseStaffIds($course) : []),
            'course_' . $action,
            'Course ' . $action . ': ' . $courseTitle,
            $body,
            'course',
            $course?->id
        );
    }

    /**
     * A cohort was created, edited or deleted, or had staff assigned to it.
     * Owner + the cohort's instructor.
     */
    public static function cohortChanged(
        string $action,
        string $cohortName,
        ?LmsTrack $track = null,
        ?iterable $staffIds = null,
        ?int $tenantId = null
    ): void {
        $body = 'Cohort "' . $cohortName . '" was ' . $action . '.';
        static::owner('cohort_' . $action, 'Cohort ' . $action . ': ' . $cohortName, $body, 'cohort', $track?->id, $tenantId);
        static::staff(
            $staffIds ?? ($track?->instructor_id ? [$track->instructor_id] : []),
            'cohort_' . $action,
            'Cohort ' . $action . ': ' . $cohortName,
            $body,
            'cohort',
            $track?->id
        );
    }

    /* ---------------------------------------------------------------------
     * Bucket 3 — staff + student administration
     * ------------------------------------------------------------------ */

    /**
     * A staff account was created, edited, suspended or removed. Owner only:
     * this IS the owner's own administrative act, and no other role has
     * business being told about another hire.
     */
    public static function staffAdmin(string $action, string $name, ?string $detail = null, ?int $tenantId = null): void
    {
        static::owner(
            'staff_' . $action,
            'Staff ' . $action . ': ' . $name,
            trim($name . ' was ' . $action . ' by you.' . ($detail ? ' ' . $detail : '')),
            'staff',
            null,
            $tenantId
        );
    }

    /** A student account was created, edited, suspended or removed. Owner only. */
    public static function studentAdmin(string $action, string $name, ?string $detail = null, ?int $tenantId = null): void
    {
        static::owner(
            'student_' . $action,
            'Student ' . $action . ': ' . $name,
            trim($name . ' was ' . $action . '.' . ($detail ? ' ' . $detail : '')),
            'student',
            null,
            $tenantId
        );
    }

    /* ---------------------------------------------------------------------
     * Bucket 4 — agent activity
     * ------------------------------------------------------------------ */

    /**
     * An Admission Marketer applied. Owner only — only the owner can decide, so
     * telling the applicant would be noise.
     */
    public static function agentApplied(Agent $agent, ?int $tenantId = null): void
    {
        $name = $agent->name ?: ($agent->email ?? 'An applicant');
        static::owner(
            'agent_applied',
            'New Admission Marketer application',
            $name . ', review to approve or decline.',
            'agent',
            $agent->id,
            $tenantId
        );
    }

    /** An application was approved or declined. The agent is the one who needs it. */
    public static function agentReviewed(Agent $agent, string $status): void
    {
        $approved = $status === 'approved';
        static::agent(
            $agent->id,
            'application_' . $status,
            $approved ? 'Application approved' : 'Application declined',
            $approved
                ? 'Your Admission Marketer application was approved. You can start sharing your academy link.'
                : 'Your Admission Marketer application was declined.',
            'agent',
            $agent->id
        );
    }

    /**
     * An agent registered a student. Both bells: the owner (a new name on the
     * roster is something the academy is entitled to know, and it is the only
     * agent activity that produces one) and the agent (their own activity record).
     *
     * Carries the agent's row so the caller does not also hand-roll an
     * AgentNotification — this used to be two writes in two shapes at one call
     * site, which is how the two drifted apart.
     */
    public static function agentRegistered(
        Agent $agent,
        string $studentName,
        ?string $courseTitle = null,
        ?int $registrationId = null
    ): void {
        static::agent(
            $agent->id,
            'student_registered',
            'Student Registered',
            'Registration created for ' . $studentName . ($courseTitle ? ', ' . $courseTitle : '') . '.',
            'registration',
            $registrationId
        );

        $agentName = $agent->name ?: ($agent->email ?? 'An agent');
        static::owner(
            'agent_registration',
            'New agent registration',
            trim($studentName . ($courseTitle ? ' for ' . $courseTitle : '') . ', registered by ' . $agentName . '.'),
            'registration',
            $registrationId
        );
    }

    /* ---------------------------------------------------------------------
     * Recipient resolution
     * ------------------------------------------------------------------ */

    /**
     * The staff ids teaching a course: the distinct instructors of its cohorts.
     *
     * A course has no instructor of its own (assignment lives on the cohort), so
     * this is the only honest answer to "who teaches this course". Cross-tenant
     * ids are impossible: the query runs under TenantScope like every other read.
     *
     * @return array<int>
     */
    public static function courseStaffIds(LmsCourse $course): array
    {
        return static::safe(fn () => LmsTrack::query()
            ->where('course_id', $course->id)
            ->whereNotNull('instructor_id')
            ->pluck('instructor_id')
            ->unique()
            ->values()
            ->all(), []);
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private static function boundTenantId(): ?int
    {
        return app()->bound('currentTenant') && app('currentTenant')
            ? (int) app('currentTenant')->id
            : null;
    }

    /** @return array<int> */
    private static function ids(int|iterable $ids): array
    {
        $ids = is_int($ids) ? [$ids] : $ids;

        return array_values(array_unique(array_filter(
            array_map('intval', is_array($ids) ? $ids : iterator_to_array($ids)),
            fn (int $id) => $id > 0
        )));
    }

    /**
     * [id => ['tenant_id' => int, 'is_academy_owner' => bool]] for the ids that
     * exist, read across tenants.
     *
     * Two jobs, one query. The tenant_id is what lets `write()` file the row
     * under the RECIPIENT's academy rather than whichever tenant happens to be
     * bound — the platform back office runs with the primary tenant bound, so a
     * bare `create()` there would file an academy's notification against the
     * wrong organisation. `is_academy_owner` is the owner-mirror filter.
     *
     * Read without the global scope deliberately: the ids came from an
     * already-scoped caller, and a scoped lookup here would silently miss a
     * recipient whose tenant differs from the bound one.
     *
     * @param  class-string<Model>  $model
     * @param  array<int>  $ids
     * @return array<int, array{tenant_id: int|null, is_academy_owner: bool}>
     */
    private static function rows(string $model, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $hasMirrorColumn = $model === LmsTeacher::class;
        $columns = $hasMirrorColumn ? ['id', 'tenant_id', 'is_academy_owner'] : ['id', 'tenant_id'];

        return static::safe(function () use ($model, $ids, $columns, $hasMirrorColumn) {
            $out = [];
            foreach ($model::withoutGlobalScope(TenantScope::class)->whereIn('id', $ids)->get($columns) as $row) {
                $out[(int) $row->id] = [
                    'tenant_id' => $row->tenant_id !== null ? (int) $row->tenant_id : null,
                    'is_academy_owner' => $hasMirrorColumn && (bool) $row->is_academy_owner,
                ];
            }

            return $out;
        }, []);
    }

    /**
     * File one notification row under $tenantId, falling back to the bound
     * tenant when the recipient row carries none (a legacy row predating the
     * tenancy backfill).
     *
     * @param  class-string<Model>  $model
     */
    private static function write(string $model, ?int $tenantId, array $attributes): void
    {
        $tenantId ??= static::boundTenantId();
        if (! $tenantId) {
            return;
        }

        static::guard(fn () => $model::createForTenant($tenantId, $attributes));
    }

    /**
     * Run $fn, logging rather than throwing. See the class docblock: a failed
     * courtesy must not fail the action that triggered it.
     */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Same contract as guard(), for a method that has a value to return. */
    private static function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
