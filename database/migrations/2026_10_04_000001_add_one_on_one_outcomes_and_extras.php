<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-on-one, round 3:
 *
 *   lms_scheduled_classes.outcome              null (not marked) | held |
 *                                              student_no_show | teacher_no_show
 *   lms_scheduled_classes.outcome_marked_by    teacher | owner
 *   lms_scheduled_classes.outcome_marked_at
 *   lms_scheduled_classes.no_show_reported_at  the student said "my teacher
 *                                              didn't come"; the owner decides
 *   lms_scheduled_classes.series_id            sessions booked together weekly
 *   lms_courses.one_on_one_extra_session_price price of one extra session when a
 *                                              student has used their allowance;
 *                                              null = extras not sold
 *   lms_one_on_one_credits                     extra sessions a student bought:
 *                                              for one month (monthly course,
 *                                              period = 'Y-m') or the whole
 *                                              course (period null)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_scheduled_classes')) {
            Schema::table('lms_scheduled_classes', function (Blueprint $table): void {
                if (! Schema::hasColumn('lms_scheduled_classes', 'outcome')) {
                    $table->string('outcome', 20)->nullable();
                }
                if (! Schema::hasColumn('lms_scheduled_classes', 'outcome_marked_by')) {
                    $table->string('outcome_marked_by', 10)->nullable();
                }
                if (! Schema::hasColumn('lms_scheduled_classes', 'outcome_marked_at')) {
                    $table->dateTime('outcome_marked_at')->nullable();
                }
                if (! Schema::hasColumn('lms_scheduled_classes', 'no_show_reported_at')) {
                    $table->dateTime('no_show_reported_at')->nullable();
                }
                // Sessions booked together as "every week" share this id.
                if (! Schema::hasColumn('lms_scheduled_classes', 'series_id')) {
                    $table->string('series_id', 36)->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('lms_courses') && ! Schema::hasColumn('lms_courses', 'one_on_one_extra_session_price')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->decimal('one_on_one_extra_session_price', 12, 2)->nullable();
            });
        }

        if (! Schema::hasTable('lms_one_on_one_credits')) {
            Schema::create('lms_one_on_one_credits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
                $table->unsignedBigInteger('payment_id')->nullable()->unique();
                $table->unsignedSmallInteger('sessions');
                $table->string('period', 7)->nullable(); // 'Y-m' for monthly courses
                $table->string('status', 10)->default('pending'); // pending | paid
                $table->timestamps();
                $table->index(['student_id', 'status', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_one_on_one_credits');

        foreach ([
            'lms_scheduled_classes' => ['outcome', 'outcome_marked_by', 'outcome_marked_at', 'no_show_reported_at', 'series_id'],
            'lms_courses' => ['one_on_one_extra_session_price'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($tableName, $c)));
            if ($present) {
                Schema::table($tableName, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }
};
