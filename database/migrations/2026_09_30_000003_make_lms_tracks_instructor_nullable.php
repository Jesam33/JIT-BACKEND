<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cohort may exist without an instructor ("Unassigned"), and removing a
 * staff member unassigns their cohorts instead of deleting them.
 *
 * lms_tracks.instructor_id was NOT NULL with ON DELETE CASCADE:
 *   - the owner's Cohorts page offers "Unassigned", which the database then
 *     rejected (a server error on create/edit);
 *   - deleting a teacher silently deleted every cohort they led, and with it
 *     the cohort's enrolments, group chat and DM threads. The app worked around
 *     this by refusing to remove a teacher who still led a cohort.
 *
 * Now: nullable, ON DELETE SET NULL. The cohort, its students and its group
 * chat survive; the owner assigns a new instructor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_tracks') || ! Schema::hasColumn('lms_tracks', 'instructor_id')) {
            return;
        }

        $hasForeignKey = collect(Schema::getForeignKeys('lms_tracks'))
            ->contains(fn ($fk) => $fk['columns'] === ['instructor_id']);

        Schema::table('lms_tracks', function (Blueprint $table) use ($hasForeignKey): void {
            if ($hasForeignKey) {
                $table->dropForeign(['instructor_id']);
            }
        });

        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->unsignedBigInteger('instructor_id')->nullable()->change();
            $table->foreign('instructor_id')->references('id')->on('lms_teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Not reversed: re-adding NOT NULL + CASCADE would fail on unassigned
        // cohorts and bring back the silent cohort deletion.
    }
};
