<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('objectives')->nullable();
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamps();
        });

        Schema::create('lms_module_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('lms_modules')->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['slides', 'pdf', 'video', 'link', 'text', 'code', 'file']);
            $table->text('content_url')->nullable();
            $table->longText('content_body')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lms_scheduled_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('lms_modules')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('lms_teachers')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('meeting_url')->nullable();
            $table->string('meeting_id')->nullable();
            $table->string('meeting_password')->nullable();
            $table->string('location')->nullable();
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_scheduled_classes');
        Schema::dropIfExists('lms_module_contents');
        Schema::dropIfExists('lms_modules');
    }
};
