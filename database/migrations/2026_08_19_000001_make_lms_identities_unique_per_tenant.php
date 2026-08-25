<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant uniqueness for LMS teacher / student identities.
 *
 * lms_teachers and lms_students were created before multi-tenancy with GLOBAL
 * unique indexes on `email` (and later `username`). Once tenant_id was added,
 * those globals became wrong for a SaaS: the same person legitimately owns or
 * teaches at more than one institute, and a student may self-register at more
 * than one. Concretely, a paid signup seeds the owner as a teacher, which
 * collided with that person's existing teacher row on another tenant
 * (lms_teachers_email_unique) and 500'd /api/signup/verify — leaving the tenant
 * stuck "active" but unprovisioned.
 *
 * Convert each global unique to a composite (tenant_id, <col>) so the value need
 * only be unique WITHIN an institute. NULL tenant_id rows (not yet backfilled)
 * stay non-conflicting because MySQL treats NULLs as distinct in unique indexes.
 */
return new class extends Migration
{
    /** table => columns whose global unique must become per-tenant */
    private array $map = [
        'lms_teachers' => ['email', 'username'],
        'lms_students' => ['email', 'username'],
    ];

    public function up(): void
    {
        foreach ($this->map as $table => $cols) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }

                $global = "{$table}_{$col}_unique";
                $composite = "{$table}_tenant_id_{$col}_unique";

                if ($this->indexExists($table, $global)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$global}`");
                }
                if (! $this->indexExists($table, $composite)) {
                    DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$composite}` (`tenant_id`, `{$col}`)");
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->map as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }

                $global = "{$table}_{$col}_unique";
                $composite = "{$table}_tenant_id_{$col}_unique";

                if ($this->indexExists($table, $composite)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$composite}`");
                }
                // Best-effort restore of the old global unique. This can fail if
                // cross-tenant duplicates now exist (exactly what the up() enabled),
                // which is acceptable on a rollback — leave the column non-unique.
                if (! $this->indexExists($table, $global)) {
                    try {
                        DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$global}` (`{$col}`)");
                    } catch (\Throwable $e) {
                        // intentionally ignored on rollback
                    }
                }
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
