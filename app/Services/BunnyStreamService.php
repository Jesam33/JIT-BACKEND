<?php

namespace App\Services;

use App\Exceptions\BunnyStreamException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for Bunny Stream (video.bunnycdn.com) — the external host for
 * pre-recorded lesson videos and video materials. This is the SOLE place the
 * Bunny wire contract lives, so any future API drift is a one-file change.
 *
 * The whole point of Bunny is that the video BYTES never touch our server or
 * DB: the browser uploads directly to Bunny over a signed TUS session, and we
 * persist only the video guid, a thumbnail URL and the player embed URL. So this
 * service does two small server-side jobs — (1) create the video record to get a
 * guid, and (2) mint a short-lived TUS upload signature — plus a status poll and
 * a few URL builders. The large transfer is the browser's, not ours.
 *
 * Contract (Bunny Stream API + TUS, verified against bunny.net/docs):
 *  - Management host is https://video.bunnycdn.com; auth header `AccessKey: {key}`.
 *  - create → POST /library/{lib}/videos {title, collectionId?} → {guid}.
 *  - status → GET  /library/{lib}/videos/{guid} → {status:int, encodeProgress,
 *             length, width, height, thumbnailFileName}. status: 0 Created,
 *             1 Uploaded, 2 Processing, 3 Transcoding, 4 Finished, 5 Error,
 *             6 UploadFailed(→ playable), etc.
 *  - TUS   → endpoint https://video.bunnycdn.com/tusupload; the browser presents
 *             a presigned signature = SHA256(library_id + api_key + expire + guid)
 *             plus the expire epoch, library id and guid as headers. We compute
 *             that signature here (the api_key never leaves the server).
 *  - embed → https://player.mediadelivery.net/embed/{lib}/{guid} (HLS; must be an
 *             <iframe>, NOT an <video> tag).
 *  - CDN   → https://{cdn_hostname}/{guid}/thumbnail.jpg and …/playlist.m3u8.
 *
 * Credentials come from config('services.bunny_stream.*') — NEVER env() at the
 * call site — so they still resolve after `php artisan config:cache` on live.
 *
 * Failure policy (see BunnyStreamException): unset library/key → 503 ("not
 * configured"); every other upstream failure is logged and re-thrown as a
 * generic 502 — never 402, so a Bunny outage can't trip the owner's plan
 * UpgradeModal (the pre-recorded-video plan gate runs separately, before Bunny).
 */
class BunnyStreamService
{
    private const MANAGEMENT_BASE = 'https://video.bunnycdn.com';

    private const TUS_ENDPOINT = 'https://video.bunnycdn.com/tusupload';

    private const PLAYER_BASE = 'https://player.mediadelivery.net';

    private string $libraryId;

    private string $apiKey;

    private string $cdnHostname;

    private string $collectionId;

    public function __construct()
    {
        $this->libraryId = (string) config('services.bunny_stream.library_id', '');
        $this->apiKey = (string) config('services.bunny_stream.api_key', '');
        // Tolerate a full URL or a bare host in cdn_hostname; keep only the host.
        $cdn = trim((string) config('services.bunny_stream.cdn_hostname', ''));
        $this->cdnHostname = $cdn === '' ? '' : (string) (parse_url($cdn, PHP_URL_HOST) ?: rtrim($cdn, '/'));
        $this->collectionId = (string) config('services.bunny_stream.collection_id', '');
    }

    /** Both the library id and its API key must be present to talk to Bunny. */
    public function isConfigured(): bool
    {
        return $this->libraryId !== '' && $this->apiKey !== '';
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw BunnyStreamException::notConfigured();
        }

        $client = Http::withHeaders([
            'AccessKey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->acceptJson()->timeout(30);

        // Mirror Gamma/Paystack: skip TLS verification only in local dev.
        if (app()->environment('local')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Create the Bunny video record and return its guid. Files the video under
     * the configured collection (if any) so the library stays organised. Throws
     * a 502 if Bunny doesn't return a guid.
     */
    public function createVideo(string $title, ?string $collectionId = null): string
    {
        $body = ['title' => $title !== '' ? $title : 'Untitled video'];
        $collection = $collectionId ?? ($this->collectionId !== '' ? $this->collectionId : null);
        if ($collection) {
            $body['collectionId'] = $collection;
        }

        $json = $this->send(
            fn (PendingRequest $c) => $c->post(self::MANAGEMENT_BASE . '/library/' . $this->libraryId . '/videos', $body),
            'The video could not be created. Please try again.'
        );

        $guid = (string) ($json['guid'] ?? '');
        if ($guid === '') {
            Log::warning('Bunny Stream create returned no guid', ['response' => $json]);
            throw new BunnyStreamException('The video could not be created. Please try again.', 502);
        }

        return $guid;
    }

    /**
     * Build the presigned TUS upload envelope the browser needs to push bytes
     * straight to Bunny. The signature is SHA256(library_id + api_key + expire +
     * video_id); the api_key is folded into the hash here and never sent to the
     * client. `expiration_time` is a UNIX epoch at least an hour out (Bunny
     * rejects short-lived signatures).
     *
     * @return array{provider:string,endpoint:string,library_id:string,video_id:string,signature:string,expiration_time:int,collection_id:?string}
     */
    public function signedUpload(string $videoId, int $ttlSeconds = 7200): array
    {
        if (! $this->isConfigured()) {
            throw BunnyStreamException::notConfigured();
        }

        $expire = time() + max(3600, $ttlSeconds);
        $signature = hash('sha256', $this->libraryId . $this->apiKey . $expire . $videoId);

        return [
            'provider' => 'bunny_stream',
            'endpoint' => self::TUS_ENDPOINT,
            'library_id' => $this->libraryId,
            'video_id' => $videoId,
            'signature' => $signature,
            'expiration_time' => $expire,
            'collection_id' => $this->collectionId !== '' ? $this->collectionId : null,
        ];
    }

    /** Poll a video by guid → raw Bunny status payload {status, encodeProgress, …}. */
    public function videoStatus(string $videoId): array
    {
        return $this->send(
            fn (PendingRequest $c) => $c->get(self::MANAGEMENT_BASE . '/library/' . $this->libraryId . '/videos/' . urlencode($videoId)),
            'Could not check the video status. Please try again.'
        );
    }

    /** The player embed URL — always an <iframe> src (Bunny serves HLS, not a file). */
    public function embedUrl(string $videoId): string
    {
        return self::PLAYER_BASE . '/embed/' . $this->libraryId . '/' . $videoId;
    }

    /** The direct-play URL (a full-page player), handy as an "open in new tab" link. */
    public function playUrl(string $videoId): string
    {
        return self::PLAYER_BASE . '/play/' . $this->libraryId . '/' . $videoId;
    }

    /** CDN thumbnail (null when no pull-zone host is configured). */
    public function thumbnailUrl(string $videoId): ?string
    {
        return $this->cdnHostname === '' ? null : 'https://' . $this->cdnHostname . '/' . $videoId . '/thumbnail.jpg';
    }

    /** CDN HLS playlist (null when no pull-zone host is configured). */
    public function hlsUrl(string $videoId): ?string
    {
        return $this->cdnHostname === '' ? null : 'https://' . $this->cdnHostname . '/' . $videoId . '/playlist.m3u8';
    }

    /**
     * Run one Bunny management call, mapping every failure mode to a
     * BunnyStreamException with a clean, non-leaking message. Identical policy to
     * GammaService::send — a notConfigured() 503 passes straight through; a
     * transport error or non-2xx upstream response is logged and surfaced as 502.
     */
    private function send(callable $call, string $failureMessage): array
    {
        try {
            $client = $this->client();
        } catch (BunnyStreamException $e) {
            throw $e;
        }

        try {
            $response = $call($client);
        } catch (\Throwable $e) {
            Log::warning('Bunny Stream transport error', ['error' => $e->getMessage()]);
            throw new BunnyStreamException($failureMessage, 502);
        }

        if (! $response->successful()) {
            Log::warning('Bunny Stream API error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);
            throw new BunnyStreamException($failureMessage, 502);
        }

        return (array) $response->json();
    }
}
