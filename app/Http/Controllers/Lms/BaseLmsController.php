<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Events\ReactionUpdated;
use App\Models\LmsClassroom;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsMessage;
use App\Models\LmsMessageReaction;
use App\Models\LmsPasswordReset;
use App\Models\LmsSession;
use App\Models\LmsTrack;
use App\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class BaseLmsController extends Controller
{
    protected function ensureLmsEnabled(): void
    {
        // Read via config (bound in config/saas.php), NOT env() directly, so the
        // flag still resolves after `php artisan config:cache` on live. Reading
        // env() here returned null under cached config and 404'd every LMS login.
        if (! config('saas.lms_feature_enabled')) {
            throw new NotFoundHttpException();
        }
    }

    protected function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $isSuperUser = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        if (! $isSuperUser) {
            if ($request->expectsJson() || $request->wantsJson()) {
                abort(response()->json(['message' => 'Only super admin can perform this action.'], 403));
            }

            abort(403, 'Only super admin can perform this action.');
        }
    }

    protected function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }

    protected function sessionFromRequest(Request $request, string $role): ?LmsSession
    {
        $token = $this->bearerToken($request);

        if (! $token) {
            return null;
        }

        return LmsSession::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)
            ->where('role', $role)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Bind currentTenant from a model's tenant_id. Used by unauthenticated
     * bootstrap flows (invite/signup/setup) where a token or invite, not a
     * header, is the authoritative proof of which organisation the request
     * belongs to. No-op when the model has no tenant_id (legacy rows).
     */
    protected function bindTenantFromModel($model): void
    {
        $tenantId = $model->tenant_id ?? null;

        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }
    }

    /**
     * Return the currently-bound tenant, or fall back to the primary (JIT)
     * tenant and bind it. Used by public self-service flows (e.g. agent
     * application) that must attribute a new row to an organisation even when
     * no tenant header is present, on the bare primary domain that
     * organisation is JIT. Prevents the TenantAware creating-guard from
     * throwing on the public path once enforcement is on.
     */
    protected function currentTenantOrPrimary()
    {
        if (app()->bound('currentTenant') && app('currentTenant')) {
            return app('currentTenant');
        }

        $primary = \App\Models\Tenant::primary();

        if ($primary) {
            app()->instance('currentTenant', $primary);
        }

        return $primary;
    }

    /**
     * The per-institute identity for an outgoing transactional email
     * (invite / password-reset): sender display name, brand accent colour and
     * reply-to, resolved from the given tenant, else the currently-bound one.
     * Keeps every LmsPasswordResetMail call site branded from a single source,
     * so an academy's emails never fall back to the platform's "Jorsas" red.
     * Callers bind the recipient's tenant (bindTenantFromModel / owner context)
     * before sending, so the no-argument form resolves the right academy.
     *
     * @return array{name: string, color: string, reply_to: ?string}
     */
    protected function mailBranding($tenant = null): array
    {
        if (! $tenant instanceof \App\Models\Tenant) {
            $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        }

        return \App\Models\Tenant::brandMailArray(
            $tenant instanceof \App\Models\Tenant ? $tenant : null
        );
    }

    protected function classroomJoinOpensAt(LmsClassroom $classroom)
    {
        return $classroom->starts_at?->copy()->subMinutes(5);
    }

    protected function canStudentJoinClassroom(LmsClassroom $classroom): bool
    {
        if (! $classroom->starts_at) {
            return false;
        }

        $joinOpensAt = $this->classroomJoinOpensAt($classroom);

        if (! $joinOpensAt || now()->lt($joinOpensAt)) {
            return false;
        }

        return ! $classroom->ends_at || now()->lte($classroom->ends_at);
    }

    protected function ensureStudentTrackContext(int $studentId): array
    {
        $enrollment = LmsEnrollment::query()->where('student_id', $studentId)->first();

        if (! $enrollment) {
            abort(422, 'Student is not enrolled in a track yet.');
        }

        $track = LmsTrack::query()->findOrFail($enrollment->track_id);
        $groupChat = LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);
        $dmThread = LmsDmThread::query()->firstOrCreate([
            'student_id' => $studentId,
            'instructor_id' => $track->instructor_id,
            'track_id' => $track->id,
        ]);

        return [$track, $groupChat, $dmThread];
    }

    protected function findActiveTrackForCourse(?int $courseId): ?LmsTrack
    {
        if (! $courseId) return null;

        // LmsTrack::registrationOpen() is the single source of truth for the
        // registration cutoff: it checks the cohort-level dates (end_date and
        // registration_deadline ?? start_date, inclusive of the whole day) and
        // falls back to the legacy Batch window, so cohort placement and the
        // public registration gate can never disagree.
        return LmsTrack::query()
            ->with('batch')
            ->where('course_id', $courseId)
            ->orderBy('id')
            ->get()
            ->first(fn (LmsTrack $t) => $t->registrationOpen());
    }

    /**
     * Enroll a single student into the active track for a course, if one exists.
     * This is the registration/payment → staff-visibility bridge: a student is
     * only visible to a staffer through an LmsEnrollment on a track that staffer
     * teaches, so a paid student who is never enrolled stays invisible. Idempotent
     * (upsert keyed by student_id, one active track per student, matching the
     * signup/setup path). No-op when the course has no track yet; the owner
     * assigning an instructor later backfills it via syncTrackEnrollments().
     */
    protected function enrollStudentIntoCourseTrack(int $studentId, ?int $courseId): void
    {
        $track = $this->findActiveTrackForCourse($courseId);

        if ($track) {
            LmsEnrollment::query()->updateOrCreate(
                ['student_id' => $studentId],
                ['track_id' => $track->id],
            );
        }
    }

    /**
     * Backfill enrollments when a cohort gains an instructor: every student who
     * chose this track's course but is not already placed in a track for THAT
     * SAME course is (re)enrolled here, so the freshly-assigned instructor
     * immediately sees the course's students. This is the assign-staff →
     * staff-sees-students half of the flow (the counterpart of
     * enrollStudentIntoCourseTrack). Students already correctly placed in a
     * sibling cohort of the same course are left untouched, so multi-cohort
     * placements aren't stolen. Returns how many enrollments were written.
     *
     * Relies on the caller having bound currentTenant (owner context / model),
     * so every query + create here is tenant-scoped and tenant-stamped.
     */
    protected function syncTrackEnrollments(LmsTrack $track): int
    {
        if (! $track->course_id) {
            return 0;
        }

        $studentIds = \App\Models\LmsStudent::query()
            ->where('selected_course_id', $track->course_id)
            ->pluck('id');

        if ($studentIds->isEmpty()) {
            return 0;
        }

        $existing = LmsEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');

        // Course of each track a student is already enrolled in, to detect a
        // student who is already in a cohort of THIS course.
        $trackCourse = LmsTrack::query()
            ->whereIn('id', $existing->pluck('track_id')->filter()->unique()->values())
            ->pluck('course_id', 'id');

        $written = 0;

        foreach ($studentIds as $studentId) {
            $enrollment = $existing->get($studentId);

            if ($enrollment && ($trackCourse[$enrollment->track_id] ?? null) === $track->course_id) {
                // Already placed in a cohort of this same course, leave it.
                continue;
            }

            LmsEnrollment::query()->updateOrCreate(
                ['student_id' => $studentId],
                ['track_id' => $track->id],
            );
            $written++;
        }

        return $written;
    }

    protected function createPasswordResetToken(string $role, string $email): string
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        $tenantId = app()->bound('currentTenant') && app('currentTenant')
            ? app('currentTenant')->id
            : null;

        // Only clear a prior token for THIS institute, a same-email account at
        // another institute keeps its own pending invite/reset. When the tenant
        // is unknown (legacy/primary path) fall back to clearing by role+email.
        LmsPasswordReset::query()
            ->where('role', $role)
            ->where('email', $email)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->delete();

        LmsPasswordReset::query()->create([
            'role' => $role,
            'email' => $email,
            'tenant_id' => $tenantId,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes(30),
        ]);

        return $token;
    }

    protected function isValidResetToken(string $role, string $email, string $token): bool
    {
        return (bool) $this->resolveResetToken($role, $email, $token);
    }

    /**
     * Return the still-valid reset-token row (or null). Unscoped by tenant so a
     * token issued by any institute resolves on the bare domain; the row carries
     * `tenant_id`, letting the caller bind the issuing organisation before
     * touching the account. Mirrors the token the emailed link carries.
     */
    protected function resolveResetToken(string $role, string $email, string $token): ?LmsPasswordReset
    {
        $tokenHash = hash('sha256', $token);

        return LmsPasswordReset::query()
            ->where('role', $role)
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    protected function consumeResetToken(string $role, string $email, string $token): void
    {
        $tokenHash = hash('sha256', $token);

        LmsPasswordReset::query()
            ->where('role', $role)
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    /**
     * Slug of the currently-bound institute, or null when none is bound (legacy /
     * bare-primary path). Emailed links append it as ?tenant={slug} so the
     * recipient's browser pins the right institute through set-password → login,
     * instead of silently falling back to the primary slug and stranding a
     * non-primary account on the wrong portal.
     */
    protected function currentTenantSlug(): ?string
    {
        if (app()->bound('currentTenant') && app('currentTenant')) {
            return app('currentTenant')->slug;
        }

        return null;
    }

    /**
     * Identity of the currently-bound institute for a login/auth JSON response:
     * the client pins the `tenant` cookie from `slug` so every later portal
     * request (branding, me, dashboard), and any inactivity → login redirect, 
     * resolves THIS institute instead of falling back to the primary slug
     * (`jorsas`). `plan` lets the portal shells gate paid-only features (chat)
     * without a second round-trip. Null when no tenant is bound (legacy path).
     */
    protected function currentTenantPayload(): ?array
    {
        if (! (app()->bound('currentTenant') && app('currentTenant'))) {
            return null;
        }

        $tenant = app('currentTenant');

        return [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'plan' => $tenant->plan ?? 'free',
        ];
    }

    /**
     * Relative URL ('/storage/...') for a file on the public disk. In-app file
     * links (materials, module contents, task submissions) MUST stay relative:
     * Storage::url() bakes in APP_URL's host, so a row saved under
     * http://127.0.0.1:8000 404s from every other host that loads the app (a
     * phone testing the portal, the live domain). Relative links are served
     * same-origin by the Next /storage rewrite, which works anywhere the app
     * itself loads. Share-preview images (og:image) are the exception —
     * crawlers reject relative URLs — so covers/logos still use Storage::url().
     */
    protected function publicFileUrl(string $path): string
    {
        return '/storage/' . ltrim($path, '/');
    }

    /**
     * Plan slug for the current request, used by portal `me` endpoints so the
     * shells can gate paid-only features (chat). Prefers the bound tenant
     * (ResolveTenantFromSession binds it from the bearer token); falls back to
     * the session's own tenant_id, then to 'free'. Never throws, a missing
     * plan simply reads as 'free' (the fail-closed default).
     */
    protected function planForSession(?LmsSession $session = null): string
    {
        // Return the RESOLVED plan slug (Tenant::planSlug()), never the raw `plan`
        // column: the primary institute is always the top plan regardless of what's
        // stored (its column reads 'free' but resolves to 'pro'), and an invalid/
        // legacy column value falls back to 'free'. Reading the raw column here
        // wrongly hid chat for the primary institute's own students and staff.
        if (app()->bound('currentTenant') && app('currentTenant')) {
            return app('currentTenant')->planSlug();
        }

        if ($session && $session->tenant_id) {
            $tenant = \App\Models\Tenant::find($session->tenant_id);
            if ($tenant) {
                return $tenant->planSlug();
            }
        }

        return 'free';
    }

    /**
     * Whether the session's plan includes a boolean feature (e.g.
     * ai_materials), resolved with the same bound-tenant-first logic as
     * planForSession(). Lets a portal `me` endpoint publish individual feature
     * gates (the staff sidebar shows "Create with AI" only when the ACADEMY's
     * plan has it) without the frontend hard-coding plan-slug matrices.
     * Fail-closed: a missing tenant/plan reads as false.
     */
    protected function featureForSession(?LmsSession $session, string $feature): bool
    {
        if (app()->bound('currentTenant') && app('currentTenant')) {
            return (bool) app('currentTenant')->planFeature($feature);
        }

        if ($session && $session->tenant_id) {
            $tenant = \App\Models\Tenant::find($session->tenant_id);
            if ($tenant) {
                return (bool) $tenant->planFeature($feature);
            }
        }

        return false;
    }

    /**
     * Tenant descriptor for a portal `me` response so the frontend can re-pin
     * its `tenant` cookie from the AUTHENTICATED session on every load, the
     * same self-heal the owner portal does. Without this the cookie is only set
     * at fresh-login and goes stale (7-day TTL / incognito / bearer-token
     * session recovery), so the inactivity → login redirect falls back to the
     * primary slug (`jorsas`) and re-login runs against the wrong institute
     * ("Invalid login credentials"). Prefers the bound tenant
     * (ResolveTenantFromSession binds it from the bearer token); falls back to
     * the session's own tenant_id; null only when neither is resolvable.
     */
    protected function tenantPayloadForSession(?LmsSession $session = null): ?array
    {
        if (app()->bound('currentTenant') && app('currentTenant')) {
            $tenant = app('currentTenant');

            return [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'plan' => $tenant->plan ?? 'free',
            ];
        }

        if ($session && $session->tenant_id) {
            $tenant = \App\Models\Tenant::find($session->tenant_id);
            if ($tenant) {
                return [
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'plan' => $tenant->plan ?? 'free',
                ];
            }
        }

        return null;
    }

    private function appendTenantParam(string $link): string
    {
        $slug = $this->currentTenantSlug();

        return $slug ? $link . '&tenant=' . urlencode($slug) : $link;
    }

    protected function buildResetLink(string $role, string $email, string $token): string
    {
        $baseUrl = config('saas.frontend_url');
        $path = match ($role) {
            'staff' => '/lms/staff/reset-password',
            'agent' => '/lms/agent/reset-password',
            default => '/lms/reset-password',
        };

        return $this->appendTenantParam(
            $baseUrl . $path . '?email=' . urlencode($email) . '&token=' . urlencode($token)
        );
    }

    /**
     * Link for an INVITE (owner-issued account activation), as opposed to a
     * self-service reset. Lands the invitee on a "set your password" page framed
     * as account activation, staff get a dedicated setup page rather than the
     * login form. Shares the reset token/endpoint underneath.
     */
    protected function buildSetupLink(string $role, string $email, string $token): string
    {
        $baseUrl = config('saas.frontend_url');
        $path = match ($role) {
            'staff' => '/lms/staff/setup-password',
            default => '/lms/reset-password',
        };

        return $this->appendTenantParam(
            $baseUrl . $path . '?email=' . urlencode($email) . '&token=' . urlencode($token)
        );
    }

    /**
     * Link for a COURSE invite, an owner inviting a student straight into a
     * course (Issue C). Unlike the reset/setup links above, which only set a
     * password on an already-provisioned account, this lands on the public
     * signup page: it carries the course and, for a paid invite, launches
     * payment before the account is provisioned. Shares the invite_token stored
     * on the TrainingRegistration.
     */
    protected function buildStudentSignupLink(string $email, string $token): string
    {
        $baseUrl = config('saas.frontend_url');

        return $this->appendTenantParam(
            $baseUrl . '/lms/signup?email=' . urlencode($email) . '&token=' . urlencode($token)
        );
    }

    /**
     * Notify every student in the CURRENT tenant that a new course is available.
     * Both course-create paths (owner panel + JIT super-admin) run in a
     * tenant-bound context, so the LmsStudent query and the LmsNotification
     * writes are all scoped to that one institute. In-app now; emailed by the
     * lms:send-notification-emails sweep.
     */
    protected function notifyStudentsOfNewCourse(\App\Models\LmsCourse $course): void
    {
        $studentIds = \App\Models\LmsStudent::query()->pluck('id');

        foreach ($studentIds as $studentId) {
            \App\Models\LmsNotification::create([
                'student_id' => $studentId,
                'type' => 'new_course',
                'title' => 'New course available: ' . $course->title,
                'body' => 'A new course, "' . $course->title . '", has just been added. Take a look!',
                'reference_type' => 'course',
                'reference_id' => $course->id,
            ]);
        }
    }

    protected function extractPasscodeFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parsed = parse_url($url);

        if ($parsed && ! empty($parsed['query'])) {
            parse_str($parsed['query'], $query);

            if (! empty($query['pwd'])) {
                return $query['pwd'];
            }
        }

        return null;
    }

    protected function maybeFillPasscode(array &$data): void
    {
        if (empty($data['meeting_password']) && ! empty($data['meeting_url'])) {
            $extracted = $this->extractPasscodeFromUrl($data['meeting_url']);

            if ($extracted) {
                $data['meeting_password'] = $extracted;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Live classes: 8x8 JaaS (managed Jitsi) token minting, shared by the
    // student (participant) and staff (moderator) classroom controllers.
    // ---------------------------------------------------------------------

    /**
     * Resolve 8x8 JaaS credentials from config. Returns null when any required
     * value is missing so callers can degrade gracefully (503) instead of
     * minting an unusable token.
     */
    protected function jitsiConfig(): ?array
    {
        $appId = config('services.jitsi.app_id');
        $kid = config('services.jitsi.api_key_id');
        $privateKey = config('services.jitsi.private_key');
        $domain = config('services.jitsi.domain', '8x8.vc');

        if (! $appId || ! $kid || ! $privateKey) {
            return null;
        }

        // The PEM may be stored base64-encoded on a single line to survive dotenv.
        if (! str_contains($privateKey, 'BEGIN')) {
            $decoded = base64_decode($privateKey, true);
            if ($decoded && str_contains($decoded, 'BEGIN')) {
                $privateKey = $decoded;
            }
        }

        return [
            'appId' => $appId,
            'kid' => $kid,
            'privateKey' => $privateKey,
            'domain' => $domain,
        ];
    }

    /**
     * Reuse the (nullable) meeting_id column as the Jitsi room name. If it holds
     * a legacy Zoom numeric id (or is empty), generate a stable, unguessable slug
     * and persist it so the room stays constant across joins and unique per tenant.
     */
    protected function ensureRoom($model, string $type): string
    {
        $existing = (string) ($model->meeting_id ?? '');

        if ($existing !== '' && str_starts_with($existing, 'jit-')) {
            return $existing;
        }

        $hash = substr(sha1(config('app.key') . '|' . $type . '|' . $model->id), 0, 12);
        $prefix = match ($type) {
            'scheduled' => 's',
            'forum' => 'f',
            default => 'c',
        };
        $room = 'jit-' . ($model->tenant_id ?? 0) . '-' . $prefix . $model->id . '-' . $hash;

        $model->meeting_id = $room;
        $model->save();

        return $room;
    }

    /**
     * Mint an RS256 JaaS JWT for a room. `moderator` is emitted as a *string*
     * ("true"/"false") per the JaaS contract; the room claim is "*" so the token
     * is valid for the room the SDK actually joins.
     */
    protected function mintJaasToken(array $cfg, string $room, array $user, bool $isModerator): string
    {
        $now = now()->timestamp;

        $header = [
            'alg' => 'RS256',
            'kid' => $cfg['kid'],
            'typ' => 'JWT',
        ];

        $payload = [
            'aud' => 'jitsi',
            'iss' => 'chat',
            'sub' => $cfg['appId'],
            'room' => '*',
            'iat' => $now,
            'nbf' => $now - 10,
            'exp' => $now + 7200,
            'context' => [
                'user' => [
                    'id' => (string) ($user['id'] ?? ''),
                    'name' => (string) ($user['name'] ?? 'Guest'),
                    'email' => (string) ($user['email'] ?? ''),
                    'avatar' => '',
                    'moderator' => $isModerator ? 'true' : 'false',
                ],
                'features' => [
                    'livestreaming' => 'false',
                    'recording' => $isModerator ? 'true' : 'false',
                    'transcription' => 'false',
                    'outbound-call' => 'false',
                ],
            ],
        ];

        return $this->jwtEncodeRs256($header, $payload, $cfg['privateKey']);
    }

    protected function jwtEncodeRs256(array $header, array $payload, string $privateKeyPem): string
    {
        $segments = [];
        $segments[] = rtrim(strtr(base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $segments[] = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $signingInput = implode('.', $segments);

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Invalid JITSI_PRIVATE_KEY: could not parse RS256 private key.');
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new \RuntimeException('Failed to sign JaaS token.');
        }

        $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return implode('.', $segments);
    }

    // ---------------------------------------------------------------------
    // Chat: reply + reaction helpers (shared by student & staff controllers)
    // ---------------------------------------------------------------------

    /**
     * The fixed reaction palette. Kept in lock-step with the REACTIONS array in
     * the frontend ChatLayout so the API only ever accepts what the UI offers.
     */
    protected function allowedReactionEmojis(): array
    {
        return ['👍', '❤️', '😂', '🎉', '👏', '😮'];
    }

    /**
     * Display name for a message's sender, resolved from whichever relation
     * matches its role. Used both for a message's own sender_name and for the
     * quoted preview of the message it replies to.
     */
    protected function messageSenderName(?LmsMessage $message): ?string
    {
        if (! $message) {
            return null;
        }

        if ($message->sender_role === 'teacher') {
            return $message->teacher?->name ?? 'Instructor';
        }

        $student = $message->student;

        if (! $student) {
            return 'Student';
        }

        $full = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));

        return $full !== '' ? $full : ($student->name ?? 'Student');
    }

    /**
     * Validate a reply target: return the parent id only when it exists, lives
     * in the SAME chat, and isn't deleted, otherwise null. Stops a client from
     * threading a reply onto a message in another track/DM.
     */
    protected function resolveReplyToId($replyToId, string $chatType, int $chatId): ?int
    {
        if (! $replyToId) {
            return null;
        }

        $parent = LmsMessage::query()
            ->where('id', $replyToId)
            ->where('chat_type', $chatType)
            ->where('chat_id', $chatId)
            ->whereNull('deleted_at')
            ->first();

        return $parent?->id;
    }

    /**
     * Quoted-reply descriptor for a message, or null when it isn't a reply.
     * Requires the `replyTo` (and its `teacher`/`student`) relation to be
     * eager-loaded by the caller. A reply to a since-deleted message still
     * renders, showing a "Message deleted" placeholder.
     */
    protected function replyToPayload(?LmsMessage $message): ?array
    {
        $parent = $message?->replyTo;

        if (! $parent) {
            return null;
        }

        $content = $parent->deleted_at
            ? 'Message deleted'
            : ($parent->content ?? $parent->body ?? '');

        return [
            'id' => $parent->id,
            'content' => $content,
            'sender_role' => $parent->sender_role,
            'sender_name' => $this->messageSenderName($parent),
        ];
    }

    /**
     * Aggregate a message's reactions into [{emoji, count, mine}], ordered by
     * the canonical palette so chips stay in a stable position for every
     * viewer. `mine` is true for the emoji the given viewer has reacted with.
     * Requires the `reactions` relation to be eager-loaded (falls back to a
     * query if not).
     */
    protected function reactionsPayload(?LmsMessage $message, string $viewerRole, int $viewerId): array
    {
        if (! $message) {
            return [];
        }

        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->get();

        $grouped = [];

        foreach ($reactions as $reaction) {
            $emoji = $reaction->emoji;

            if (! isset($grouped[$emoji])) {
                $grouped[$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false];
            }

            $grouped[$emoji]['count']++;

            if ($reaction->user_role === $viewerRole && (int) $reaction->user_id === $viewerId) {
                $grouped[$emoji]['mine'] = true;
            }
        }

        $result = array_values($grouped);
        $order = array_flip($this->allowedReactionEmojis());
        usort($result, fn ($a, $b) => ($order[$a['emoji']] ?? 99) <=> ($order[$b['emoji']] ?? 99));

        return $result;
    }

    /**
     * Toggle one reactor's emoji on a message (add if absent, remove if
     * present), then broadcast the fresh aggregate to everyone on the chat's
     * channel and return the viewer-specific aggregate for the HTTP response.
     *
     * The broadcast intentionally carries counts only, a reactor's own `mine`
     * state is never changed by someone else's toggle, so each receiving client
     * keeps its own `mine` flags and only updates counts. The HTTP caller gets
     * the fully-resolved list (with `mine`) for its optimistic reconcile.
     */
    protected function toggleMessageReaction(LmsMessage $message, string $viewerRole, int $viewerId, string $emoji): array
    {
        $existing = LmsMessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_role', $viewerRole)
            ->where('user_id', $viewerId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            LmsMessageReaction::query()->create([
                'message_id' => $message->id,
                'user_role' => $viewerRole,
                'user_id' => $viewerId,
                'emoji' => $emoji,
            ]);
        }

        $message->load('reactions');
        $viewerPayload = $this->reactionsPayload($message, $viewerRole, $viewerId);

        $broadcastPayload = array_map(
            fn ($r) => ['emoji' => $r['emoji'], 'count' => $r['count']],
            $viewerPayload,
        );

        try {
            ReactionUpdated::dispatch(
                $message->chat_type,
                (int) $message->chat_id,
                (int) $message->id,
                $broadcastPayload,
            );
        } catch (\Throwable) {
            // Broadcasting is best-effort; the HTTP response still carries truth.
        }

        return $viewerPayload;
    }

    protected function adminShellData(): array
    {
        return [
            'adminDir' => env('ADMIN_DIR', 'admin'),
            'pendingRegistrations' => \App\Models\TrainingRegistration::query()->where('status', 'pending')->count(),
            'studentCount' => \App\Models\LmsStudent::query()->count(),
            'tracks' => LmsTrack::query()->count(),
            'courses' => \App\Models\LmsCourse::query()->count(),
            'teachers' => \App\Models\LmsTeacher::query()->count(),
            'classrooms' => LmsClassroom::query()->count(),
        ];
    }
}
