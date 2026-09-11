<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Materials uploaded from a staffer's PC (PDF/document/etc.) are stored on the
// platform's public disk, unlike videos which go straight to Bunny Stream. The
// stored path is kept here so deleting the material can also delete its file
// (the same pattern as lms_module_contents.file_path). Nullable: every existing
// material (pasted URLs + Bunny embeds) has no local file.
//
// Existence-guarded like the other recent migrations (MySQL DDL here is not
// transactional, so a partially-failed run must be resumable).
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('lms_materials', 'file_path')) {
            Schema::table('lms_materials', function (Blueprint $table) {
                $table->string('file_path')->nullable()->after('file_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lms_materials', 'file_path')) {
            Schema::table('lms_materials', function (Blueprint $table) {
                $table->dropColumn('file_path');
            });
        }
    }
};
