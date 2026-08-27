<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real course ratings for the storefront card's ★ average + (count).
 *
 * One row per (course, student). A student may re-rate — the controller does an
 * updateOrCreate, so the unique constraint keeps a single row and the average
 * stays honest. `tenant_id` is a plain nullable+indexed column (NOT a FK),
 * auto-stamped by the TenantAware trait like every other tenant table.
 *
 * Guarded (Schema::hasTable) + re-run safe, matching the 2026_08_* style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_course_reviews')) {
            return;
        }

        Schema::create('lms_course_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'student_id'], 'course_review_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_reviews');
    }
};
