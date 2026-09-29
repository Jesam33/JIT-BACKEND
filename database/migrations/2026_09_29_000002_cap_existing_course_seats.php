<?php

use App\Models\Tenant;
use App\Support\PlanGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring every existing course under the seat rule LmsCourse::booted() now
 * enforces on save: never "unlimited" (max_students 0, the column default that
 * every seeded "Getting Started" course and setup-wizard course ended up with),
 * and never more than the platform ceiling of 50 per course, so Pro and above
 * top out at 50.
 *
 *   0 / negative → the most the academy's plan allows (Free 1, Basic 30, Pro+ 50)
 *   above 50     → 50
 *
 * Students already enrolled are untouched; a course simply stops taking new
 * registrations once full. Idempotent: a second run finds nothing to change.
 * Written through the query builder, not the model, so it does not depend on
 * model events and never touches updated_at-driven behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_courses') || ! Schema::hasColumn('lms_courses', 'max_students')) {
            return;
        }

        $ceiling = PlanGate::maxStudentsPerCourse();

        DB::table('lms_courses')->where('max_students', '>', $ceiling)->update(['max_students' => $ceiling]);

        $uncapped = DB::table('lms_courses')->where('max_students', '<=', 0)->get(['id', 'tenant_id']);
        $tenants = Tenant::query()->whereIn('id', $uncapped->pluck('tenant_id')->filter()->unique())->get()->keyBy('id');

        foreach ($uncapped->groupBy('tenant_id') as $tenantId => $courses) {
            DB::table('lms_courses')
                ->whereIn('id', $courses->pluck('id'))
                ->update(['max_students' => PlanGate::courseSeatCap($tenants->get($tenantId))]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: "unlimited" is no longer a valid capacity.
    }
};
