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

    public function initializeTransaction(string $email, float $amount, string $reference, array $metadata = [], ?string $callbackUrl = null): array
    {
        $client = $this->client();

        if (! $client) {
            return ['status' => false, 'message' => 'Payment gateway not configured.'];
        }

        if ($callbackUrl === null) {
            $callbackUrl = rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/') . '/institute/verify?reference=' . $reference;
        }

        $response = $client->post($this->baseUrl . '/transaction/initialize', [
            'email' => $email,
            'amount' => (int) round($amount * 100),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        return $response->json();
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
