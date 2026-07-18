<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_task_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('lms_tasks')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->string('submitted_link')->nullable();
            $table->string('submitted_file_url')->nullable();
            $table->dateTime('submitted_at');
            $table->decimal('score', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->foreignId('graded_by_teacher_id')->nullable()->constrained('lms_teachers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_task_submissions');
    }
};
