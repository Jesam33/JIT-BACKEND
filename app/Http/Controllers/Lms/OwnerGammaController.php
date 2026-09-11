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
use App\Support\PptxToDocx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pro+ AI training-material generation (Gamma). Owners describe a topic, Gamma
 * generates a document/deck asynchronously, the page polls for the result, and
 * the owner saves the finished export as a real downloadable file into one of
 * their courses or modules (the vendor link is never student-facing).
 *
 * Authorization mirrors OwnerAdminController exactly, the tenant is derived
 * from the owner's OWN session row (never the middleware-bound tenant, since a
 * student/staff token also binds one) and confirmed against tenant_admins; once
 * confirmed we (re)bind that tenant so every TenantAware read/write below is
 * scoped to this organisation. The paid-feature gate (`ai_materials`, Pro+) runs
 * on every action via PlanGate::ensureFeature → 402 → the owner UpgradeModal.
 *
 * All Gamma wire specifics live in GammaService (the sole adapter); this
 * controller only maps request → validated Gamma body and Gamma result →
 * persisted LmsMaterial / LmsModuleContent.
 *
 * StaffGammaController extends this class: it overrides resolveContext() to
 * authenticate a STAFF session instead, and the two authorize*Target() hooks
 * below to restrict the save/browse targets to the teacher's assigned courses.
 * Keep every method's context resolution + PlanGate gate going through
 * resolveContext()/the hooks so the staff variant stays a pure override.
 */
class OwnerGammaController extends BaseLmsController
{
    public function __construct(private readonly GammaService $gamma)
    {
    }

    /**
     * Resolve the authenticated owner + tenant from the bearer session and bind
     * the tenant. Returns [Tenant, User] or null when the caller is not an owner
     * of a tenant. (Copy of OwnerAdminController::ownerContext, kept local so
     * the two owner controllers don't couple through a shared parent method.)
     */
    protected function resolveContext(Request $request): ?array
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
     * Ownership guards for the save/browse targets. The owner variant allows
     * ANY course of the bound tenant (the TenantAware scope already 404s
     * foreign-tenant ids); StaffGammaController overrides both to require the
     * courses the teacher is actually assigned to (via their cohorts).
     */
    protected function authorizeCourseTarget(LmsCourse $course): void
    {
    }

    protected function authorizeModuleTarget(LmsModule $module): void
    {
    }

    /**
     * Start an async generation. Validates the prompt + options, maps them to
     * Gamma's camelCase body (GammaService owns the wire contract), and returns
     * the generation id the page then polls. PlanGate gates it to Pro+ (402).
     */
    public function generate(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
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
            // Optional downloadable export. When the owner doesn't pick a format
            // we still request one (default PDF, below) so save() can always store
            // a real downloadable file, the editable gammaUrl is returned too.
            // 'docx' isn't a Gamma format: we request the pptx master and
            // convert it server-side on save/download (see PptxToDocx).
            'export_as' => ['nullable', Rule::in(['pdf', 'pptx', 'docx'])],
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

        // Always request an export so save() can store a real downloadable file;
        // default to PDF when the owner didn't choose a format. The editable
        // gammaUrl is returned by status() regardless of the export. A docx
        // choice is served from the pptx export (the editable master we
        // convert); Gamma itself has no Word format.
        $body['exportAs'] = ($data['export_as'] ?? 'pdf') === 'docx' ? 'pptx' : ($data['export_as'] ?? 'pdf');

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
     * export URL (if the owner requested one, expires ~1 week, so it's for an
     * immediate download only, never persisted), and any failure message.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
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
     * Persist a finished generation into the owner's LMS. BOTH targets store a
     * real downloadable file: the ephemeral export URL (Gamma expires it ~1
     * week out) is fetched now and OUR copy is what students open, so no
     * student-facing surface ever points at the vendor. The durable editable
     * Gamma link is preserved in provider/external_id — no portal UI renders
     * those for non-video contents, so it's recoverable without being shown.
     * Course-level → LmsMaterial; module-level → LmsModuleContent appended
     * after the module's existing content. Exactly one target is required; the
     * course/module is resolved through the TenantAware scope, so a foreign id
     * 404s instead of leaking across tenants.
     */
    public function save(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            // The durable Gamma doc URL (gammaUrl from a completed generation).
            'url' => ['required', 'url', 'max:2048'],
            // Optional downloadable export produced by the generation. When present
            // we fetch the bytes and store a real file as the material (both the
            // module and the course target); the editable gammaUrl above is kept
            // alongside it in provider/external_id.
            'export_url' => ['nullable', 'url', 'max:2048'],
            'format' => ['nullable', Rule::in(['pdf', 'pptx', 'docx'])],
            'course_id' => ['nullable', 'integer', 'required_without:module_id'],
            'module_id' => ['nullable', 'integer', 'required_without:course_id'],
        ]);

        if (! empty($data['course_id']) && ! empty($data['module_id'])) {
            return response()->json([
                'message' => 'Choose a single destination, either a course or a module, not both.',
            ], 422);
        }

        if (! empty($data['module_id'])) {
            // Tenant-scoped: a module from another institute 404s here.
            $module = LmsModule::query()->findOrFail((int) $data['module_id']);
            $this->authorizeModuleTarget($module);

            // Fetch the export bytes now and store OUR copy as the module
            // material: content_url points at the stored file so the student's
            // "Open pdf/slides" downloads the real thing, and file_path records
            // it for cleanup on delete (mirrors the staff upload flow). We never
            // fall back to saving the gamma.site link itself — students must not
            // see the vendor — so a failed download fails the save with a retry
            // message instead of quietly storing a vendor URL.
            $format = $data['format'] ?? 'pdf';
            $stored = ! empty($data['export_url'])
                ? $this->storeExportFile($data['export_url'], $format)
                : null;
            if (! $stored) {
                return response()->json([
                    'message' => 'The downloadable copy could not be fetched, so nothing was saved. Please try saving again.',
                    'error' => 'export_unavailable',
                ], 502);
            }

            $nextOrder = (int) (LmsModuleContent::query()
                ->where('module_id', $module->id)
                ->max('sort_order') ?? 0) + 1;

            $content = LmsModuleContent::create([
                'module_id' => $module->id,
                'title' => $data['title'],
                'type' => $format === 'pptx' ? 'slides' : ($format === 'docx' ? 'doc' : 'pdf'),
                'content_url' => $this->publicFileUrl($stored),
                'file_path' => $stored,
                // Vendor pointers, kept for recovery; never rendered by any UI.
                'provider' => 'gamma',
                'external_id' => $data['url'],
                'sort_order' => $nextOrder,
            ]);

            return response()->json([
                'saved' => true,
                'target' => 'module',
                'id' => $content->id,
                'type' => $content->type,
            ], 201);
        }

        // Tenant-scoped: a course from another institute 404s here.
        $course = LmsCourse::query()->findOrFail((int) $data['course_id']);
        $this->authorizeCourseTarget($course);

        // Same contract as the module target: fetch the export bytes and store
        // OUR copy as the course material. file_url is always our /storage file
        // (never the gamma.site link students used to be handed), file_path
        // records it so deleteMaterial cleans the file up, and the editable
        // vendor link is preserved in provider/external_id where no portal UI
        // renders it.
        $format = $data['format'] ?? 'pdf';
        $stored = ! empty($data['export_url'])
            ? $this->storeExportFile($data['export_url'], $format, 'materials')
            : null;
        if (! $stored) {
            return response()->json([
                'message' => 'The downloadable copy could not be fetched, so nothing was saved. Please try saving again.',
                'error' => 'export_unavailable',
            ], 502);
        }

        $material = LmsMaterial::create([
            'course_id' => $course->id,
            'title' => $data['title'],
            'type' => $format === 'pdf' ? 'pdf' : 'doc',
            'file_url' => $this->publicFileUrl($stored),
            'file_path' => $stored,
            // Vendor pointers, kept for recovery; never rendered by any UI.
            'provider' => 'gamma',
            'external_id' => $data['url'],
        ]);

        return response()->json([
            'saved' => true,
            'target' => 'course',
            'id' => $material->id,
            'type' => $material->type,
        ], 201);
    }

    /**
     * List a course's modules ({id,title}) to populate the AI-materials module
     * picker. Tenant-scoped: a course id from another institute 404s. Same
     * owner-context + Pro-feature gate as the rest of this controller.
     */
    public function courseModules(Request $request, int $course): JsonResponse
    {
        $context = $this->resolveContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        // Tenant-scoped: a foreign course 404s instead of leaking its modules.
        $courseModel = LmsCourse::query()->findOrFail($course);
        $this->authorizeCourseTarget($courseModel);

        $modules = LmsModule::query()
            ->where('course_id', $courseModel->id)
            ->orderBy('sort_order')
            ->get(['id', 'title']);

        return response()->json(['modules' => $modules]);
    }

    /**
     * Download a Gamma export (PDF/PPTX), optionally convert it, and store OUR
     * copy on the public disk. Returns the stored relative path, or null on any
     * failure. The export URL is ephemeral (Gamma expires it ~1 week out), so
     * we fetch the bytes now and serve our own file.
     *
     * $format picks what gets stored: 'pdf' and 'pptx' are stored as-is;
     * 'docx' converts the pptx export through PptxToDocx (Gamma has no Word
     * format — the pptx IS the editable master we translate from). $dir picks
     * the storage folder: 'module-contents/' to match the staff content-upload
     * location for module saves, 'materials/' to match the staff
     * material-upload location for course saves.
     */
    private function storeExportFile(string $url, string $format, string $dir = 'module-contents'): ?string
    {
        $bytes = $this->fetchExportBytes($url);
        if ($bytes === null) {
            return null;
        }

        if ($format === 'docx') {
            $docx = PptxToDocx::convert($bytes);
            if ($docx === null) {
                \Illuminate\Support\Facades\Log::warning('Gamma pptx→docx conversion failed');
                return null;
            }
            $bytes = $docx;
        }

        $ext = ['pdf' => 'pdf', 'pptx' => 'pptx', 'docx' => 'docx'][$format] ?? 'pdf';
        $path = $dir . '/' . Str::uuid()->toString() . '.' . $ext;
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * Fetch the raw bytes behind an ephemeral export URL, or null on failure.
     */
    private function fetchExportBytes(string $url): ?string
    {
        // Mirrors GammaService::client(): local dev often sits behind a
        // self-signed proxy/cert, production always verifies.
        $buildClient = fn () => app()->environment('local')
            ? Http::timeout(60)->withoutVerifying()
            : Http::timeout(60);

        // Two attempts: the export is generated slightly after the doc flips to
        // "completed", so an immediate fetch can race it. Log the failure — the
        // caller turns null into a retry-later 502 for the user.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $buildClient()->get($url);
                if ($response->successful() && $response->body() !== '') {
                    return $response->body();
                }

                \Illuminate\Support\Facades\Log::warning('Gamma export download failed', [
                    'status' => $response->status(),
                    'attempt' => $attempt,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Gamma export download error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
            }

            if ($attempt === 1) {
                sleep(3);
            }
        }

        return null;
    }

    /**
     * "Download copy" for a Word (.docx) choice: fetch the pptx export, convert
     * it, and STREAM the docx back as an attachment. Unlike save() nothing is
     * persisted — the button is a one-off download, so no orphan file is left
     * on the disk. Same context resolution + Pro gate as every other action,
     * so the staff variant inherits it unchanged.
     */
    public function downloadDocx(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $context = $this->resolveContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        [$tenant] = $context;
        PlanGate::ensureFeature($tenant, 'ai_materials');

        $data = $request->validate([
            'export_url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $bytes = $this->fetchExportBytes($data['export_url']);
        if ($bytes === null) {
            return response()->json([
                'message' => 'The downloadable copy could not be fetched. Please try again in a moment.',
                'error' => 'export_unavailable',
            ], 502);
        }

        $docx = PptxToDocx::convert($bytes);
        if ($docx === null) {
            return response()->json([
                'message' => 'The Word copy could not be produced. Please try again.',
                'error' => 'conversion_failed',
            ], 502);
        }

        $filename = trim((string) ($data['title'] ?? '')) !== ''
            ? Str::slug($data['title']) . '.docx'
            : 'ai-materials.docx';

        $tmp = tempnam(sys_get_temp_dir(), 'aidocx');
        if ($tmp === false || file_put_contents($tmp, $docx) === false) {
            @unlink($tmp);
            return response()->json(['message' => 'The Word copy could not be produced. Please try again.'], 502);
        }

        register_shutdown_function(fn () => @unlink($tmp));

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }
}
