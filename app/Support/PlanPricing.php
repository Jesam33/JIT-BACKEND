<?php

namespace App\Support;

use App\Services\CurrencyService;

/**
 * What a Jorsas plan costs in an academy's own currency.
 *
 * Plan prices are set in naira (config saas.plans.{slug}.price). An academy
 * elsewhere pays in its own currency: a fixed price when one is configured
 * (saas.plans.{slug}.prices.{CODE}, e.g. 'USD' => 10), otherwise the naira
 * price converted at today's rate and rounded UP to a whole unit. With no rate
 * available the plan is charged in naira (cards abroad can still pay it).
 */
class PlanPricing
{
    /** @return array{amount: float, currency: string}|null  null = no self-serve price (Enterprise) */
    public static function priceIn(string $slug, ?string $currency): ?array
    {
        $plan = (array) config("saas.plans.$slug", []);
        if (! array_key_exists('price', $plan) || $plan['price'] === null) {
            return null;
        }

        $base = (float) $plan['price'];
        $currency = strtoupper(trim((string) $currency)) ?: 'NGN';

        if ($currency === 'NGN') {
            return ['amount' => $base, 'currency' => 'NGN'];
        }
        if ($base <= 0) {
            return ['amount' => 0.0, 'currency' => $currency];
        }

        $fixed = data_get($plan, "prices.$currency");
        if (is_numeric($fixed) && (float) $fixed > 0) {
            return ['amount' => (float) $fixed, 'currency' => $currency];
        }

        $converted = app(CurrencyService::class)->convertBetween($base, 'NGN', $currency);
        if ($converted === null || $converted <= 0) {
            return ['amount' => $base, 'currency' => 'NGN'];
        }

        return ['amount' => (float) max(1, ceil($converted)), 'currency' => $currency];
    }
}
