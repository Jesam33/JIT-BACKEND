<?php

namespace Tests\Feature;

use App\Services\CurrencyService;
use App\Support\PlanPricing;
use Tests\TestCase;

/**
 * Regional plan pricing: Nigeria pays the naira price; every other country pays
 * the plan's US-dollar price (Basic $15, Pro $30) in its own currency at the
 * day's rate, instead of the naira price converted (a giveaway abroad).
 */
class RegionalPlanPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'saas.plans.basic.price' => 5000, 'saas.plans.basic.usd_price' => 15, 'saas.plans.basic.prices' => [],
            'saas.plans.pro.price' => 15000, 'saas.plans.pro.usd_price' => 30, 'saas.plans.pro.prices' => [],
            'saas.yearly_discount_percent' => 20,
        ]);
    }

    /** A rate table: 1 USD = 0.79 GBP = 15.5 GHS. */
    private function fakeRates(?array $rates = ['GBP' => 0.79, 'GHS' => 15.5]): void
    {
        $this->app->instance(CurrencyService::class, new class($rates) extends CurrencyService
        {
            public function __construct(private ?array $rates) {}

            public function convertBetween(float $amount, string $from, string $to): ?float
            {
                if ($this->rates === null || strtoupper($from) !== 'USD') {
                    return null;
                }
                $rate = $this->rates[strtoupper($to)] ?? null;

                return $rate === null ? null : $amount * $rate;
            }
        });
    }

    public function test_nigeria_keeps_the_naira_prices(): void
    {
        $this->fakeRates();
        $this->assertSame(['amount' => 5000.0, 'currency' => 'NGN'], PlanPricing::priceIn('basic', 'NGN'));
        $this->assertSame(['amount' => 15000.0, 'currency' => 'NGN'], PlanPricing::priceIn('pro', 'NGN'));
    }

    public function test_a_us_academy_pays_the_dollar_prices(): void
    {
        $this->fakeRates();
        $this->assertSame(['amount' => 15.0, 'currency' => 'USD'], PlanPricing::priceIn('basic', 'USD'));
        $this->assertSame(['amount' => 30.0, 'currency' => 'USD'], PlanPricing::priceIn('pro', 'USD'));
        // Yearly: 12 x $30 less 20% = $288.
        $this->assertSame(['amount' => 288.0, 'currency' => 'USD'], PlanPricing::priceIn('pro', 'USD', 'yearly'));
    }

    public function test_other_countries_pay_the_dollar_price_in_their_own_currency(): void
    {
        $this->fakeRates();
        // $30 x 0.79 = £23.70 → rounded up to £24; $30 x 15.5 = GH₵465.
        $this->assertSame(['amount' => 24.0, 'currency' => 'GBP'], PlanPricing::priceIn('pro', 'GBP'));
        $this->assertSame(['amount' => 465.0, 'currency' => 'GHS'], PlanPricing::priceIn('pro', 'GHS'));
        $this->assertSame(['amount' => 12.0, 'currency' => 'GBP'], PlanPricing::priceIn('basic', 'GBP')); // $15 x 0.79 = 11.85 → 12
    }

    public function test_without_an_exchange_rate_it_charges_dollars(): void
    {
        $this->fakeRates(null);
        $this->assertSame(['amount' => 30.0, 'currency' => 'USD'], PlanPricing::priceIn('pro', 'GBP'));
    }

    public function test_a_fixed_price_for_a_currency_still_wins(): void
    {
        $this->fakeRates();
        config(['saas.plans.pro.prices' => ['GBP' => 29]]);
        $this->assertSame(['amount' => 29.0, 'currency' => 'GBP'], PlanPricing::priceIn('pro', 'GBP'));
    }
}
