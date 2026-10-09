<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Localized DISPLAY pricing.
 *
 * Each academy sells in its own currency (Tenant::currency(); the charge is
 * always in that currency, frozen at registration). This service only produces
 * the cosmetic "≈ £30" a visitor elsewhere sees, converted from the academy's
 * price at live FX rates and rounded. Never authoritative; recomputed per view.
 *
 * FX rates are base-NGN (convertBetween goes through NGN for other bases),
 * fetched from a free no-key provider and cached. On provider downtime the
 * last-good rates are served; with no cache at all the display falls back to
 * the academy's own price, so the storefront never errors over FX.
 */
class CurrencyService
{
    private const TTL_KEY = 'fx:ngn:rates';

    private const LAST_GOOD_KEY = 'fx:ngn:rates:last_good';

    /**
     * ISO-3166-1 alpha-2 country → display currency. Eurozone members map to EUR;
     * a detected country not listed here falls back to USD (a global reference the
     * visitor understands); an *undetected* country falls back to base NGN.
     */
    private const COUNTRY_CURRENCY = [
        'NG' => 'NGN',
        'US' => 'USD', 'UM' => 'USD', 'EC' => 'USD', 'SV' => 'USD',
        'GB' => 'GBP', 'IM' => 'GBP', 'JE' => 'GBP', 'GG' => 'GBP',
        'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD',
        'GH' => 'GHS', 'KE' => 'KES', 'ZA' => 'ZAR', 'UG' => 'UGX',
        'TZ' => 'TZS', 'RW' => 'RWF', 'ET' => 'ETB', 'EG' => 'EGP',
        'MA' => 'MAD', 'CI' => 'XOF', 'SN' => 'XOF',
        'ZM' => 'ZMW', 'MW' => 'MWK', 'CM' => 'XAF',
        'IN' => 'INR', 'PK' => 'PKR', 'BD' => 'BDT', 'LK' => 'LKR',
        'CN' => 'CNY', 'JP' => 'JPY', 'KR' => 'KRW', 'SG' => 'SGD',
        'MY' => 'MYR', 'ID' => 'IDR', 'PH' => 'PHP', 'TH' => 'THB',
        'VN' => 'VND', 'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR',
        'TR' => 'TRY', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK',
        'DK' => 'DKK', 'PL' => 'PLN', 'RU' => 'RUB', 'BR' => 'BRL',
        'MX' => 'MXN', 'AR' => 'ARS',
        // Eurozone
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR',
        'PT' => 'EUR', 'IE' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR',
        'AT' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR', 'LU' => 'EUR',
        'SK' => 'EUR', 'SI' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR',
        'LT' => 'EUR', 'CY' => 'EUR', 'MT' => 'EUR', 'HR' => 'EUR',
    ];

    /** Currency → display symbol. Missing → the ISO code + a space (e.g. "KES "). */
    private const SYMBOLS = [
        'NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€',
        'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'GHS' => 'GH₵',
        'ZAR' => 'R', 'KES' => 'KSh', 'EGP' => 'E£', 'INR' => '₹',
        'JPY' => '¥', 'CNY' => '¥', 'KRW' => '₩', 'SGD' => 'S$',
        'AED' => 'AED ', 'SAR' => 'SAR ', 'BRL' => 'R$', 'CHF' => 'CHF ',
        'TRY' => '₺', 'RUB' => '₽', 'PHP' => '₱', 'THB' => '฿',
        'UGX' => 'USh ', 'TZS' => 'TSh ', 'RWF' => 'RF ', 'ZMW' => 'ZK ', 'MWK' => 'MK ',
        'XAF' => 'FCFA ', 'XOF' => 'CFA ',
    ];

    /**
     * Live base-NGN → * rates, cached. Returns null only when the provider is
     * down AND no last-good snapshot exists.
     *
     * @return array<string,float>|null
     */
    public function rates(): ?array
    {
        $cached = Cache::get(self::TTL_KEY);
        if (is_array($cached) && $cached) {
            return $cached;
        }

        $fetched = $this->fetchRates();
        if (is_array($fetched) && $fetched) {
            $minutes = max(1, (int) config('saas.fx_cache_minutes', 60));
            Cache::put(self::TTL_KEY, $fetched, now()->addMinutes($minutes));
            Cache::forever(self::LAST_GOOD_KEY, $fetched);

            return $fetched;
        }

        // Provider unavailable, serve the last-good snapshot if we ever had one.
        $lastGood = Cache::get(self::LAST_GOOD_KEY);

        return is_array($lastGood) && $lastGood ? $lastGood : null;
    }

    /**
     * Convert a base-NGN amount to another currency at live rates. Returns null
     * when no rate is available (so callers can fall back). NGN returns as-is.
     */
    public function convert(float $ngnAmount, string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'NGN') {
            return $ngnAmount;
        }

        $rates = $this->rates();
        $rate = $rates[$currency] ?? null;
        if (! $rate || ! is_numeric($rate)) {
            return null;
        }

        return $ngnAmount * (float) $rate;
    }

    /**
     * Convert between any two currencies through the base-NGN rate table
     * (X per 1 NGN). Null when either rate is missing.
     */
    public function convertBetween(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return $amount;
        }
        if ($from === 'NGN') {
            return $this->convert($amount, $to);
        }

        $rates = $this->rates();
        $fromRate = $rates[$from] ?? null;
        if (! $fromRate || ! is_numeric($fromRate) || (float) $fromRate <= 0) {
            return null;
        }

        $ngn = $amount / (float) $fromRate;

        return $to === 'NGN' ? $ngn : $this->convert($ngn, $to);
    }

    /**
     * Cosmetic display packet for a price in $base (the academy's currency)
     * given a visitor country. Falls back local → USD → base so it always
     * returns something renderable.
     *
     * @return array{currency:string,symbol:string,amount:float,is_base:bool}
     */
    public function displayFor(float $amount, ?string $countryCode, string $base = 'NGN'): array
    {
        $local = $countryCode ? $this->displayCurrencyForCountry($countryCode) : $base;

        return $this->displayInCurrency($amount, $local, $base);
    }

    /**
     * Cosmetic display packet in an explicit currency (e.g. from the manual
     * currency selector). Same graceful fallback as {@see displayFor}: an
     * unsupported/rate-less currency degrades to USD, then to the base.
     *
     * @return array{currency:string,symbol:string,amount:float,is_base:bool}
     */
    public function displayInCurrency(float $amount, ?string $currency, string $base = 'NGN'): array
    {
        $base = strtoupper($base) ?: 'NGN';
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '' || $currency === $base) {
            return $this->pack($base, $amount, $base);
        }

        $converted = $this->convertBetween($amount, $base, $currency);

        // No rate for the requested currency → try USD as a universal reference.
        if ($converted === null && $currency !== 'USD') {
            $usd = $this->convertBetween($amount, $base, 'USD');
            if ($usd !== null) {
                return $this->pack('USD', $usd, $base);
            }
        }

        // Still nothing (FX provider fully down, no cache) → show the base price.
        if ($converted === null) {
            return $this->pack($base, $amount, $base);
        }

        return $this->pack($currency, $converted, $base);
    }

    /** @return array{currency:string,symbol:string,amount:float,is_base:bool} */
    private function pack(string $currency, float $amount, string $base = 'NGN'): array
    {
        return [
            'currency' => $currency,
            'symbol' => $this->symbol($currency),
            'amount' => $this->roundDisplay($amount),
            'is_base' => $currency === $base,
        ];
    }

    /** Display currency for a country: mapped → its currency; detected-but-unmapped → USD; undetected → NGN. */
    public function displayCurrencyForCountry(?string $countryCode): string
    {
        $country = strtoupper(trim((string) $countryCode));

        if ($country === '') {
            return 'NGN';
        }

        return self::COUNTRY_CURRENCY[$country] ?? 'USD';
    }

    public function symbol(string $currency): string
    {
        $currency = strtoupper($currency);

        return self::SYMBOLS[$currency] ?? ($currency . ' ');
    }

    /**
     * The currencies offered in the manual selector (the guaranteed fallback for
     * visitors whose country we can't detect or whose local currency we don't map).
     *
     * @return array<int,array{code:string,symbol:string}>
     */
    public function selectableCurrencies(): array
    {
        $codes = ['NGN', 'USD', 'GBP', 'EUR', 'CAD', 'GHS', 'ZAR', 'KES', 'INR', 'AUD'];

        return array_map(fn ($c) => ['code' => $c, 'symbol' => $this->symbol($c)], $codes);
    }

    /** Cosmetic rounding: whole units for readable prices, cents only for tiny amounts. */
    private function roundDisplay(float $amount): float
    {
        if ($amount > 0 && $amount < 10) {
            return round($amount, 2);
        }

        return round($amount);
    }

    /** @return array<string,float>|null */
    private function fetchRates(): ?array
    {
        $url = (string) config('saas.fx_provider_url', 'https://open.er-api.com/v6/latest/NGN');
        if ($url === '') {
            return null;
        }

        try {
            $http = Http::acceptJson()->timeout(6);
            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $body = $http->get($url)->json();
            $rates = $body['rates'] ?? null;

            // Sanity: a base-NGN feed must carry NGN=1. Guards against a provider
            // silently switching base currency on us.
            if (is_array($rates) && isset($rates['NGN']) && (float) $rates['NGN'] == 1.0) {
                return $rates;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
