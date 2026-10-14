<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group class reminders ("starts in 20 minutes", "starts in 5 minutes"): one
 * stamp per reminder per class, so each goes out at most once. A reminder the
 * class was created too late for is stamped without sending (App\Services\
 * ClassReminders). Both kinds of class: module scheduled classes and the older
 * per-course classrooms.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lms_scheduled_classes', 'lms_classrooms'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'reminder_20_sent_at')) {
                    $t->timestamp('reminder_20_sent_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'reminder_5_sent_at')) {
                    $t->timestamp('reminder_5_sent_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['lms_scheduled_classes', 'lms_classrooms'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['reminder_20_sent_at', 'reminder_5_sent_at'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
