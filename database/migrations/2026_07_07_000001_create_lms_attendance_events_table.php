<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('classroom_id')->constrained('lms_classrooms')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('lms_students')->nullOnDelete();
            $table->string('participant_id')->nullable();
            $table->string('participant_email')->nullable();
            $table->enum('event_type', ['joined', 'left']);
            $table->dateTime('event_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_attendance_events');
    }
};
