<?php

namespace App\Services;

use App\Exceptions\GammaException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for the Gamma Generate API (developers.gamma.app), the Pro+ AI
 * training-material generator. This is the SOLE place the Gamma wire contract
 * lives, so any future API drift (field names, the version segment) is a
 * one-file change.
 *
 * Contract (API v1.0, verified against developers.gamma.app):
 *  - Auth header is `X-API-KEY` (NOT `Authorization: Bearer`).
 *  - generate → POST {base}/generations  (async; HTTP 200 → {generationId}).
 *  - status   → GET  {base}/generations/{id} → {status: pending|completed|failed,
 *               gammaUrl (editable doc), exportUrl? (pdf/pptx; ~1-week expiry),
 *               credits{deducted,remaining}, error{message,statusCode}}.
 *
 * Credentials come from config('services.gamma.*'), NEVER env() at the call
 * site, so they still resolve after `php artisan config:cache` on live (the
 * same trap that once 404'd LMS login when a flag was read via env() under a
 * cached config).
 *
 * Failure policy (see GammaException): an unset key → 503 ("not configured");
 * every other upstream failure is logged and re-thrown as a generic 502, never
 * 402, so a Gamma credit exhaustion can't trip the owner's plan UpgradeModal.
 */
class GammaService
{
    private string $key;

    private string $base;

    public function __construct()
    {
        $this->key = (string) config('services.gamma.key', '');
        $this->base = rtrim((string) config('services.gamma.base', 'https://public-api.gamma.app/v1.0'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->key !== '';
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw GammaException::notConfigured();
        }

        $client = Http::withHeaders([
            'X-API-KEY' => $this->key,
            'Content-Type' => 'application/json',
        ])->acceptJson()->timeout(30);

        // Mirror PaystackService: skip TLS verification only in local dev, where a
        // self-signed proxy/cert is common; production always verifies.
        if (app()->environment('local')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Kick off an async generation. $body is the already-validated, camelCase
     * Gamma payload (inputText, format, numCards, exportAs, themeId,
     * additionalInstructions, textOptions…). Returns the decoded response; the
     * caller reads `generationId`.
     */
    public function generate(array $body): array
    {
        return $this->send(fn (PendingRequest $c) => $c->post($this->base . '/generations', $body),
            'AI generation could not be started. Please try again.');
    }

    /** Poll a generation by id → {status, gammaUrl, exportUrl?, credits, error?}. */
    public function status(string $generationId): array
    {
        return $this->send(fn (PendingRequest $c) => $c->get($this->base . '/generations/' . urlencode($generationId)),
            'Could not check the generation status. Please try again.');
    }

    /**
     * Run one Gamma call, mapping every failure mode to a GammaException with a
     * clean, non-leaking message. A GammaException raised while building the
     * client (unset key → 503) passes straight through; anything else, a
     * transport error or a non-2xx upstream response, is logged with its detail
     * and surfaced as a generic 502.
     */
    private function send(callable $call, string $failureMessage): array
    {
        try {
            $client = $this->client();
        } catch (GammaException $e) {
            throw $e;
        }

        try {
            $response = $call($client);
        } catch (\Throwable $e) {
            Log::warning('Gamma transport error', ['error' => $e->getMessage()]);
            throw new GammaException($failureMessage, 502);
        }

        if (! $response->successful()) {
            Log::warning('Gamma API error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);
            throw new GammaException($failureMessage, 502);
        }

        return (array) $response->json();
    }
}
