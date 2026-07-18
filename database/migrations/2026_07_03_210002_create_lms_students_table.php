<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('training_registration_id')->nullable()->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('selected_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->enum('learning_mode', ['live', 'pre_recorded'])->nullable();
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_students');
    }
};
