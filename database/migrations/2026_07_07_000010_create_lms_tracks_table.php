<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_tracks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('instructor_id')->constrained('lms_teachers')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_tracks');
    }
};
