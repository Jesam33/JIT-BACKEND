<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->string('title');
            $table->string('file_url')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_certificates');
    }
};
