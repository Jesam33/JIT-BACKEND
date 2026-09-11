<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill: strip the origin from host-absolute storage URLs in the three
 * in-app file-link columns, turning
 *   http://127.0.0.1:8000/storage/materials/x.pdf  →  /storage/materials/x.pdf
 *
 * New saves are relative (see BaseLmsController::publicFileUrl()): an absolute
 * URL bakes in whatever host Laravel ran under, so a row saved locally 404s
 * from any other host that loads the app — a phone testing the portal, or the
 * live domain. Relative links are served same-origin by the Next /storage
 * rewrite instead.
 *
 * Only OUR hosts are rewritten (app.url, the frontend URL, localhost variants)
 * so a user-pasted external link that merely contains "/storage/" is left
 * alone. Idempotent: already-relative rows don't match the LIKE filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hosts = $this->ourHosts();

        $targets = [
            ['lms_materials', 'file_url'],
            ['lms_module_contents', 'content_url'],
            ['lms_task_submissions', 'submitted_file_url'],
        ];

        foreach ($targets as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where($column, 'like', 'http%://%/storage/%')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, $column, $hosts) {
                    foreach ($rows as $row) {
                        $url = $row->{$column};
                        $host = parse_url($url, PHP_URL_HOST);

                        // Only rewrite links pointing at a host we run; anything
                        // else (Bunny embeds, pasted external links) is foreign.
                        if (! $host || ! in_array(strtolower($host), $hosts, true)) {
                            continue;
                        }

                        $pos = strpos($url, '/storage/');
                        if ($pos === false) {
                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update([
                            $column => substr($url, $pos),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Irreversible by design: the correct host for a relative URL depends on
        // where the app is served from, which is exactly the information the
        // absolute form wrongly froze. Re-running up() is a no-op.
    }

    /**
     * Hosts that are "us": APP_URL and the frontend URL (live), plus the local
     * dev variants a developer might have run `artisan serve` under.
     */
    private function ourHosts(): array
    {
        $candidates = [
            config('app.url'),
            config('saas.frontend_url'),
            'http://localhost:8000',
            'http://127.0.0.1:8000',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ];

        $hosts = [];
        foreach ($candidates as $url) {
            if (! $url) {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
};
