<?php

namespace App\Support;

use App\Services\CurrencyService;

/**
 * Money totals across academies that are paid in different currencies, for
 * the platform admin pages: each total is kept per currency, and the combined
 * figure is everything converted to naira at today's rate (CurrencyService).
 * A currency with no rate available is listed but left out of the naira total
 * (`unconverted`), never silently counted 1:1.
 */
class PlatformMoney
{
    /**
     * @param  array<string, float|int|string>  $byCurrency  ['NGN' => 120000, 'GHS' => 300]
     * @return array{ngn: float, breakdown: array<string, float>, unconverted: array<int, string>}
     */
    public static function total(array $byCurrency): array
    {
        $fx = app(CurrencyService::class);
        $ngn = 0.0;
        $breakdown = [];
        $unconverted = [];

        foreach ($byCurrency as $currency => $amount) {
            $currency = strtoupper((string) ($currency ?: 'NGN'));
            $amount = (float) $amount;
            if (abs($amount) < 0.005) {
                continue;
            }

            $breakdown[$currency] = round(($breakdown[$currency] ?? 0) + $amount, 2);
        }

        ksort($breakdown);
        // Naira first: it's the platform's home currency.
        if (isset($breakdown['NGN'])) {
            $breakdown = ['NGN' => $breakdown['NGN']] + $breakdown;
        }

        foreach ($breakdown as $currency => $amount) {
            $converted = $fx->convertBetween($amount, $currency, 'NGN');
            if ($converted === null) {
                $unconverted[] = $currency;

                continue;
            }
            $ngn += $converted;
        }

        return ['ngn' => round($ngn, 2), 'breakdown' => $breakdown, 'unconverted' => $unconverted];
    }

    /** Add $amount in $currency to a per-currency map. @param array<string, float> $map */
    public static function add(array &$map, ?string $currency, float $amount): void
    {
        $currency = strtoupper((string) ($currency ?: 'NGN'));
        $map[$currency] = ($map[$currency] ?? 0) + $amount;
    }

    /** One amount in naira (today's rate), or null when there's no rate. */
    public static function toNgn(float $amount, ?string $currency): ?float
    {
        return app(CurrencyService::class)->convertBetween($amount, strtoupper((string) ($currency ?: 'NGN')), 'NGN');
    }

    /** "GHS 3,000.00 · USD 120.00" (empty when there's only naira). */
    public static function breakdownLine(array $breakdown): string
    {
        if (count($breakdown) <= 1 && isset($breakdown['NGN'])) {
            return '';
        }

        return collect($breakdown)
            ->map(fn ($amount, $currency) => $currency . ' ' . number_format((float) $amount, 2))
            ->implode(' · ');
    }

    /**
     * The line under a headline total, so it always says what it is:
     * "Total, all in naira", or "Total in naira at today's rate: NGN … · GHS …"
     * once more than one currency is involved (plus any left out for lack of a rate).
     *
     * @param  array{ngn?: float, breakdown?: array<string, float>, unconverted?: array<int, string>}  $money
     */
    public static function totalNote(array $money): string
    {
        $line = static::breakdownLine($money['breakdown'] ?? []);
        if ($line === '') {
            return 'Total, all in naira';
        }

        $note = 'Total in naira at today\'s rate: ' . $line;
        if (! empty($money['unconverted'])) {
            $note .= ' (no rate yet for ' . implode(', ', $money['unconverted']) . ', not in the total)';
        }

        return $note;
    }

    /** "GH₵1,200" in a currency's own symbol. */
    public static function format(float $amount, ?string $currency, int $decimals = 0): string
    {
        return app(CurrencyService::class)->symbol(strtoupper((string) ($currency ?: 'NGN'))) . number_format($amount, $decimals);
    }
}
