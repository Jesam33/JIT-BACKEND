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
        $this->secretKey = (string) env('PAYSTACK_SECRET_KEY', '');
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

    public function initializeTransaction(string $email, float $amount, string $reference, array $metadata = [], ?string $callbackUrl = null, ?string $subaccount = null): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        if ($callbackUrl === null) {
            $callbackUrl = rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/') . '/institute/verify?reference=' . $reference;
        }

        $payload = [
            'email' => $email,
            'amount' => (int) round($amount * 100),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        // Route the money to the institute's own Paystack subaccount (its bank),
        // so course fees settle to the institute — not the platform. The split
        // (platform commission + who bears the Paystack fee) is defined on the
        // subaccount at creation. `bearer=subaccount` makes the institute bear
        // the gateway fee. Absent a subaccount, funds settle to the platform
        // account as before (unchanged for the primary institute).
        if ($subaccount) {
            $payload['subaccount'] = $subaccount;
            $payload['bearer'] = 'subaccount';
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
     * List Nigerian banks + their Paystack codes, for the institute's payout
     * bank picker. Returns [] when the gateway isn't configured.
     */
    public function listBanks(): array
    {
        $client = $this->client();

        if (! $client) {
            return [];
        }

        $response = $client->get($this->baseUrl . '/bank', ['country' => 'nigeria', 'perPage' => 100]);

        return $response->json()['data'] ?? [];
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
