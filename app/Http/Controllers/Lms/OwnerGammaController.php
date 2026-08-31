<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsModule;
use App\Models\LmsModuleContent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GammaService;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pro+ AI training-material generation (Gamma). Owners describe a topic, Gamma
 * generates a document/deck asynchronously, the page polls for the result, and
 * the owner saves the finished Gamma link into one of their courses or modules.
 *
 * Authorization mirrors OwnerAdminController exactly — the tenant is derived
 * from the owner's OWN session row (never the middleware-bound tenant, since a
 * student/staff token also binds one) and confirmed against tenant_admins; once
 * confirmed we (re)bind that tenant so every TenantAware read/write below is
 * scoped to this organisation. The paid-feature gate (`ai_materials`, Pro+) runs
 * on every action via PlanGate::ensureFeature → 402 → the owner UpgradeModal.
 *
 * All Gamma wire specifics live in GammaService (the sole adapter); this
 * controller only maps request → validated Gamma body and Gamma result →
 * persisted LmsMaterial / LmsModuleContent.
 */
class OwnerGammaController extends BaseLmsController
{
    public function __construct(private readonly GammaService $gamma)
    {
    }

    /**
     * Resolve the authenticated owner + tenant from the bearer session and bind
     * the tenant. Returns [Tenant, User] or null when the caller is not an owner
     * of a tenant. (Copy of OwnerAdminController::ownerContext — kept local so
     * the two owner controllers don't couple through a shared parent method.)
     */
    protected function ownerContext(Request $request): ?array
    {
        $session = $this->sessionFromRequest($request, 'owner');
        if (! $session) {
            return null;
        }

        $tenantId = $session->tenant_id
            ?? (app()->bound('currentTenant') && app('currentTenant') ? app('currentTenant')->id : null);
        if (! $tenantId) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        $isAdmin = DB::table('tenant_admins')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $session->user_id)
            ->exists();
        if (! $isAdmin) {
            return null;
        }

        app()->instance('currentTenant', $tenant);

        return [$tenant, User::find($session->user_id)];
    }

    /**
     * Start an async generation. Validates the prompt + options, maps them to
     * Gamma's camelCase body (GammaService owns the wire contract), and returns
     * the generation id the page then polls. PlanGate gates it to Pro+ (402).
     */
    public function generate(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        $data = $request->validate([
            // The topic/brief. Gamma expands it into full content (textMode:generate).
            'prompt' => ['required', 'string', 'min:3', 'max:8000'],
            'format' => ['nullable', Rule::in(['document', 'presentation', 'social', 'webpage'])],
            'num_cards' => ['nullable', 'integer', 'min:1', 'max:60'],
            'additional_instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'max:120'],
            // Optional downloadable export. Omitted → Gamma returns only the
            // editable gammaUrl (which is what we persist; see save()).
            'export_as' => ['nullable', Rule::in(['pdf', 'pptx'])],
        ]);

        $body = [
            'inputText' => $data['prompt'],
            'format' => $data['format'] ?? 'presentation',
            // Expand the short brief into real content (vs. condense/preserve).
            'textMode' => 'generate',
        ];

        if (! empty($data['num_cards'])) {
            $body['numCards'] = (int) $data['num_cards'];
        }
        if (! empty($data['additional_instructions'])) {
            $body['additionalInstructions'] = $data['additional_instructions'];
        }

        $textOptions = [];
        if (! empty($data['tone'])) {
            $textOptions['tone'] = $data['tone'];
        }
        if (! empty($data['audience'])) {
            $textOptions['audience'] = $data['audience'];
        }
        if ($textOptions) {
            $body['textOptions'] = $textOptions;
        }

        if (! empty($data['export_as'])) {
            $body['exportAs'] = $data['export_as'];
        }

        // GammaService self-renders 503 (unset key) / 502 (upstream failure).
        $result = $this->gamma->generate($body);

        $generationId = $result['generationId'] ?? null;
        if (! $generationId) {
            return response()->json([
                'message' => 'AI generation could not be started. Please try again.',
                'error' => 'gamma_unavailable',
            ], 502);
        }

        return response()->json(['generation_id' => (string) $generationId]);
    }

    /**
     * Poll a generation. Returns a normalised, non-leaking envelope: the status
     * (pending|completed|failed), the editable gammaUrl when done, the ephemeral
     * export URL (if the owner requested one — expires ~1 week, so it's for an
     * immediate download only, never persisted), and any failure message.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        $id = trim($id);
        if ($id === '' || strlen($id) > 200) {
            return response()->json(['message' => 'Invalid generation reference.'], 422);
        }

        $result = $this->gamma->status($id);

        $status = strtolower((string) ($result['status'] ?? 'pending'));

        return response()->json([
            'status' => $status,
            'url' => $result['gammaUrl'] ?? null,
            'export_url' => $result['exportUrl'] ?? null,
            'credits' => $result['credits'] ?? null,
            'error' => data_get($result, 'error.message'),
        ]);
    }

    /**
     * Persist a finished generation into the owner's LMS as the durable Gamma
     * link (never the export URL — that expires in ~1 week). Course-level →
     * LmsMaterial(type:link); module-level → LmsModuleContent(type:link) appended
     * after the module's existing content. Exactly one target is required; the
     * course/module is resolved through the TenantAware scope, so a foreign id
     * 404s instead of leaking across tenants.
     */
    public function save(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            // The durable Gamma doc URL (gammaUrl from a completed generation).
            'url' => ['required', 'url', 'max:2048'],
            'course_id' => ['nullable', 'integer', 'required_without:module_id'],
            'module_id' => ['nullable', 'integer', 'required_without:course_id'],
        ]);

        if (! empty($data['course_id']) && ! empty($data['module_id'])) {
            return response()->json([
                'message' => 'Choose a single destination — either a course or a module, not both.',
            ], 422);
        }

        if (! empty($data['module_id'])) {
            // Tenant-scoped: a module from another institute 404s here.
            $module = LmsModule::query()->findOrFail((int) $data['module_id']);

            $nextOrder = (int) (LmsModuleContent::query()
                ->where('module_id', $module->id)
                ->max('sort_order') ?? 0) + 1;

            $content = LmsModuleContent::create([
                'module_id' => $module->id,
                'title' => $data['title'],
                'type' => 'link',
                'content_url' => $data['url'],
                'sort_order' => $nextOrder,
            ]);

            return response()->json([
                'saved' => true,
                'target' => 'module',
                'id' => $content->id,
            ], 201);
        }

        // Tenant-scoped: a course from another institute 404s here.
        $course = LmsCourse::query()->findOrFail((int) $data['course_id']);

        $material = LmsMaterial::create([
            'course_id' => $course->id,
            'title' => $data['title'],
            'type' => 'link',
            'file_url' => $data['url'],
        ]);

        return response()->json([
            'saved' => true,
            'target' => 'course',
            'id' => $material->id,
        ], 201);
    }
}
