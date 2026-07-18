<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_sessions', function (Blueprint $table): void {
            $table->id();
            $table->enum('role', ['student', 'teacher']);
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['role', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_sessions');
    }
};
