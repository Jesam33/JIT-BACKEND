<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live quizzes during a live class (Kahoot-style):
 *   - lms_live_quiz_rounds: a teacher's saved set of questions for a course,
 *     reusable across classes ("Week 3 revision").
 *   - lms_live_quiz_runs: one playing of a round inside one class. Holds the
 *     questions as they were when it started, and the live state the students'
 *     screens follow (phase, current question, when it opened).
 *   - lms_live_quiz_answers: one answer per student per question, with the
 *     time it took and the points it earned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_live_quiz_rounds')) {
            Schema::create('lms_live_quiz_rounds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
                $table->string('title', 160);
                $table->json('questions');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lms_live_quiz_runs')) {
            Schema::create('lms_live_quiz_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('round_id')->nullable()->constrained('lms_live_quiz_rounds')->nullOnDelete();
                $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
                $table->string('class_type', 16);
                $table->unsignedBigInteger('class_id');
                $table->string('title', 160);
                $table->json('questions');
                // lobby | question | reveal | finished
                $table->string('phase', 16)->default('lobby');
                $table->integer('current_index')->default(-1);
                $table->timestamp('phase_started_at')->nullable();
                $table->boolean('auto_advance')->default(false);
                $table->unsignedBigInteger('started_by')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['class_type', 'class_id', 'phase']);
            });
        }

        if (! Schema::hasTable('lms_live_quiz_answers')) {
            Schema::create('lms_live_quiz_answers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('run_id')->constrained('lms_live_quiz_runs')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
                $table->unsignedSmallInteger('question_index');
                $table->unsignedTinyInteger('choice');
                $table->boolean('correct')->default(false);
                $table->unsignedInteger('response_ms')->default(0);
                $table->unsignedInteger('points')->default(0);
                $table->timestamps();
                $table->unique(['run_id', 'student_id', 'question_index']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_live_quiz_answers');
        Schema::dropIfExists('lms_live_quiz_runs');
        Schema::dropIfExists('lms_live_quiz_rounds');
    }
};
