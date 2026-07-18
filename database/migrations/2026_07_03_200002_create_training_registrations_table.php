<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('training_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('qualification_level');
            $table->string('phone_number');
            $table->string('email')->index();
            $table->string('whatsapp');
            $table->foreignId('course_id')->nullable()->constrained('training_courses')->nullOnDelete();
            $table->string('course_name');
            $table->enum('learning_mode', ['live', 'pre_recorded']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('invite_token')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_registrations');
    }
};
