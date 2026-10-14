<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each student's and teacher's own timezone (an IANA name like "Europe/London"),
 * saved from their device when they use the portal, so times in their emails
 * are in their own time (App\Support\LocalTime). Null = not known yet: their
 * academy's timezone is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lms_students', 'lms_teachers'] as $table) {
            if (! Schema::hasColumn($table, 'timezone')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('timezone', 64)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['lms_students', 'lms_teachers'] as $table) {
            if (Schema::hasColumn($table, 'timezone')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('timezone');
                });
            }
        }
    }
};
