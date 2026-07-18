<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_chat_read_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students')->cascadeOnDelete();
            $table->enum('chat_type', ['group', 'dm']);
            $table->unsignedBigInteger('chat_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'chat_type', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_chat_read_states');
    }
};
