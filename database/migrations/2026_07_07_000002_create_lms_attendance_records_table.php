<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('classroom_id')->constrained('lms_classrooms')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->integer('total_seconds')->default(0);
            $table->boolean('joined_within_10min')->default(false);
            $table->dateTime('first_joined_at')->nullable();
            $table->enum('status', ['present', 'late', 'partial', 'absent', 'made_up'])->default('absent');
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();
            $table->unique(['classroom_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_attendance_records');
    }
};
