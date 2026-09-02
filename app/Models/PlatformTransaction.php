<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single institute→platform payment (paid signup or plan upgrade) — the host's
 * own revenue ledger. NOT tenant-scoped (no TenantAware): it's the super-admin's
 * cross-tenant view of what the platform has been paid. See the migration for how
 * this differs from the tenant-scoped course-fee `payments` table.
 *
 * All writes go through {@see recordPending()} / {@see markSuccess()} — both keyed
 * on the unique `reference` — so the signup-verify, billing-verify and webhook
 * paths can each call them, in any order, any number of times, without ever
 * double-counting revenue.
 */
class PlatformTransaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'tenant_name',
        'plan',
        'purpose',
        'reference',
        'amount',
        'currency',
        'status',
        'gateway',
        'gateway_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Record (or refresh) a pending transaction at the moment checkout is started.
     * Idempotent on `reference`: re-initialising the same reference updates the
     * pending row rather than inserting a duplicate, and never overwrites a row
     * that already reached a terminal state (success/failed).
     */
    public static function recordPending(Tenant $tenant, string $reference, float $amount, string $purpose, ?string $plan = null, string $currency = 'NGN'): void
    {
        if ($reference === '') {
            return;
        }

        $existing = static::where('reference', $reference)->first();
        if ($existing && $existing->status !== 'pending') {
            return; // already resolved — don't downgrade a confirmed row
        }

        static::updateOrCreate(
            ['reference' => $reference],
            [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'plan' => $plan ?? $tenant->plan,
                'purpose' => $purpose,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'status' => 'pending',
                'gateway' => 'paystack',
            ]
        );
    }

    /**
     * Confirm a transaction once its Paystack charge succeeds. Idempotent on
     * `reference`: the first call stamps success + paid_at, later calls (a racing
     * webhook, a repeat verify) are no-ops. Creates the row if checkout-time
     * recording was missed, so a confirmed payment is never lost to the ledger.
     *
     * `$verifiedAmountMinor` is Paystack's amount in minor units (kobo/cents) from
     * the verified payload; when present it's the authoritative figure recorded.
     */
    public static function markSuccess(Tenant $tenant, string $reference, string $purpose, ?string $plan = null, ?array $gatewayData = null): void
    {
        if ($reference === '') {
            return;
        }

        $existing = static::where('reference', $reference)->first();
        if ($existing && $existing->status === 'success') {
            return; // already counted
        }

        $attributes = [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'plan' => $plan ?? $existing->plan ?? $tenant->plan,
            'purpose' => $purpose,
            'status' => 'success',
            'gateway' => 'paystack',
            'paid_at' => now(),
        ];

        if (is_array($gatewayData)) {
            $attributes['gateway_response'] = $gatewayData;
            // Prefer the verified charged amount/currency (minor units → major).
            if (isset($gatewayData['amount'])) {
                $attributes['amount'] = round(((int) $gatewayData['amount']) / 100, 2);
            }
            if (! empty($gatewayData['currency'])) {
                $attributes['currency'] = strtoupper((string) $gatewayData['currency']);
            }
        }

        // Fall back to the plan price if we're creating fresh with no gateway amount.
        if (! isset($attributes['amount']) && ! $existing) {
            $attributes['amount'] = (float) (config("saas.plans.$plan.price", 0) ?? 0);
            $attributes['currency'] = 'NGN';
        }

        static::updateOrCreate(['reference' => $reference], $attributes);
    }
}
