<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_messages', function (Blueprint $table): void {
            $table->id();
            $table->enum('from_role', ['student', 'teacher']);
            $table->unsignedBigInteger('from_id');
            $table->enum('to_role', ['student', 'teacher']);
            $table->unsignedBigInteger('to_id');
            $table->text('body');
            $table->timestamps();

            $table->index(['from_role', 'from_id']);
            $table->index(['to_role', 'to_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_messages');
    }
};
