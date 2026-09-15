<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Multiple labelled links per task submission: a task can ask for several URLs
// (e.g. "drop your repo, your live demo and your design file"), each with a
// label and a short comment describing what it is. Stored as a JSON array of
// [{label, url, comment}] rather than a dedicated table: hard-capped at 20,
// only ever read whole with the submission, and never queried on its own.
// `submitted_link` (single URL) stays for older submissions and simple cases.
//
// Existence-guarded like the other recent migrations (MySQL DDL here is not
// transactional, so a partially-failed run must be resumable).
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('lms_task_submissions', 'links')) {
            Schema::table('lms_task_submissions', function (Blueprint $table) {
                $table->json('links')->nullable()->after('submitted_link');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lms_task_submissions', 'links')) {
            Schema::table('lms_task_submissions', function (Blueprint $table) {
                $table->dropColumn('links');
            });
        }
    }
};
