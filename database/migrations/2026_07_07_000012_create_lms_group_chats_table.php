<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_group_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_id')->constrained('lms_tracks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_group_chats');
    }
};
