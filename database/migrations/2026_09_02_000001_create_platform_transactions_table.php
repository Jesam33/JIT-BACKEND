<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The host's own revenue ledger: every institute→PLATFORM payment (a paid
     * signup or a plan upgrade). This is deliberately NOT tenant-scoped — it is
     * the super-admin's cross-tenant record of what Jorsas itself has been paid,
     * used to reconcile against Paystack and to see subscription revenue at a
     * glance.
     *
     * Distinct from the existing `payments` table, which records student→INSTITUTE
     * course fees (tenant-scoped, tied to a training_registration). That table
     * answers "how much has this institute earned"; this one answers "how much has
     * the platform earned from this institute".
     *
     * `tenant_name` is snapshotted at write time so a transaction stays legible in
     * the ledger even after its institute (and the tenants row) is deleted.
     */
    public function up(): void
    {
        if (Schema::hasTable('platform_transactions')) {
            return;
        }

        Schema::create('platform_transactions', function (Blueprint $table): void {
            $table->id();
            // Nullable + nullOnDelete: keep the revenue row even if the institute
            // is later removed (the snapshot name/plan below preserve context).
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('tenant_name')->nullable();
            $table->string('plan')->nullable();
            // 'tenant_signup' (first paid signup) or 'plan_upgrade' (later change).
            $table->string('purpose')->default('plan_upgrade');
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            // pending → success | failed. Only 'success' rows count as revenue.
            $table->string('status')->default('pending')->index();
            $table->string('gateway')->default('paystack');
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_transactions');
    }
};
