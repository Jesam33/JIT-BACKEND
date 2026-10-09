<?php

namespace App\Support;

/**
 * The countries an academy can be paid out in (Flutterwave subaccounts), each
 * with the currency the academy sells and is paid in, and what its bank form
 * needs:
 *
 *   bank_list  pick the bank from Flutterwave's list for that country
 *   iban       IBAN in the account number field + SWIFT code (UK, Eurozone)
 *   routing    US: routing number + SWIFT code as well
 *   branch     a branch code is needed (GH, UG, TZ, RW)
 *
 * Checked against the Flutterwave API on 2026-10-09: bank lists and checkout
 * worked for every currency here (test mode). Live payouts outside Nigeria
 * are subject to what Flutterwave enables on the account.
 */
class PayoutCountries
{
    /** @var array<string, array{name: string, currency: string, form: string, branch?: bool}> */
    public const ALL = [
        'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'form' => 'bank_list'],
        'GH' => ['name' => 'Ghana', 'currency' => 'GHS', 'form' => 'bank_list', 'branch' => true],
        'KE' => ['name' => 'Kenya', 'currency' => 'KES', 'form' => 'bank_list'],
        'UG' => ['name' => 'Uganda', 'currency' => 'UGX', 'form' => 'bank_list', 'branch' => true],
        'TZ' => ['name' => 'Tanzania', 'currency' => 'TZS', 'form' => 'bank_list', 'branch' => true],
        'RW' => ['name' => 'Rwanda', 'currency' => 'RWF', 'form' => 'bank_list', 'branch' => true],
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'form' => 'bank_list'],
        'ZM' => ['name' => 'Zambia', 'currency' => 'ZMW', 'form' => 'bank_list'],
        'MW' => ['name' => 'Malawi', 'currency' => 'MWK', 'form' => 'bank_list'],
        'CM' => ['name' => 'Cameroon', 'currency' => 'XAF', 'form' => 'bank_list'],
        'CI' => ['name' => "Côte d'Ivoire", 'currency' => 'XOF', 'form' => 'bank_list'],
        'SN' => ['name' => 'Senegal', 'currency' => 'XOF', 'form' => 'bank_list'],
        'EG' => ['name' => 'Egypt', 'currency' => 'EGP', 'form' => 'bank_list'],
        'US' => ['name' => 'United States', 'currency' => 'USD', 'form' => 'routing'],
        'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'form' => 'iban'],
        'IE' => ['name' => 'Ireland', 'currency' => 'EUR', 'form' => 'iban'],
        'DE' => ['name' => 'Germany', 'currency' => 'EUR', 'form' => 'iban'],
        'FR' => ['name' => 'France', 'currency' => 'EUR', 'form' => 'iban'],
        'NL' => ['name' => 'Netherlands', 'currency' => 'EUR', 'form' => 'iban'],
        'BE' => ['name' => 'Belgium', 'currency' => 'EUR', 'form' => 'iban'],
        'ES' => ['name' => 'Spain', 'currency' => 'EUR', 'form' => 'iban'],
        'IT' => ['name' => 'Italy', 'currency' => 'EUR', 'form' => 'iban'],
        'PT' => ['name' => 'Portugal', 'currency' => 'EUR', 'form' => 'iban'],
        'AT' => ['name' => 'Austria', 'currency' => 'EUR', 'form' => 'iban'],
        'FI' => ['name' => 'Finland', 'currency' => 'EUR', 'form' => 'iban'],
    ];

    public static function supports(?string $country): bool
    {
        return isset(self::ALL[strtoupper((string) $country)]);
    }

    public static function currencyFor(?string $country): ?string
    {
        return self::ALL[strtoupper((string) $country)]['currency'] ?? null;
    }

    public static function get(string $country): ?array
    {
        $code = strtoupper($country);

        return isset(self::ALL[$code]) ? ['code' => $code] + self::ALL[$code] : null;
    }

    /** For the owner's country picker. @return array<int, array{code: string, name: string, currency: string, form: string, branch: bool}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => $c) {
            $out[] = ['code' => $code, 'name' => $c['name'], 'currency' => $c['currency'], 'form' => $c['form'], 'branch' => (bool) ($c['branch'] ?? false)];
        }

        return $out;
    }
}
