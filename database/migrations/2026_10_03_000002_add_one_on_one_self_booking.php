<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-on-one self-booking (Phase 2): students book their own private sessions
 * from the times their teacher makes available.
 *
 *   lms_teacher_availability   weekly open windows per teacher (weekday +
 *                              start/end, in the academy's timezone)
 *   lms_teacher_time_off       whole days a teacher is away (no slots)
 *   lms_courses.one_on_one_session_minutes   length of one session (default 60)
 *   lms_courses.one_on_one_session_limit     sessions a student may have per
 *                              paid month (monthly course) or in total
 *                              (one-time course); null = unlimited
 *   lms_students.one_on_one_self_booking     the teacher can switch booking off
 *                              for one student (they then schedule for them)
 *   lms_scheduled_classes.booked_by          'teacher' | 'student'
 *   lms_scheduled_classes.reminder_sent_at   the "starting soon" reminder, once
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_teacher_availability')) {
            Schema::create('lms_teacher_availability', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('teacher_id')->constrained('lms_teachers')->cascadeOnDelete();
                $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
                $table->time('start_time');
                $table->time('end_time');
                $table->timestamps();
                $table->index(['teacher_id', 'weekday']);
            });
        }

        if (! Schema::hasTable('lms_teacher_time_off')) {
            Schema::create('lms_teacher_time_off', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('teacher_id')->constrained('lms_teachers')->cascadeOnDelete();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('note', 120)->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'starts_on']);
            });
        }

        if (Schema::hasTable('lms_courses')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                if (! Schema::hasColumn('lms_courses', 'one_on_one_session_minutes')) {
                    $table->unsignedSmallInteger('one_on_one_session_minutes')->default(60);
                }
                if (! Schema::hasColumn('lms_courses', 'one_on_one_session_limit')) {
                    $table->unsignedSmallInteger('one_on_one_session_limit')->nullable();
                }
            });
        }

        if (Schema::hasTable('lms_students') && ! Schema::hasColumn('lms_students', 'one_on_one_self_booking')) {
            Schema::table('lms_students', function (Blueprint $table): void {
                $table->boolean('one_on_one_self_booking')->default(true);
            });
        }

        if (Schema::hasTable('lms_scheduled_classes')) {
            Schema::table('lms_scheduled_classes', function (Blueprint $table): void {
                if (! Schema::hasColumn('lms_scheduled_classes', 'booked_by')) {
                    $table->string('booked_by', 10)->nullable();
                }
                if (! Schema::hasColumn('lms_scheduled_classes', 'reminder_sent_at')) {
                    $table->dateTime('reminder_sent_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_teacher_time_off');
        Schema::dropIfExists('lms_teacher_availability');

        foreach ([
            'lms_courses' => ['one_on_one_session_minutes', 'one_on_one_session_limit'],
            'lms_students' => ['one_on_one_self_booking'],
            'lms_scheduled_classes' => ['booked_by', 'reminder_sent_at'],
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
