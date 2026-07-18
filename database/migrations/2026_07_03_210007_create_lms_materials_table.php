<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lms_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('file_url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_materials');
    }
};
