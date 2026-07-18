<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_teacher_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained('lms_teachers')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['teacher_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_teacher_notifications');
    }
};
