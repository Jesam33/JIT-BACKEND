<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_dm_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('lms_teachers')->cascadeOnDelete();
            $table->foreignId('track_id')->constrained('lms_tracks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'instructor_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_dm_threads');
    }
};
