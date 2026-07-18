<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->after('batch_id')->constrained('lms_courses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
