<?php

namespace App\Support;

use App\Exceptions\PlanLimitException;
use App\Models\LmsCourse;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Tenant;

/**
 * Plan-limit enforcement for the per-institute caps (courses / staff / students).
 *
 * Every count is taken with the explicit `withTenant()` scope — bypassing the
 * ambient TenantScope and pinning tenant_id — so a check is correct regardless
 * of which tenant happens to be bound on the request. A null limit means the
 * plan is unlimited for that resource (every check short-circuits to allow).
 *
 * Limits only ever block going OVER the cap on a new create; they never touch
 * existing rows, so lowering a plan (or an institute already above a cap) is safe.
 */
class PlanGate
{
    /** Throw a 402 PlanLimitException if the tenant is at/over its course cap. */
    public static function ensureCanAddCourse(Tenant $tenant): void
    {
        $limit = $tenant->planLimit('courses');
        if ($limit === null) {
            return;
        }

        if (LmsCourse::query()->withTenant($tenant->id)->count() >= $limit) {
            throw PlanLimitException::forResource('courses', $limit, $tenant);
        }
    }

    /**
     * Throw a 402 PlanLimitException if this tenant's plan does not include a
     * boolean feature (e.g. ai_materials, pre_recorded_video, admission_marketer,
     * chat). Renders the same 402 + upgrade_required envelope the owner UI already
     * intercepts (maybeUpgrade → UpgradeModal), so any owner call site can simply
     * `PlanGate::ensureFeature($tenant, 'ai_materials')` before doing the work.
     */
    public static function ensureFeature(Tenant $tenant, string $feature): void
    {
        if (! $tenant->planFeature($feature)) {
            throw PlanLimitException::forFeature($feature, $tenant);
        }
    }

    /**
     * Throw a 402 PlanLimitException if the tenant is at/over its staff cap. An
     * email that already belongs to a staff member here is a re-invite of an
     * existing seat, not a new one, so it never counts against the limit.
     */
    public static function ensureCanAddStaff(Tenant $tenant, ?string $email = null): void
    {
        $limit = $tenant->planLimit('staff');
        if ($limit === null) {
            return;
        }

        if ($email !== null && $email !== '' && self::teacherExists($tenant, $email)) {
            return;
        }

        if (LmsTeacher::query()->withTenant($tenant->id)->count() >= $limit) {
            throw PlanLimitException::forResource('staff', $limit, $tenant);
        }
    }

    /**
     * Whether enrolling one more (new) student would exceed the tenant's cap.
     * An email that is already a student here is a re-enrolment into another
     * course (an existing seat), so it is always allowed.
     *
     * Returns the limit when full (so the caller can build a message), or null
     * when there is room / the plan is unlimited. The public enrolment path uses
     * this instead of throwing — a self-enrolling visitor cannot upgrade a plan.
     */
    public static function studentLimitReached(Tenant $tenant, ?string $email = null): ?int
    {
        $limit = $tenant->planLimit('students');
        if ($limit === null) {
            return null;
        }

        if ($email !== null && $email !== '' && self::studentExists($tenant, $email)) {
            return null;
        }

        return LmsStudent::query()->withTenant($tenant->id)->count() >= $limit ? $limit : null;
    }

    private static function teacherExists(Tenant $tenant, string $email): bool
    {
        return LmsTeacher::query()->withTenant($tenant->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->exists();
    }

    private static function studentExists(Tenant $tenant, string $email): bool
    {
        return LmsStudent::query()->withTenant($tenant->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->exists();
    }
}
