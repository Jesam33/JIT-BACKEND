<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised by BunnyStreamService when a video upload can't be served. It
 * self-renders (like GammaException / PlanLimitException) so the staff video
 * controller stays thin — no try/catch/response wiring at the call site.
 *
 * The status code is deliberately constrained to 503 or 502 and is NEVER 402:
 *  - 503 — Bunny Stream isn't configured on the platform (an ops matter),
 *    mirroring the Gamma/Jitsi "not configured" degrade.
 *  - 502 — any upstream Bunny failure (bad key, library missing, a transient
 *    5xx). Details are logged server-side; the client sees a generic message so
 *    Bunny internals never leak.
 *
 * Why not 402: our OWN 402 means "upgrade your Jorsas plan" and drives the owner
 * UpgradeModal (see maybeUpgrade()). The pre-recorded-video plan gate is applied
 * SEPARATELY, before we ever touch Bunny — so a Bunny outage can never be
 * mistaken for a plan limit and wrongly prompt an upgrade.
 */
class BunnyStreamException extends \Exception
{
    public function __construct(string $message, public readonly int $statusCode = 502)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self("Video uploads aren't available right now. Please try again later.", 503);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'bunny_stream_unavailable',
        ], $this->statusCode);
    }
}
