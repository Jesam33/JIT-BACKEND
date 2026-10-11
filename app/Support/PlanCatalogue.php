<?php

namespace App\Support;

/**
 * The single source of truth for the public plan catalogue, shaped for plan
 * cards (billing portal AND the public signup page): price, per-plan
 * commission, the three limits (null = unlimited) and the feature flags, so
 * both surfaces render identical cards from one call.
 *
 * Extracted verbatim from TenantBillingController::planCatalogue(); that
 * controller and PublicPagesController::plansJson both delegate here so the
 * owner-facing upgrade cards and the signup "Choose your plan" cards can
 * never drift apart again.
 */
class PlanCatalogue
{
    /**
     * @param  string|null  $currency  also price each plan in this currency
     *                                 (local_price + currency, see PlanPricing);
     *                                 `price` always stays the naira base.
     */
    public static function all(?string $currency = null): array
    {
        $default = config('saas.platform_commission_percent', 2);

        // Every feature flag the plan model exposes, in display order, kept in
        // sync with config/saas.php `features` and the card UI's labels. `free`
        // lists them all (false), so data_get always resolves.
        $featureKeys = [
            'live_classes', 'chat', 'certificates', 'pre_recorded_video', 'quizzes',
            'admission_marketer', 'remove_branding', 'advanced_analytics',
            'advanced_reporting', 'custom_domain', 'priority_support',
            'ai_materials', 'api_access', 'white_label',
        ];

        return collect(config('saas.plans', []))
            ->map(fn ($plan, $slug) => [
                'slug' => $slug,
                'name' => $plan['name'] ?? ucfirst($slug),
                'label' => $plan['label'] ?? null,
                // Enterprise has no self-serve price, it's a contact-sales tier,
                // so price stays null (the UI renders "Contact sales", not ₦0).
                'price' => array_key_exists('price', $plan) && $plan['price'] !== null ? (float) $plan['price'] : null,
                'contact_sales' => (bool) ($plan['contact_sales'] ?? false),
                'commission_percent' => (float) ($plan['commission_percent'] ?? $default),
                'limits' => [
                    'courses' => data_get($plan, 'limits.courses'),
                    'students' => data_get($plan, 'limits.students'),
                    'staff' => data_get($plan, 'limits.staff'),
                ],
                'features' => collect($featureKeys)
                    ->mapWithKeys(fn ($k) => [$k => (bool) data_get($plan, "features.$k", false)])
                    ->all(),
            ] + static::localPrice((string) $slug, $currency))
            ->values()
            ->all();
    }

    /** @return array{local_price?: ?float, currency?: string} */
    private static function localPrice(string $slug, ?string $currency): array
    {
        if ($currency === null) {
            return [];
        }

        $local = PlanPricing::priceIn($slug, $currency);

        return [
            'local_price' => $local['amount'] ?? null,
            'currency' => $local['currency'] ?? strtoupper($currency),
        ];
    }
}
