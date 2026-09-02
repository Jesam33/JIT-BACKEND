<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PaystackService
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paystack.secret_key', '');
        $this->baseUrl = 'https://api.paystack.co';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->secretKey);
    }

    private function client(): ?PendingRequest
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $client = Http::withToken($this->secretKey)
            ->acceptJson()
            ->throw();

        if (app()->environment('local')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    public function initializeTransaction(string $email, float $amount, string $reference, array $metadata = [], ?string $callbackUrl = null, ?string $subaccount = null, ?string $currency = null): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        if ($callbackUrl === null) {
            $callbackUrl = config('saas.frontend_url') . '/institute/verify?reference=' . $reference;
        }

        $payload = [
            'email' => $email,
            // `*100` is correct for both NGN (kobo) and USD (cents) — Paystack's
            // smallest-unit convention is the same for the currencies we charge.
            'amount' => (int) round($amount * 100),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        // Charge currency (NGN by default at the call site; USD only once the
        // platform's Paystack account is USD-enabled). Absent → Paystack uses the
        // account default (NGN), i.e. today's behavior.
        if ($currency) {
            $payload['currency'] = strtoupper($currency);
        }

        // Route the money to the institute's own Paystack subaccount (its bank),
        // so course fees settle to the institute — not the platform. The split
        // (platform commission + who bears the Paystack fee) is defined on the
        // subaccount at creation. The fee bearer is a platform config knob
        // (`saas.paystack_fee_bearer`, default `subaccount` → the institute bears
        // the gateway fee, unchanged). Absent a subaccount, funds settle to the
        // platform account as before (unchanged for the primary institute).
        if ($subaccount) {
            $payload['subaccount'] = $subaccount;
            $payload['bearer'] = (string) config('saas.paystack_fee_bearer', 'subaccount');
        }

        $response = $client->post($this->baseUrl . '/transaction/initialize', $payload);

        return $response->json();
    }

    /**
     * Create a Paystack subaccount for an institute so its course fees settle to
     * its own bank. `percentageCharge` is the platform's commission percentage
     * retained on each transaction (the remainder settles to the institute).
     * Returns the raw Paystack response; the caller stores data.subaccount_code.
     */
    public function createSubaccount(string $businessName, string $bankCode, string $accountNumber, float $percentageCharge): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        $response = $client->post($this->baseUrl . '/subaccount', [
            'business_name' => $businessName,
            'settlement_bank' => $bankCode,
            'account_number' => $accountNumber,
            'percentage_charge' => $percentageCharge,
        ]);

        return $response->json();
    }

    /**
     * Update an existing subaccount's split — used to keep the platform commission
     * in step when an institute changes plan (see Tenant::syncPayoutCommission()).
     * Only `percentage_charge` is sent; Paystack leaves the bank/name untouched.
     * `subaccountCode` is the stored ACCT_… code. Returns the raw Paystack response.
     */
    public function updateSubaccount(string $subaccountCode, float $percentageCharge): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        $response = $client->put($this->baseUrl . '/subaccount/' . $subaccountCode, [
            'percentage_charge' => $percentageCharge,
        ]);

        return $response->json();
    }

    /**
     * Deactivate a subaccount when an institute disconnects its payout bank.
     * Paystack has no delete endpoint for subaccounts, so teardown is a
     * `PUT /subaccount/:code` with `active:false` — the code stops accepting
     * splits and drops out of the active list on the Paystack dashboard.
     *
     * Best-effort and NON-throwing: disconnect is a local settings change the
     * owner must always be able to make, so an unconfigured/unreachable gateway
     * (or a code Paystack no longer knows) never blocks it. Returns true only when
     * Paystack confirms the deactivation, false otherwise — the caller clears its
     * local record either way.
     */
    public function deactivateSubaccount(string $subaccountCode): bool
    {
        $client = $this->client();

        if (! $client || $subaccountCode === '') {
            return false;
        }

        try {
            $response = $client->put($this->baseUrl . '/subaccount/' . $subaccountCode, [
                'active' => false,
            ]);

            return (bool) ($response->json()['status'] ?? false);
        } catch (\Throwable $e) {
            // client() uses ->throw(); swallow any transport/4xx so the local
            // disconnect still goes through. The subaccount lingering on Paystack
            // is a soft failure, not something to surface to the owner.
            return false;
        }
    }

    /**
     * List banks + their Paystack codes, for the institute's payout bank picker.
     * Defaults to Nigeria: Paystack subaccounts settle only to Nigerian banks, so
     * Nigeria-only is correct for a Paystack payout — the parameter is future-proofing
     * for a later multi-country payout provider. Returns [] when the gateway isn't
     * configured.
     */
    public function listBanks(string $country = 'nigeria'): array
    {
        $client = $this->client();

        if (! $client) {
            return [];
        }

        $response = $client->get($this->baseUrl . '/bank', ['country' => $country, 'perPage' => 100]);

        return $response->json()['data'] ?? [];
    }

    /**
     * Resolve a bank account number to its account-holder name via Paystack
     * (`GET /bank/resolve`), so an owner can confirm the account before linking
     * their payout subaccount. Returns the raw `data` array (contains
     * `account_name`, `account_number`) on success, or null on any failure —
     * unconfigured gateway, an unresolvable account (Paystack 422/400), or a
     * transport error. Never throws: the owner page must not 500 over a typo.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ?array
    {
        $client = $this->client();

        if (! $client) {
            return null;
        }

        try {
            $response = $client->get($this->baseUrl . '/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            $body = $response->json();

            if (($body['status'] ?? false) && ! empty($body['data'])) {
                return $body['data'];
            }

            return null;
        } catch (\Throwable $e) {
            // client() uses ->throw(); an invalid account returns 422 and raises
            // RequestException. Swallow it — the resolve is confirmatory, never blocking.
            return null;
        }
    }

    public function verifyTransaction(string $reference): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        $response = $client->get($this->baseUrl . "/transaction/verify/{$reference}");

        return $response->json();
    }
}
