<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant uniqueness for agent (Admission Marketer) accounts.
 *
 * `agents.email` was created with a GLOBAL unique index (agents_email_unique,
 * see 2026_07_17_000001_create_agents_tables). Once agents became per-tenant —
 * the public can now register to become an agent of ANY academy from its
 * storefront — that global unique is wrong: the same person legitimately joins
 * more than one academy's marketer network with the same email. Convert it to a
 * composite (tenant_id, email) so the address need only be unique WITHIN an
 * academy. `agents.referral_code` stays GLOBALLY unique (referral links resolve
 * without a tenant, in LmsIntakeController::register), so it is untouched here.
 *
 * NULL tenant_id rows (agents predating the tenant_id backfill) stay
 * non-conflicting because MySQL treats NULLs as distinct in unique indexes.
 * Mirrors 2026_08_19_000001_make_lms_identities_unique_per_tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agents') || ! Schema::hasColumn('agents', 'tenant_id')) {
            return;
        }

        $global = 'agents_email_unique';
        $composite = 'agents_tenant_id_email_unique';

        if ($this->indexExists('agents', $global)) {
            DB::statement("ALTER TABLE `agents` DROP INDEX `{$global}`");
        }
        if (! $this->indexExists('agents', $composite)) {
            DB::statement("ALTER TABLE `agents` ADD UNIQUE `{$composite}` (`tenant_id`, `email`)");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agents')) {
            return;
        }

        $global = 'agents_email_unique';
        $composite = 'agents_tenant_id_email_unique';

        if ($this->indexExists('agents', $composite)) {
            DB::statement("ALTER TABLE `agents` DROP INDEX `{$composite}`");
        }
        // Best-effort restore of the old global unique. Fails if cross-tenant
        // duplicate emails now exist (exactly what up() enabled) — acceptable on
        // a rollback; leave the column non-unique in that case.
        if (! $this->indexExists('agents', $global)) {
            try {
                DB::statement("ALTER TABLE `agents` ADD UNIQUE `{$global}` (`email`)");
            } catch (\Throwable $e) {
                // intentionally ignored on rollback
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
