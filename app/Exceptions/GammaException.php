<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised by GammaService when an AI-material generation can't be served. It
 * self-renders (like PlanLimitException) so the owner controller stays thin —
 * no try/catch/response wiring at the call site.
 *
 * The status code is deliberately constrained to 503 or 502 and is NEVER 402:
 *  - 503 — Gamma isn't configured on the platform (an ops matter), mirroring the
 *    Jitsi "not configured" degrade.
 *  - 502 — any upstream Gamma failure (bad key, no credits, rate-limited, a
 *    transient 5xx). Details are logged server-side; the client sees a generic
 *    message so Gamma internals never leak.
 *
 * Why not 402: our OWN 402 means "upgrade your Jorsas plan" and drives the owner
 * UpgradeModal (see maybeUpgrade()). Gamma's own 402 means "the PLATFORM's Gamma
 * account is out of credits" — nothing the owner can fix by upgrading — so
 * surfacing it as 402 would wrongly prompt them to upgrade. We never pass it on.
 */
class GammaException extends \Exception
{
    public function __construct(string $message, public readonly int $statusCode = 502)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self("AI material generation isn't available right now. Please try again later.", 503);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'gamma_unavailable',
        ], $this->statusCode);
    }
}
