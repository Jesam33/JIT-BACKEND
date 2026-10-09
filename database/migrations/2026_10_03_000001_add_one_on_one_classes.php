<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-on-one classes: a third way to take a course, beside live (group) and
 * pre-recorded. Same course content; the student gets private live sessions
 * with an assigned teacher instead of joining the cohort's group classes.
 *
 *   lms_courses.is_one_on_one_available / one_on_one_price
 *       the owner offers it per course, at its own price (Basic plan and up)
 *   learning_mode on training_registrations + lms_students
 *       enum(live, pre_recorded) → string, so it can also be 'one_on_one'
 *   lms_students.one_on_one_teacher_id
 *       the student's own teacher (null = "needs a teacher"); unassigned, not
 *       deleted, when the teacher's account goes
 *   lms_scheduled_classes.student_id
 *       set = a private session for that one student; null = a group class.
 *       Deleted with the student (a private session must never fall back to
 *       null and become visible to the whole course).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_courses')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                if (! Schema::hasColumn('lms_courses', 'is_one_on_one_available')) {
                    $table->boolean('is_one_on_one_available')->default(false);
                }
                if (! Schema::hasColumn('lms_courses', 'one_on_one_price')) {
                    $table->decimal('one_on_one_price', 10, 2)->nullable();
                }
            });
        }

        foreach (['training_registrations', 'lms_students'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'learning_mode')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $column = $table->string('learning_mode', 20);
                    // lms_students allows "not chosen yet"; registrations always have one.
                    $tableName === 'lms_students' ? $column->nullable()->change() : $column->change();
                });
            }
        }

        if (Schema::hasTable('lms_students') && ! Schema::hasColumn('lms_students', 'one_on_one_teacher_id')) {
            Schema::table('lms_students', function (Blueprint $table): void {
                $table->foreignId('one_on_one_teacher_id')->nullable()->constrained('lms_teachers')->nullOnDelete();
            });
        }

        if (Schema::hasTable('lms_scheduled_classes') && ! Schema::hasColumn('lms_scheduled_classes', 'student_id')) {
            Schema::table('lms_scheduled_classes', function (Blueprint $table): void {
                $table->foreignId('student_id')->nullable()->constrained('lms_students')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lms_scheduled_classes') && Schema::hasColumn('lms_scheduled_classes', 'student_id')) {
            Schema::table('lms_scheduled_classes', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('student_id');
            });
        }
        if (Schema::hasTable('lms_students') && Schema::hasColumn('lms_students', 'one_on_one_teacher_id')) {
            Schema::table('lms_students', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('one_on_one_teacher_id');
            });
        }
        if (Schema::hasTable('lms_courses')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                foreach (['is_one_on_one_available', 'one_on_one_price'] as $col) {
                    if (Schema::hasColumn('lms_courses', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        // learning_mode stays a string: narrowing back to the enum would fail
        // on any one_on_one rows.
    }
};
