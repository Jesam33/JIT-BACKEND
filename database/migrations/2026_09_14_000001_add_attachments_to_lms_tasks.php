<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Files a staffer attaches to a task (datasets, documents, images…) that
// students download while working on it. Stored as a JSON array of
// [{name, url, path, size}] rather than a dedicated table: hard-capped at 5,
// no lifecycle beyond task edit/delete, and never queried on its own. `path`
// is the public-disk location so removed/deleted attachments can delete their
// file too (same pattern as lms_materials.file_path).
//
// Existence-guarded like the other recent migrations (MySQL DDL here is not
// transactional, so a partially-failed run must be resumable).
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('lms_tasks', 'attachments')) {
            Schema::table('lms_tasks', function (Blueprint $table) {
                $table->json('attachments')->nullable()->after('submission_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lms_tasks', 'attachments')) {
            Schema::table('lms_tasks', function (Blueprint $table) {
                $table->dropColumn('attachments');
            });
        }
    }
};
