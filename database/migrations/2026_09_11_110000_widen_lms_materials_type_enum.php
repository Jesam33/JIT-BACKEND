<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen lms_materials.type to the type set the application actually uses.
 * The column was created (2026_07_07) as enum('file','link','image'), but the
 * staff material endpoints validate 'in:pdf,doc,video,link,other', Bunny video
 * materials store 'video', and AI (Gamma) saves store 'pdf'/'doc' — the live
 * database was widened manually at some point, so only a freshly-migrated
 * database (tests, new installs) still carries the narrow enum and rejects
 * those writes with "Data truncated for column 'type'".
 *
 * The new list is a SUPERSET: legacy 'file'/'image' rows keep their value
 * instead of being mangled by the MODIFY. Modifying an already-widened live
 * column to this same superset is a harmless rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_materials')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `lms_materials` "
            . "MODIFY COLUMN `type` ENUM('pdf','doc','video','link','other','file','image') NOT NULL DEFAULT 'file'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('lms_materials')) {
            return;
        }

        // Fold the widened values back into the original set before narrowing,
        // otherwise the MODIFY itself would truncate them.
        DB::statement("UPDATE `lms_materials` SET `type` = 'file' WHERE `type` NOT IN ('file','link','image')");
        DB::statement(
            "ALTER TABLE `lms_materials` "
            . "MODIFY COLUMN `type` ENUM('file','link','image') NOT NULL DEFAULT 'file'"
        );
    }
};
