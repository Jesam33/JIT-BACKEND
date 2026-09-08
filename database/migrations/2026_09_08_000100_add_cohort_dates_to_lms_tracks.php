<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cohort scheduling on lms_tracks: start_date/end_date (the cohort runs
     * between them) and an optional registration_deadline that overrides
     * start_date as the registration cutoff. All nullable: a cohort with no
     * dates behaves exactly as before (registration always open), so existing
     * owner-created cohorts are unaffected until the owner sets dates.
     */
    public function up(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('course_id');
            $table->date('end_date')->nullable()->after('start_date');
            $table->date('registration_deadline')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'end_date', 'registration_deadline']);
        });
    }
};
