<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 7 TenantAware tables that were missing a tenant_id column.
     * Adding it (nullable + indexed) is additive and zero-downtime; existing
     * rows are backfilled by the tenants:establish-primary command.
     */
    private array $tables = [
        'lms_teachers',
        'lms_teacher_notifications',
        'lms_notifications',
        'lms_dm_threads',
        'lms_attendances',
        'lms_chat_read_states',
        'agent_sessions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    if (! Schema::hasColumn($tbl, 'tenant_id')) {
                        $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'tenant_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
