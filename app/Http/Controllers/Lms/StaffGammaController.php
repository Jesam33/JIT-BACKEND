<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourse;
use App\Models\LmsModule;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * The STAFF variant of the AI training-material generator (Gamma, Pro+).
 * Teachers are the ones authoring course content, so the generate → poll →
 * save flow lives in their portal too; the owner keeps theirs so a solo-owner
 * academy (where the owner IS the teacher) still gets the Pro feature it pays
 * for.
 *
 * This is a thin override of OwnerGammaController, NOT a copy: every method
 * (generate/status/save/courseModules) is inherited unchanged and only three
 * seams differ:
 *
 *  - resolveContext(): authenticates a STAFF bearer session (lms_staff_token)
 *    instead of an owner one, resolves the teacher, and (re)binds their tenant
 *    so all inherited TenantAware queries stay scoped to the institute. An
 *    OWNER bearer session resolves too, to the academy's owner-mirror teacher
 *    (full parity: the owner keeps their own portal and the staff one).
 *  - authorizeCourseTarget()/authorizeModuleTarget(): restrict the save/browse
 *    targets to the courses this actor may author into, the same scoping
 *    StaffPortalController uses for materials — a teacher cannot write AI
 *    content into a course they don't teach, even within the same institute.
 *    For a staffer that is the courses of their cohorts
 *    (LmsTrack.instructor_id); for the owner's mirror, the whole academy.
 *
 * The ai_materials PlanGate (Pro+) is inherited on every action; a 402 reaches
 * the staff page as an "ask your academy owner to upgrade" note, since staff
 * cannot upgrade the plan themselves (unlike the owner's UpgradeModal).
 */
class StaffGammaController extends OwnerGammaController
{
    private ?LmsTeacher $teacher = null;

    /** Course ids of the teacher's assigned cohorts, [] when unassigned. */
    private array $assignedCourseIds = [];

    /**
     * Resolve the authenticated teacher + their tenant from the bearer session
     * and bind the tenant. Returns [Tenant, LmsTeacher] or null when the caller
     * is neither a staff member nor the academy owner. Mirrors
     * StaffPortalController::getTeacher, plus the same currentTenant (re)bind the
     * owner context does so the inherited TenantAware-scoped queries in
     * save()/courseModules() scope to THIS actor's institute.
     */
    protected function resolveContext(Request $request): ?array
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror.
        $teacher = $this->staffActor($request);
        if (! $teacher) {
            return null;
        }

        $tenantId = $teacher->tenant_id
            ?? (app()->bound('currentTenant') && app('currentTenant') ? app('currentTenant')->id : null);
        if (! $tenantId) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        app()->instance('currentTenant', $tenant);

        $this->teacher = $teacher;
        // Courses this actor may author into: the whole academy for the owner's
        // mirror, the assigned cohorts' courses for a staffer.
        $this->assignedCourseIds = array_map('intval', $this->actorCourseIds($teacher));

        return [$tenant, $teacher];
    }

    protected function authorizeCourseTarget(LmsCourse $course): void
    {
        if (! $this->teacher || ! in_array((int) $course->id, $this->assignedCourseIds, true)) {
            abort(403, 'You can only save into courses you are assigned to teach.');
        }
    }

    protected function authorizeModuleTarget(LmsModule $module): void
    {
        if (! $this->teacher || ! in_array((int) $module->course_id, $this->assignedCourseIds, true)) {
            abort(403, 'You can only save into modules of courses you are assigned to teach.');
        }
    }
}
