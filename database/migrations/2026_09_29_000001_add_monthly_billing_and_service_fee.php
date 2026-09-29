<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly courses + the per-payment platform service charge.
 *
 * lms_courses.billing_type
 *   'one_time' (every existing course, unchanged) or 'monthly': the student pays
 *   the course price every month to keep access.
 *
 * training_registrations (the student's purchase of one course)
 *   billing_type             frozen from the course at registration, so an owner
 *                            flipping the course later never re-bills a student
 *   paid_until               end of the period the student has paid for
 *   billing_status           active | past_due | cancelled | ended (null = one-time)
 *   paystack_authorization   reusable card authorization for automatic renewals
 *                            (encrypted cast on the model; null for transfer/USSD)
 *   billing_cancelled_at     when the student stopped renewing
 *   billing_reminded_for     the paid_until value the "due soon" reminder was sent
 *                            for, so each period is reminded exactly once
 *   billing_last_attempt_at  last automatic card charge, caps retries at one a day
 *   billing_lock_notified_at when the "access paused" notice went out
 *
 * payments
 *   kind            'initial' (first purchase, every existing row) or 'renewal'
 *   platform_fee    the platform's service charge taken on this payment (0 when
 *                   the money settled to the platform account unsplit, i.e. the
 *                   primary institute). Existing rows are backfilled at the rate
 *                   in force when they were paid, see backfillHistoricFees().
 *   academy_amount  what settled to the academy (amount - platform_fee); null
 *                   when the payment was not split
 *   period_end      for monthly payments, the paid_until this payment bought
 *
 * Guarded + idempotent (Schema::hasColumn), like the other additive migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_courses') && ! Schema::hasColumn('lms_courses', 'billing_type')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->string('billing_type', 20)->default('one_time')->after('prerecorded_price');
            });
        }

        if (Schema::hasTable('training_registrations')) {
            $columns = [
                'billing_type' => fn (Blueprint $t) => $t->string('billing_type', 20)->default('one_time'),
                'paid_until' => fn (Blueprint $t) => $t->dateTime('paid_until')->nullable(),
                'billing_status' => fn (Blueprint $t) => $t->string('billing_status', 20)->nullable()->index(),
                'paystack_authorization' => fn (Blueprint $t) => $t->text('paystack_authorization')->nullable(),
                'billing_cancelled_at' => fn (Blueprint $t) => $t->dateTime('billing_cancelled_at')->nullable(),
                'billing_reminded_for' => fn (Blueprint $t) => $t->dateTime('billing_reminded_for')->nullable(),
                'billing_last_attempt_at' => fn (Blueprint $t) => $t->dateTime('billing_last_attempt_at')->nullable(),
                'billing_lock_notified_at' => fn (Blueprint $t) => $t->dateTime('billing_lock_notified_at')->nullable(),
            ];

            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('training_registrations', $name)) {
                    Schema::table('training_registrations', function (Blueprint $table) use ($add): void {
                        $add($table);
                    });
                }
            }
        }

        if (Schema::hasTable('payments')) {
            $columns = [
                'kind' => fn (Blueprint $t) => $t->string('kind', 20)->default('initial'),
                'platform_fee' => fn (Blueprint $t) => $t->decimal('platform_fee', 10, 2)->nullable(),
                'academy_amount' => fn (Blueprint $t) => $t->decimal('academy_amount', 10, 2)->nullable(),
                'period_end' => fn (Blueprint $t) => $t->dateTime('period_end')->nullable(),
            ];

            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('payments', $name)) {
                    Schema::table('payments', function (Blueprint $table) use ($add): void {
                        $add($table);
                    });
                }
            }

            $this->backfillHistoricFees();
        }
    }

    /**
     * Freeze the fee on every existing payment at the rate that applied WHEN IT
     * WAS PAID (the old per-plan 5% / 3% / 0%, primary institute 0%).
     *
     * Before this, the host ledger worked the platform's cut out on the fly from
     * each academy's CURRENT plan rate. With the rate now a flat 5%, doing that
     * would silently re-price every past Basic/Pro sale. Rates are a snapshot of
     * config/saas.php as it stood before 2026-09-29, deliberately hardcoded:
     * they describe history, so they must not follow the live config.
     *
     * Only rows with no fee yet are touched, so a re-run is a no-op.
     */
    private function backfillHistoricFees(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('payments', 'tenant_id')) {
            return;
        }

        $historicRates = ['free' => 5, 'basic' => 3, 'pro' => 0, 'enterprise' => 0];
        $primarySlug = (string) config('saas.primary_slug', 'jorsas');

        foreach (DB::table('tenants')->get(['id', 'slug', 'plan']) as $tenant) {
            $rate = $tenant->slug === $primarySlug
                ? 0
                : ($historicRates[$tenant->plan ?: 'free'] ?? $historicRates['free']);

            DB::table('payments')
                ->where('tenant_id', $tenant->id)
                ->whereNull('platform_fee')
                ->update([
                    'platform_fee' => DB::raw('ROUND(amount * ' . (float) $rate . ' / 100, 2)'),
                    'academy_amount' => DB::raw('amount - ROUND(amount * ' . (float) $rate . ' / 100, 2)'),
                ]);
        }
    }

    public function down(): void
    {
        $drop = [
            'lms_courses' => ['billing_type'],
            'training_registrations' => [
                'billing_type', 'paid_until', 'billing_status', 'paystack_authorization',
                'billing_cancelled_at', 'billing_reminded_for', 'billing_last_attempt_at',
                'billing_lock_notified_at',
            ],
            'payments' => ['kind', 'platform_fee', 'academy_amount', 'period_end'],
        ];

        foreach ($drop as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present) {
                Schema::table($table, function (Blueprint $t) use ($present): void {
                    $t->dropColumn($present);
                });
            }
        }
    }
};
