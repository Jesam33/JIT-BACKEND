<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->dropForeign(['course_id']);
        });

        // Preserve the user-visible course_name field and clear legacy ids before attaching the LMS course foreign key.
        DB::table('training_registrations')->whereNotNull('course_id')->update(['course_id' => null]);

        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->dropForeign(['course_id']);
        });

        DB::table('training_registrations')->whereNotNull('course_id')->update(['course_id' => null]);

        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->foreign('course_id')->references('id')->on('training_courses')->nullOnDelete();
        });
    }
};