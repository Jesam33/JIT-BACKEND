<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a monthly registration stopped being billed (billing_status = 'ended'):
 * cohort_ended, course_deleted, academy_closed, stopped_by_academy,
 * student_inactive or moved_course. Shown to the student and the owner, and
 * used to resume billing when a suspended student is reactivated.
 * See App\Services\CourseBilling::endBilling().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_registrations') && ! Schema::hasColumn('training_registrations', 'billing_end_reason')) {
            Schema::table('training_registrations', function (Blueprint $table): void {
                $table->string('billing_end_reason', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('training_registrations') && Schema::hasColumn('training_registrations', 'billing_end_reason')) {
            Schema::table('training_registrations', function (Blueprint $table): void {
                $table->dropColumn('billing_end_reason');
            });
        }
    }
};
