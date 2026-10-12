<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified-learner reviews: each review carries the proof that its author
 * actually learned (classes attended out of classes held, tasks/quizzes done,
 * finished with a certificate), stamped when it's written. Only verified
 * reviews count toward a course's stars. The academy can post one public reply.
 *
 * Existing reviews start unverified; `php artisan lms:verify-reviews` checks
 * them against their authors' records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_course_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('lms_course_reviews', 'verified')) {
                $table->boolean('verified')->default(false)->after('comment');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'attended_classes')) {
                $table->unsignedInteger('attended_classes')->default(0)->after('verified');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'classes_held')) {
                $table->unsignedInteger('classes_held')->default(0)->after('attended_classes');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'activities')) {
                $table->unsignedInteger('activities')->default(0)->after('classes_held');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'finished')) {
                $table->boolean('finished')->default(false)->after('activities');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'reply')) {
                $table->text('reply')->nullable()->after('finished');
            }
            if (! Schema::hasColumn('lms_course_reviews', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('reply');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_course_reviews', function (Blueprint $table) {
            foreach (['verified', 'attended_classes', 'classes_held', 'activities', 'finished', 'reply', 'replied_at'] as $col) {
                if (Schema::hasColumn('lms_course_reviews', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
