<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module quizzes (paid plans): one 5-question multiple-choice quiz per module,
 * written by the teacher, auto-marked, with a scoreboard per quiz.
 *
 *   lms_quizzes          one per module (unique module_id); draft | published
 *   lms_quiz_questions   prompt, 2-4 options (JSON), the correct option index
 *   lms_quiz_attempts    one per student per quiz: answers as they go, the
 *                        score on submit, and how long they took (the
 *                        scoreboard's tie-break)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_quizzes')) {
            Schema::create('lms_quizzes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
                $table->foreignId('module_id')->unique()->constrained('lms_modules')->cascadeOnDelete();
                $table->string('title', 160);
                $table->text('instructions')->nullable();
                $table->unsignedSmallInteger('time_limit_minutes')->nullable();
                $table->dateTime('closes_at')->nullable();
                $table->string('status', 12)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['course_id', 'status']);
            });
        }

        if (! Schema::hasTable('lms_quiz_questions')) {
            Schema::create('lms_quiz_questions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('quiz_id')->constrained('lms_quizzes')->cascadeOnDelete();
                $table->unsignedTinyInteger('position');
                $table->text('prompt');
                $table->json('options');
                $table->unsignedTinyInteger('correct_index');
                $table->text('explanation')->nullable();
                $table->timestamps();
                $table->index(['quiz_id', 'position']);
            });
        }

        if (! Schema::hasTable('lms_quiz_attempts')) {
            Schema::create('lms_quiz_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('quiz_id')->constrained('lms_quizzes')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
                $table->dateTime('started_at');
                $table->dateTime('submitted_at')->nullable();
                $table->json('answers')->nullable();
                $table->unsignedTinyInteger('score')->nullable();
                $table->unsignedTinyInteger('total');
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->timestamps();
                $table->unique(['quiz_id', 'student_id']);
                $table->index(['quiz_id', 'submitted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_quiz_attempts');
        Schema::dropIfExists('lms_quiz_questions');
        Schema::dropIfExists('lms_quizzes');
    }
};
