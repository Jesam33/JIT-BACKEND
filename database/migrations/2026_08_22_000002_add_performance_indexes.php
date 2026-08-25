<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the hot lookup/aggregate columns that were missing one.
 *
 * Foreign keys created via constrained() are already indexed by MySQL, so this
 * only fills the gaps: non-FK filter columns (status/enrollment_id), the agent
 * referral columns added to training_registrations after table creation, and
 * the chat/scheduled-class composite lookups used by the polling endpoints.
 *
 * Every column is guarded with hasColumn so a renamed/restructured table simply
 * skips its index instead of failing, and each add is wrapped so re-running the
 * migration over a partially-indexed production DB is a no-op rather than an error.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:array<int,string>,2:string}> */
    private array $indexes = [
        ['agent_commissions', ['enrollment_id'], 'idx_agent_commissions_enrollment'],
        ['agent_commissions', ['status'], 'idx_agent_commissions_status'],
        ['payments', ['status'], 'idx_payments_status'],
        ['training_registrations', ['status'], 'idx_training_registrations_status'],
        ['training_registrations', ['referred_by_agent_id'], 'idx_tr_referred_agent'],
        ['training_registrations', ['registered_by_agent_id'], 'idx_tr_registered_agent'],
        ['lms_scheduled_classes', ['module_id', 'status'], 'idx_sched_module_status'],
        ['lms_messages', ['chat_type', 'chat_id'], 'idx_messages_chat'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! $this->columnsExist($table, $columns)) {
                continue;
            }

            try {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            } catch (\Throwable $e) {
                // Index already present (idempotent re-run) — ignore.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            } catch (\Throwable $e) {
                // Index absent — ignore.
            }
        }
    }

    /** @param array<int,string> $columns */
    private function columnsExist(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
