<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsTeacher;
use App\Services\BunnyStreamService;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-side endpoints for uploading a pre-recorded video to Bunny Stream.
 *
 * The flow is deliberately split so the video BYTES never pass through our
 * server:
 *   1. POST …/staff/videos/upload  → we plan-gate, create the Bunny video record
 *      (to mint a guid) and return a short-lived signed TUS envelope.
 *   2. The BROWSER uploads the file straight to Bunny using that envelope.
 *   3. GET …/staff/videos/{id}/status → we proxy Bunny's encode status so the UI
 *      can poll until the video is ready, then save the material / module content
 *      with the returned embed + thumbnail URLs.
 *
 * The `pre_recorded_video` plan gate runs in step 1 BEFORE we touch Bunny, so a
 * Free academy is turned away with a 402 and no orphan Bunny video is ever
 * created for it. A Bunny outage surfaces as 503/502 (never 402) via
 * BunnyStreamException, so it can't be mistaken for a plan limit.
 */
class StaffVideoController extends BaseLmsController
{
    public function __construct(private readonly BunnyStreamService $bunny)
    {
    }

    private function teacherOrFail(Request $request): LmsTeacher
    {
        $session = $this->sessionFromRequest($request, 'staff');
        $teacher = $session ? LmsTeacher::find($session->user_id) : null;
        if (! $teacher) {
            abort(401, 'Unauthorized');
        }
        return $teacher;
    }

    /**
     * Create a Bunny video + return a signed TUS envelope for the browser to
     * upload against. Plan-gated on `pre_recorded_video` (Basic+). The response
     * carries everything the client TUS uploader and the eventual save need:
     * the upload endpoint + auth signature, plus the embed/thumbnail/HLS URLs.
     */
    public function createUpload(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->teacherOrFail($request);

        // Gate BEFORE creating anything on Bunny → no orphan video for a Free academy.
        PlanGate::ensureFeature($this->currentTenantOrPrimary(), 'pre_recorded_video');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $videoId = $this->bunny->createVideo($validated['title']);
        $upload = $this->bunny->signedUpload($videoId);

        return response()->json([
            'provider' => 'bunny_stream',
            'video_id' => $videoId,
            'library_id' => $upload['library_id'],
            'upload_endpoint' => $upload['endpoint'],
            'signature' => $upload['signature'],
            'expiration_time' => $upload['expiration_time'],
            'collection_id' => $upload['collection_id'],
            'embed_url' => $this->bunny->embedUrl($videoId),
            'play_url' => $this->bunny->playUrl($videoId),
            'thumbnail_url' => $this->bunny->thumbnailUrl($videoId),
            'hls_url' => $this->bunny->hlsUrl($videoId),
        ], 201);
    }

    /**
     * Proxy Bunny's encode status for a video, normalised for the UI. Bunny's
     * numeric status becomes a simple 'processing' | 'ready' | 'error', with a
     * `ready` boolean the poller can stop on. Ready = Finished (4) or, as a
     * pragmatic fallback, a resolvable playable state (6). Also plan-gated so a
     * downgraded academy can't keep polling.
     */
    public function videoStatus(Request $request, string $videoId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $this->teacherOrFail($request);
        PlanGate::ensureFeature($this->currentTenantOrPrimary(), 'pre_recorded_video');

        $raw = $this->bunny->videoStatus($videoId);

        $code = (int) ($raw['status'] ?? 0);
        $ready = in_array($code, [4, 6], true);
        $failed = $code === 5;
        $status = $ready ? 'ready' : ($failed ? 'error' : 'processing');

        return response()->json([
            'provider' => 'bunny_stream',
            'video_id' => $videoId,
            'status' => $status,
            'status_code' => $code,
            'ready' => $ready,
            'failed' => $failed,
            'progress' => (int) ($raw['encodeProgress'] ?? 0),
            'duration_seconds' => isset($raw['length']) ? (int) $raw['length'] : null,
            'width' => isset($raw['width']) ? (int) $raw['width'] : null,
            'height' => isset($raw['height']) ? (int) $raw['height'] : null,
            'embed_url' => $this->bunny->embedUrl($videoId),
            'play_url' => $this->bunny->playUrl($videoId),
            'thumbnail_url' => $this->bunny->thumbnailUrl($videoId),
            'hls_url' => $this->bunny->hlsUrl($videoId),
        ]);
    }
}
