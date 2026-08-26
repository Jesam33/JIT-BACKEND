<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Localized DISPLAY pricing + charge-currency resolution.
 *
 * Two separate concerns kept deliberately apart:
 *
 *  • DISPLAY — a cosmetic "≈ £30" the visitor sees, converted from the base NGN
 *    price at live FX rates and rounded. Never authoritative; recomputed per view.
 *  • CHARGE — the currency money is actually collected in: NGN for Nigeria, USD
 *    for everyone else *only when* `saas.usd_charge_enabled` is on (off by default,
 *    so today every buyer is charged NGN). Frozen server-side at registration.
 *
 * FX rates are base-NGN, fetched from a free no-key provider and cached. On
 * provider downtime the last-good rates are served; with no cache at all the
 * display silently falls back to base NGN — the storefront never errors over FX.
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

        // Provider unavailable — serve the last-good snapshot if we ever had one.
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
     * Cosmetic display packet for a base-NGN price given a visitor country.
     * Falls back local → USD → base NGN so it always returns something renderable.
     *
     * @return array{currency:string,symbol:string,amount:float,is_base:bool}
     */
    public function displayFor(float $ngnAmount, ?string $countryCode): array
    {
        return $this->displayInCurrency($ngnAmount, $this->displayCurrencyForCountry($countryCode));
    }

    /**
     * Cosmetic display packet in an explicit currency (e.g. from the manual
     * currency selector). Same graceful fallback as {@see displayFor}: an
     * unsupported/rate-less currency degrades to USD, then to base NGN.
     *
     * @return array{currency:string,symbol:string,amount:float,is_base:bool}
     */
    public function displayInCurrency(float $ngnAmount, ?string $currency): array
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '' || $currency === 'NGN') {
            return $this->pack('NGN', $ngnAmount);
        }

        $converted = $this->convert($ngnAmount, $currency);

        // No rate for the requested currency → try USD as a universal reference.
        if ($converted === null && $currency !== 'USD') {
            $usd = $this->convert($ngnAmount, 'USD');
            if ($usd !== null) {
                return $this->pack('USD', $usd);
            }
        }

        // Still nothing (FX provider fully down, no cache) → show base NGN.
        if ($converted === null) {
            return $this->pack('NGN', $ngnAmount);
        }

        return $this->pack($currency, $converted);
    }

    /** @return array{currency:string,symbol:string,amount:float,is_base:bool} */
    private function pack(string $currency, float $amount): array
    {
        return [
            'currency' => $currency,
            'symbol' => $this->symbol($currency),
            'amount' => $this->roundDisplay($amount),
            'is_base' => $currency === 'NGN',
        ];
    }

    /**
     * The currency the visitor is charged in: NGN for Nigeria (and whenever the
     * country is unknown), otherwise USD — but only when USD charging is enabled;
     * while it's off, everyone is charged NGN (today's behavior).
     */
    public function chargeCurrencyForCountry(?string $countryCode): string
    {
        $country = strtoupper(trim((string) $countryCode));

        if ($country === '' || $country === 'NG') {
            return 'NGN';
        }

        return config('saas.usd_charge_enabled') ? 'USD' : 'NGN';
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
