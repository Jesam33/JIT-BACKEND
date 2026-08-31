<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External-video fields for pre-recorded lessons + video materials.
 *
 * When a lesson video (module content) or a video material is hosted on Bunny
 * Stream, the bytes live on Bunny — never on our server or in our DB. These
 * nullable columns hold only the pointers: which provider, the provider's video
 * id (guid), a CDN thumbnail, the duration, and the encode status. The existing
 * `content_url` / `file_url` columns keep the player embed URL, so nothing about
 * how those rows are already read has to change — a plain link/pdf/text item
 * simply leaves all of these NULL.
 *
 * Guarded with hasColumn on both up() and down() so a partial/re-run is safe.
 */
return new class extends Migration
{
    /** The two content tables that can carry an externally-hosted video. */
    private array $tables = ['lms_module_contents', 'lms_materials'];

    private array $columns = ['provider', 'external_id', 'thumbnail_url', 'duration_seconds', 'status'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'provider')) {
                    // e.g. 'bunny_stream'; NULL for a plain link/pdf/text item.
                    $t->string('provider')->nullable()->after('type');
                }
                if (! Schema::hasColumn($table, 'external_id')) {
                    // The provider's video id (Bunny guid). Indexed for status polls.
                    $t->string('external_id')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'thumbnail_url')) {
                    $t->string('thumbnail_url', 2048)->nullable();
                }
                if (! Schema::hasColumn($table, 'duration_seconds')) {
                    $t->unsignedInteger('duration_seconds')->nullable();
                }
                if (! Schema::hasColumn($table, 'status')) {
                    // 'processing' | 'ready' | 'error' — mirrors the normalised Bunny state.
                    $t->string('status')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach ($this->columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        // external_id carries an index; Laravel drops it with the column on MySQL.
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};
