<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_notifications');
    }
};
