<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Attendance for module classes (LmsScheduledClass). Historically only the
// legacy course-level classrooms (LmsClassroom) tracked attendance; every
// module-based live class recorded nothing. This adds a class_type
// discriminator plus a scheduled_class_id FK so both delivery types write
// into the same attendance tables.
//
// NOTE on the dropped UNIQUE indexes: a composite unique over
// (student_id, classroom_id, scheduled_class_id) cannot work in MySQL because
// NULLs are distinct in unique indexes (each row has exactly one of the two
// id columns set, the other NULL), so duplicates would never be blocked.
// Deduplication stays application-level via firstOrCreate()/updateOrCreate(),
// which is how the writers already work.
//
// The drops are EXISTENCE-GUARDED: MySQL migrations here are not
// transactional, and the classroom FK must be dropped BEFORE the unique
// index backing it (error 1553), so an earlier failed run may have removed
// only the FK. The guards let the migration resume from that mid-state
// while still running cleanly from a pristine schema.
return new class extends Migration {
    private function hasForeignKey(string $table, string $constraint): bool
    {
        return (bool) DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [DB::connection()->getDatabaseName(), $table, $constraint]
        )?->c;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (bool) DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [DB::connection()->getDatabaseName(), $table, $index]
        )?->c;
    }

    public function up(): void
    {
        // ── lms_attendances ──────────────────────────────────────────
        // The student_id FK is backed by the (student_id, classroom_id)
        // unique (student_id is its leftmost column), so BOTH foreign keys
        // must go before the unique can drop.
        $droppedStudentFk = $this->hasForeignKey('lms_attendances', 'lms_attendances_student_id_foreign');
        if ($droppedStudentFk) {
            Schema::table('lms_attendances', fn (Blueprint $t) => $t->dropForeign(['student_id']));
        }
        if ($this->hasForeignKey('lms_attendances', 'lms_attendances_classroom_id_foreign')) {
            Schema::table('lms_attendances', fn (Blueprint $t) => $t->dropForeign(['classroom_id']));
        }
        if ($this->hasIndex('lms_attendances', 'lms_attendances_student_id_classroom_id_unique')) {
            Schema::table('lms_attendances', fn (Blueprint $t) => $t->dropUnique('lms_attendances_student_id_classroom_id_unique'));
        }
        if (! $this->hasIndex('lms_attendances', 'lms_attendances_class_type_index')) {
            Schema::table('lms_attendances', function (Blueprint $table) use ($droppedStudentFk): void {
                $table->unsignedBigInteger('classroom_id')->nullable()->change();
                $table->foreign('classroom_id')->references('id')->on('lms_classrooms')->cascadeOnDelete();

                $table->string('class_type')->default('classroom')->index();
                $table->foreignId('scheduled_class_id')->nullable()
                    ->constrained('lms_scheduled_classes')->cascadeOnDelete();
                $table->index(['student_id', 'class_type', 'scheduled_class_id']);

                if ($droppedStudentFk) {
                    $table->foreign('student_id')->references('id')->on('lms_students')->cascadeOnDelete();
                }
            });
        }

        // ── lms_attendance_records ───────────────────────────────────
        if ($this->hasForeignKey('lms_attendance_records', 'lms_attendance_records_classroom_id_foreign')) {
            Schema::table('lms_attendance_records', fn (Blueprint $t) => $t->dropForeign(['classroom_id']));
        }
        if ($this->hasIndex('lms_attendance_records', 'lms_attendance_records_classroom_id_student_id_unique')) {
            Schema::table('lms_attendance_records', fn (Blueprint $t) => $t->dropUnique('lms_attendance_records_classroom_id_student_id_unique'));
        }
        if (! $this->hasIndex('lms_attendance_records', 'lms_attendance_records_class_type_index')) {
            Schema::table('lms_attendance_records', function (Blueprint $table): void {
                $table->unsignedBigInteger('classroom_id')->nullable()->change();
                $table->foreign('classroom_id')->references('id')->on('lms_classrooms')->cascadeOnDelete();

                $table->string('class_type')->default('classroom')->index();
                $table->foreignId('scheduled_class_id')->nullable()
                    ->constrained('lms_scheduled_classes')->cascadeOnDelete();
                $table->index(['class_type', 'scheduled_class_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('lms_attendance_records', function (Blueprint $table): void {
            $table->dropIndex(['class_type', 'scheduled_class_id']);
            $table->dropConstrainedForeignId('scheduled_class_id');
            $table->dropIndex(['class_type']);
            $table->dropForeign(['classroom_id']);
        });
        Schema::table('lms_attendance_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('classroom_id')->nullable(false)->change();
            $table->foreign('classroom_id')->references('id')->on('lms_classrooms')->cascadeOnDelete();
            $table->unique(['classroom_id', 'student_id'], 'lms_attendance_records_classroom_id_student_id_unique');
        });

        Schema::table('lms_attendances', function (Blueprint $table): void {
            $table->dropIndex(['student_id', 'class_type', 'scheduled_class_id']);
            $table->dropConstrainedForeignId('scheduled_class_id');
            $table->dropIndex(['class_type']);
            $table->dropForeign(['classroom_id']);
        });
        Schema::table('lms_attendances', function (Blueprint $table): void {
            $table->unsignedBigInteger('classroom_id')->nullable(false)->change();
            $table->foreign('classroom_id')->references('id')->on('lms_classrooms')->cascadeOnDelete();
            $table->unique(['student_id', 'classroom_id'], 'lms_attendances_student_id_classroom_id_unique');
        });
    }
};
