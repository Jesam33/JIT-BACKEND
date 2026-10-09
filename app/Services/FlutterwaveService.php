<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave API (v3) for academy course payments in every country.
 *
 *   - Each academy's bank is a Flutterwave SUBACCOUNT (createSubaccount).
 *   - A student payment is a hosted checkout (initializePayment) that names
 *     the subaccount and, as a flat_subaccount charge, the platform's service
 *     charge: the academy's bank gets the rest, automatically, on Flutterwave's
 *     settlement cycle.
 *   - Confirmation re-reads the transaction from Flutterwave
 *     (verifyByReference), never trusting the redirect or webhook body.
 *   - Monthly renewals charge the card token saved from the first payment
 *     (chargeToken).
 *
 * normalize() turns a Flutterwave transaction into the same shape Paystack
 * returns, so CourseBilling and the intake flow handle both gateways with one
 * code path. Every method returns an array and never throws.
 */
class FlutterwaveService
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.flutterwave.secret_key', '')) !== '';
    }

    private function client(): PendingRequest
    {
        $client = Http::withToken((string) config('services.flutterwave.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        // Local dev PHP often has no CA bundle (same allowance as PaystackService).
        if (app()->environment('local')) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'), '/') . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'error', 'message' => 'Payment gateway not configured.'];
        }

        try {
            $response = $method === 'GET'
                ? $this->client()->get($this->url($path), $payload)
                : $this->client()->send($method, $this->url($path), $method === 'DELETE' ? [] : ['json' => $payload]);

            $body = $response->json();

            return is_array($body) ? $body : ['status' => 'error', 'message' => 'Unexpected response from the payment gateway.'];
        } catch (\Throwable $e) {
            Log::warning('Flutterwave call failed', ['path' => $path, 'err' => $e->getMessage()]);

            return ['status' => 'error', 'message' => 'Could not reach the payment gateway. Please try again.'];
        }
    }

    public static function ok(array $body): bool
    {
        return ($body['status'] ?? '') === 'success';
    }

    /** Banks (and mobile-money wallets) Flutterwave pays into in a country. @return array<int, array{id: int, code: string, name: string}> */
    public function listBanks(string $country): array
    {
        $body = $this->call('GET', 'banks/' . strtoupper($country));

        return collect((array) ($body['data'] ?? []))
            ->map(fn ($b) => ['id' => (int) ($b['id'] ?? 0), 'code' => (string) ($b['code'] ?? ''), 'name' => (string) ($b['name'] ?? '')])
            ->filter(fn ($b) => $b['code'] !== '' && $b['name'] !== '')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** Branches of one bank (GH, UG, TZ, RW). Often empty. @return array<int, array{code: string, name: string}> */
    public function bankBranches(int $bankId): array
    {
        $body = $this->call('GET', 'banks/' . $bankId . '/branches');

        return collect((array) ($body['data'] ?? []))
            ->map(fn ($b) => ['code' => (string) ($b['branch_code'] ?? ''), 'name' => (string) ($b['branch_name'] ?? '')])
            ->filter(fn ($b) => $b['code'] !== '')
            ->values()
            ->all();
    }

    /** Account holder name for a Nigerian (or Ghanaian) account, or null. */
    public function resolveAccount(string $accountNumber, string $bankCode): ?string
    {
        $body = $this->call('POST', 'accounts/resolve', ['account_number' => $accountNumber, 'account_bank' => $bankCode]);

        return static::ok($body) ? ($body['data']['account_name'] ?? null) : null;
    }

    /**
     * Create an academy's subaccount. $fields: account_bank, account_number,
     * business_name, business_email, business_mobile, country, meta (list of
     * {meta_name, meta_value}). The split stored on it is a fallback only:
     * every payment sends the exact service charge.
     */
    public function createSubaccount(array $fields, float $percent): array
    {
        return $this->call('POST', 'subaccounts', $fields + [
            'split_type' => 'percentage',
            'split_value' => round($percent / 100, 4),
        ]);
    }

    public function deleteSubaccount(int $id): bool
    {
        return static::ok($this->call('DELETE', 'subaccounts/' . $id));
    }

    /**
     * Start a hosted checkout. Returns the raw body; data.link is the page.
     * $serviceCharge (major units) is held back for the platform; the rest of
     * the payment goes to $subaccountId's bank.
     */
    public function initializePayment(string $reference, float $amount, string $currency, string $email, string $name, string $redirectUrl, ?string $subaccountId, ?float $serviceCharge, array $meta = [], string $title = 'Course payment'): array
    {
        $payload = [
            'tx_ref' => $reference,
            'amount' => round($amount, 2),
            'currency' => strtoupper($currency),
            'redirect_url' => $redirectUrl,
            'customer' => ['email' => $email, 'name' => $name !== '' ? $name : $email],
            'meta' => $meta,
            'customizations' => ['title' => $title],
            // Ask for the methods people in that currency actually use. Without
            // this the checkout shows only what the dashboard defaults allow.
            'payment_options' => static::paymentOptions($currency),
        ];

        if ($split = $this->split($subaccountId, $serviceCharge)) {
            $payload['subaccounts'] = $split;
        }

        return $this->call('POST', 'payments', $payload);
    }

    /**
     * Checkout payment methods for a currency (Flutterwave `payment_options`).
     * Card everywhere, plus the local favourites: bank transfer and USSD in
     * Nigeria, mobile money across East/West Africa, M-Pesa in Kenya.
     * A method not enabled on the Flutterwave account is simply not shown.
     */
    public static function paymentOptions(string $currency): string
    {
        return match (strtoupper($currency)) {
            'NGN' => 'card,banktransfer,ussd,account',
            'GHS' => 'card,mobilemoneyghana',
            'KES' => 'card,mpesa',
            'UGX' => 'card,mobilemoneyuganda',
            'RWF' => 'card,mobilemoneyrwanda',
            'TZS' => 'card,mobilemoneytanzania',
            'ZMW' => 'card,mobilemoneyzambia',
            'XAF', 'XOF' => 'card,mobilemoneyfranco',
            'USD', 'GBP', 'EUR' => 'card,paypal',
            default => 'card',
        };
    }

    /** Charge a saved card token (monthly renewal, no student present). */
    public function chargeToken(string $token, string $email, float $amount, string $currency, string $reference, ?string $subaccountId, ?float $serviceCharge, ?string $country = null): array
    {
        $payload = [
            'token' => $token,
            'email' => $email,
            'amount' => round($amount, 2),
            'currency' => strtoupper($currency),
            'tx_ref' => $reference,
        ];
        if ($country) {
            $payload['country'] = strtoupper($country);
        }
        if ($split = $this->split($subaccountId, $serviceCharge)) {
            $payload['subaccounts'] = $split;
        }

        return $this->call('POST', 'tokenized-charges', $payload);
    }

    /** The transaction for our reference, straight from Flutterwave. */
    public function verifyByReference(string $reference): array
    {
        return $this->call('GET', 'transactions/verify_by_reference', ['tx_ref' => $reference]);
    }

    /**
     * A Flutterwave transaction in Paystack's shape: status 'success',
     * amount in minor units, currency, and a reusable card under
     * `authorization` (Flutterwave's card token) for monthly renewals.
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $card = (array) ($data['card'] ?? []);
        [$expMonth, $expYear] = array_pad(explode('/', (string) ($card['expiry'] ?? '')), 2, null);
        $status = strtolower((string) ($data['status'] ?? ''));

        return [
            'gateway' => 'flutterwave',
            'status' => $status === 'successful' ? 'success' : ($status ?: 'unknown'),
            'reference' => $data['tx_ref'] ?? null,
            'transaction_id' => $data['id'] ?? null,
            'amount' => (int) round(((float) ($data['amount'] ?? 0)) * 100),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'channel' => $data['payment_type'] ?? null,
            'customer_email' => $data['customer']['email'] ?? null,
            'authorization' => ! empty($card['token']) ? [
                'authorization_code' => (string) $card['token'],
                'reusable' => true,
                'channel' => 'card',
                'last4' => $card['last_4digits'] ?? null,
                'card_type' => $card['type'] ?? null,
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
            ] : [],
        ];
    }

    /** @return array<int, array<string, mixed>>|null */
    private function split(?string $subaccountId, ?float $serviceCharge): ?array
    {
        if (! $subaccountId) {
            return null;
        }

        $entry = ['id' => $subaccountId];
        if ($serviceCharge !== null && $serviceCharge >= 0) {
            // The subaccount is charged exactly this much; it keeps the rest.
            $entry['transaction_charge_type'] = 'flat_subaccount';
            $entry['transaction_charge'] = round($serviceCharge, 2);
        }

        return [$entry];
    }
}
