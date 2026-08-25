<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real subscription state for the SaaS billing layer. Until now the selected
     * plan lived only in the `settings` JSON blob and no subscription status was
     * modelled, so billing logic had nothing to read. These columns are additive
     * and nullable/defaulted, so the change is zero-downtime.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'plan')) {
                $table->string('plan')->default('free')->after('status');
            }
            if (! Schema::hasColumn('tenants', 'subscription_status')) {
                $table->string('subscription_status')->default('active')->after('plan');
            }
            if (! Schema::hasColumn('tenants', 'current_period_end')) {
                $table->timestamp('current_period_end')->nullable()->after('subscription_status');
            }
        });

        // Backfill `plan` from the legacy settings->plan blob where present, so
        // existing tenants keep whatever plan they signed up with.
        foreach (DB::table('tenants')->select('id', 'settings')->get() as $row) {
            $settings = json_decode((string) ($row->settings ?? ''), true);
            $plan = is_array($settings) ? ($settings['plan'] ?? null) : null;
            if ($plan) {
                DB::table('tenants')->where('id', $row->id)->update(['plan' => $plan]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            foreach (['current_period_end', 'subscription_status', 'plan'] as $col) {
                if (Schema::hasColumn('tenants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
