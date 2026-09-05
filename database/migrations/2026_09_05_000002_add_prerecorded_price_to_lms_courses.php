<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate pre-recorded pricing (item 6). A course can charge LESS for the
 * pre-recorded (on-demand) mode than for live delivery. Nullable: when unset,
 * the pre-recorded mode falls back to the live `price` (no cheaper option), so
 * every existing course keeps its current single price with no behaviour change.
 * Only meaningful when the course has pre-recorded available AND the academy's
 * plan unlocks pre-recorded video (a Pro+ feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_courses') && ! Schema::hasColumn('lms_courses', 'prerecorded_price')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->decimal('prerecorded_price', 10, 2)->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lms_courses') && Schema::hasColumn('lms_courses', 'prerecorded_price')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->dropColumn('prerecorded_price');
            });
        }
    }
};
