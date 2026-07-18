<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_password_resets', function (Blueprint $table): void {
            $table->id();
            $table->enum('role', ['student', 'staff']);
            $table->string('email')->index();
            $table->string('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->index(['role', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_password_resets');
    }
};
