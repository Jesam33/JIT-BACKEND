<?php

namespace App\Support;

use App\Services\CurrencyService;

/**
 * What a Jorsas plan costs in an academy's own currency, monthly or yearly.
 *
 * Regional pricing: Nigeria pays the naira price (config
 * saas.plans.{slug}.price). Everywhere else pays the plan's US-dollar price
 * (saas.plans.{slug}.usd_price: Basic $15, Pro $30), charged in the academy's
 * own currency at today's rate and rounded UP to a whole unit, because
 * converting the naira price gave richer countries a giveaway price. A fixed
 * price for one currency (saas.plans.{slug}.prices.{CODE}) overrides both.
 * With no exchange rate available the dollar price is charged in US dollars.
 * A plan without a dollar price falls back to converting the naira price.
 *
 * Yearly is 12x that monthly figure less saas.yearly_discount_percent, rounded
 * to a whole unit, so it tracks whatever the monthly price resolves to.
 */
class PlanPricing
{
    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /** Anything that isn't 'yearly' bills monthly. */
    public static function cycle(?string $cycle): string
    {
        return strtolower(trim((string) $cycle)) === self::YEARLY ? self::YEARLY : self::MONTHLY;
    }

    public static function yearlyDiscountPercent(): float
    {
        return max(0.0, min(100.0, (float) config('saas.yearly_discount_percent', 20)));
    }

    /** @return array{amount: float, currency: string}|null  null = no self-serve price (Enterprise) */
    public static function priceIn(string $slug, ?string $currency, ?string $cycle = self::MONTHLY): ?array
    {
        $monthly = static::monthlyIn($slug, $currency);
        if ($monthly === null || static::cycle($cycle) === self::MONTHLY || $monthly['amount'] <= 0) {
            return $monthly;
        }

        $yearly = $monthly['amount'] * 12 * (1 - static::yearlyDiscountPercent() / 100);

        return ['amount' => (float) max(1, round($yearly)), 'currency' => $monthly['currency']];
    }

    /** @return array{amount: float, currency: string}|null */
    private static function monthlyIn(string $slug, ?string $currency): ?array
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

        // Outside Nigeria: the dollar price, in the academy's own currency.
        $usd = (float) ($plan['usd_price'] ?? 0);
        if ($usd > 0) {
            if ($currency === 'USD') {
                return ['amount' => $usd, 'currency' => 'USD'];
            }
            $local = app(CurrencyService::class)->convertBetween($usd, 'USD', $currency);

            return $local !== null && $local > 0
                ? ['amount' => (float) max(1, ceil($local)), 'currency' => $currency]
                : ['amount' => $usd, 'currency' => 'USD'];
        }

        $converted = app(CurrencyService::class)->convertBetween($base, 'NGN', $currency);
        if ($converted === null || $converted <= 0) {
            return ['amount' => $base, 'currency' => 'NGN'];
        }

        return ['amount' => (float) max(1, ceil($converted)), 'currency' => $currency];
    }
}
