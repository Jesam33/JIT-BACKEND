<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained('lms_classrooms')->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->timestamps();

            $table->unique(['student_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_attendances');
    }
};
