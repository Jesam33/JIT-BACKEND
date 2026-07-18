<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('lms_teachers')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->text('instructions')->nullable();
            $table->dateTime('due_at');
            $table->enum('submission_type', ['link', 'file_upload']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_tasks');
    }
};
