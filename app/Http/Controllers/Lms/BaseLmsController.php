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
use App\Models\LmsModule;
use App\Models\LmsPasswordReset;
use App\Models\LmsSession;
use App\Models\LmsTrack;
use App\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        // ResolveTenantFromSession already found this request's session (and
        // EnsureAccountActive inspected it), so reuse that row instead of running
        // the identical lookup again on every authenticated call.
        //
        // The token is re-checked against the request rather than trusted: the
        // binding lives in the container, which outlives a single request under a
        // long-running worker, so a binding set by an earlier request must never
        // be able to authenticate a later one. Both the role filter and the token
        // have to match, exactly as the query below would require.
        if (app()->bound('lmsResolvedSession') && $token) {
            $resolved = app('lmsResolvedSession');

            if ($resolved instanceof LmsSession
                && $resolved->role === $role
                && hash_equals((string) $resolved->token, $token)) {
                return $resolved;
            }
        }

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
     * Tell a person their account was deactivated, restored, deleted or
     * un-deleted. Shared by the self-service endpoints (the person acting on
     * themselves) and the owner's (an academy acting on a student or staffer), so
     * one event never reads two different ways depending on who triggered it.
     *
     * Failures are swallowed on purpose: mail is synchronous here, so a dead SMTP
     * host would otherwise turn "remove this student" into a 500 and leave the
     * owner unsure whether it took effect. The state change is the operation; the
     * email is a courtesy.
     */
    protected function notifyAccountLifecycle($account, string $role, string $kind): void
    {
        if (trim((string) $account->email) === '') {
            return;
        }

        $tenant = $account->tenant_id ? \App\Models\Tenant::find($account->tenant_id) : null;

        try {
            \Illuminate\Support\Facades\Mail::to($account->email)->send(
                \App\Mail\AccountLifecycleMail::make($kind, [
                    'name' => $role === 'student'
                        ? trim($account->first_name . ' ' . $account->last_name)
                        : $account->name,
                    'academy' => $tenant?->name ?: 'your academy',
                    'tenant_id' => $account->tenant_id,
                    'purge_after' => $account->purge_after
                        ? \Illuminate\Support\Carbon::parse($account->purge_after)->format('j F Y')
                        : null,
                    'url' => \App\Support\NotificationLinks::profileUrl($role, $tenant?->slug),
                ])
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Staff-portal actor (staff OR academy owner)
    |--------------------------------------------------------------------------
    | The owner portal gives an academy owner every staff feature ("full parity").
    | Rather than branch every staff endpoint, ONE resolver decides who is acting:
    |
    |   - a staff session            -> that staffer's own LmsTeacher row
    |   - an owner session            -> the academy's owner-mirror LmsTeacher row
    |                                    (LmsTeacher::ownerMirror, auto-provisioned)
    |
    | The mirror is a REAL row because the staff portal keys on a teacher id
    | everywhere (cohort instructor, scheduled-class teacher, chat membership), so
    | a virtual actor could not host a class or open a chat. It is flagged
    | is_academy_owner, and that flag is the only difference that matters to the
    | endpoints: scope. A staffer sees their assigned courses; the owner sees the
    | WHOLE academy. Every staff controller must therefore take its course/track
    | scope from actorCourseIds()/actorTrackIds() below, never from a raw
    | `where('instructor_id', $teacher->id)`.
    */

    /**
     * The owner (a `users` row) acting through the owner portal, or null when the
     * bearer token is a staff session. Mirrors OwnerAdminController::ownerContext's
     * authorization: the session must be role 'owner' AND belong to a tenant_admins
     * row of the session's tenant, so a token alone can't claim owner powers.
     */
    protected function ownerFromRequest(Request $request): ?\App\Models\User
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

        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        $isAdmin = \Illuminate\Support\Facades\DB::table('tenant_admins')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $session->user_id)
            ->exists();

        if (! $isAdmin) {
            return null;
        }

        app()->instance('currentTenant', $tenant);

        return \App\Models\User::find($session->user_id);
    }

    /**
     * The acting teacher for a staff-portal endpoint: a staff session's own row,
     * else the owner's academy-wide mirror. Null when neither token is valid, so
     * callers keep their existing "Unauthorized" branch unchanged.
     */
    protected function staffActor(Request $request): ?\App\Models\LmsTeacher
    {
        $session = $this->sessionFromRequest($request, 'staff');

        if ($session) {
            return \App\Models\LmsTeacher::query()->find($session->user_id);
        }

        if (! $this->ownerFromRequest($request)) {
            return null;
        }

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        return \App\Models\LmsTeacher::ownerMirror($tenant);
    }

    /** Whether this actor is an academy owner's mirror, i.e. scoped academy-wide. */
    protected function actorIsOwner(?\App\Models\LmsTeacher $actor): bool
    {
        return (bool) ($actor && $actor->isAcademyOwner());
    }

    /**
     * Course ids this actor may work on: every active course in the academy for an
     * owner, else the courses of the cohorts they instruct. Mirrors the two
     * hand-rolled scope helpers it replaces (StaffPortalController::getCourseIds,
     * StaffModuleController::assignedCourseIds) so reads and writes agree.
     */
    protected function actorCourseIds(?\App\Models\LmsTeacher $actor): array
    {
        if (! $actor) {
            return [];
        }

        if ($actor->isAcademyOwner()) {
            // TenantScope already narrows this to the owner's academy.
            return \App\Models\LmsCourse::query()->pluck('id')->all();
        }

        return \App\Models\LmsTrack::query()
            ->where('instructor_id', $actor->id)
            ->pluck('course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Cohort ids this actor may work on: all of the academy's for an owner. */
    protected function actorTrackIds(?\App\Models\LmsTeacher $actor): array
    {
        if (! $actor) {
            return [];
        }

        if ($actor->isAcademyOwner()) {
            return \App\Models\LmsTrack::query()->pluck('id')->all();
        }

        return \App\Models\LmsTrack::query()
            ->where('instructor_id', $actor->id)
            ->pluck('id')
            ->all();
    }

    /**
     * Legacy course classrooms this actor may work on. Owned via
     * `lms_classrooms.teacher_id` (the module-based scheduled classes below are
     * the other delivery type), so the owner's mirror covers every classroom in
     * the academy — still tenant-scoped by TenantScope.
     */
    protected function actorClassroomIds(?\App\Models\LmsTeacher $actor): array
    {
        if (! $actor) {
            return [];
        }

        $query = LmsClassroom::query();

        if (! $actor->isAcademyOwner()) {
            $query->where('teacher_id', $actor->id);
        }

        return $query->pluck('id')->all();
    }

    /** Module-based scheduled classes this actor may work on (see above). */
    protected function actorScheduledClassIds(?\App\Models\LmsTeacher $actor): array
    {
        if (! $actor) {
            return [];
        }

        $query = \App\Models\LmsScheduledClass::query();

        if (! $actor->isAcademyOwner()) {
            $query->where('teacher_id', $actor->id);
        }

        return $query->pluck('id')->all();
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
     * The cohort a student who is ALREADY committed to a course belongs in.
     *
     * Differs from {@see findActiveTrackForCourse} in exactly one way: it still
     * answers once every cohort's registration window has closed. These are
     * PLACEMENT moments, not signup moments — the owner invited this student by
     * hand, or the student registered (and often paid) while the window was open
     * and is only being set up now. The cutoff exists to stop strangers joining a
     * finished intake, not to strand a student the academy has already accepted:
     * resolving placement through the open-only lookup left that student enrolled
     * in no cohort at all, which the student portal renders as "No course selected
     * yet" with no way out, and which nothing told the owner about. An academy
     * that leaves a cohort's dates blank is unaffected either way (blank dates
     * never close registration).
     *
     * An open cohort still wins; otherwise the newest, i.e. the current intake.
     */
    protected function findTrackForCoursePlacement(?int $courseId): ?LmsTrack
    {
        if (! $courseId) return null;

        $open = $this->findActiveTrackForCourse($courseId);

        if ($open) {
            return $open;
        }

        return LmsTrack::query()
            ->with('batch')
            ->where('course_id', $courseId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The students who belong to any of these courses, for notification audiences.
     *
     * Two sources, unioned and deduped, because neither one alone is the whole
     * roll: `lms_students.selected_course_id` is what the student portal actually
     * keys off (their modules, tasks, materials, timetable), while `lms_enrollments`
     * rows are per-cohort and only appear once a cohort has an instructor to be
     * enrolled onto (see syncTrackEnrollments). An academy whose cohorts are still
     * unassigned, or a student who chose the course but was never placed in a
     * cohort, has no enrollment row at all — notifying from enrollments alone
     * reaches NOBODY there. That is what "I scheduled a class / posted an
     * announcement and the student got nothing" looks like from the owner's side,
     * and it is not rare: it is every academy on the day it onboards.
     *
     * Course-scoped, not cohort-scoped: a student row carries a single
     * selected_course_id and no track id, so on a course running several cohorts an
     * announcement to one also reaches its siblings. Every other notification here
     * already uses this audience (see StaffTaskController), so this stays
     * consistent with what students already receive rather than inventing a
     * narrower rule for some messages and a wider one for others.
     *
     * Both models are TenantAware, so this is only ever one academy's students —
     * callers must have the tenant bound, which staff and owner requests both do.
     *
     * @param  int[]  $courseIds
     * @return int[]
     */
    protected function studentIdsInCourses(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $byCourse = \App\Models\LmsStudent::query()
            ->whereIn('selected_course_id', $courseIds)
            ->pluck('id');

        $byEnrollment = LmsEnrollment::query()
            ->whereHas('track', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->pluck('student_id');

        return $byCourse
            ->merge($byEnrollment)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Enroll a single student into the active track for a course, if one exists.
     * This is the registration/payment → staff-visibility bridge: a student is
     * only visible to a staffer through an LmsEnrollment on a track that staffer
     * teaches, so a paid student who is never enrolled stays invisible. Idempotent
     * (upsert keyed by student_id, one active track per student, matching the
     * signup/setup path). No-op when the course has no track at all; the owner
     * creating a cohort (or assigning its instructor) later backfills it via
     * syncTrackEnrollments().
     */
    protected function enrollStudentIntoCourseTrack(int $studentId, ?int $courseId): void
    {
        $track = $this->findTrackForCoursePlacement($courseId);

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
     * Store a chat attachment (any file a student or staffer picks in the chat
     * composer) on the platform's public disk and return its {url, path}. The
     * endpoint wrappers (Student/StaffChatController::uploadAttachment) do the
     * auth; this is just the shared storage step.
     *
     * The mimes list is deliberately broad — "attach anything" — but keeps
     * executable-ish types (php/html/svg/js) off the same-origin storage host,
     * exactly like StaffPortalController::uploadMaterialFile. Videos are allowed
     * here (unlike materials) because chat clips are expected to be small; the
     * 25MB cap is the guard, large training videos belong on Bunny Stream.
     */
    protected function storeChatAttachment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:25600',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,csv,txt,rtf,zip,rar,7z,tar,gz,png,jpg,jpeg,webp,gif,bmp,heic,mp3,wav,m4a,aac,ogg,mp4,webm,mov,mkv,avi,3gp,json',
            ],
        ]);

        $path = $validated['file']->store('chat-attachments', 'public');

        return response()->json([
            'url' => $this->publicFileUrl($path),
            'path' => $path,
        ], 201);
    }

    /**
     * One-click module download: a zip of every downloadable file in the module
     * (platform-disk uploads — PDFs, documents, slides, images) plus a README
     * listing everything that can't be zipped: pasted links, text/code bodies
     * and Bunny-hosted videos (the server never holds those bytes, so they stay
     * as watch-online links). Shared by the student and staff download
     * endpoints; callers do their own access checks first.
     */
    protected function moduleZipResponse(LmsModule $module)
    {
        $contents = $module->contents()->orderBy('sort_order')->get();

        $zipPath = tempnam(sys_get_temp_dir(), 'lmsmodule_');
        $zip = new \ZipArchive();
        if (! $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            @unlink($zipPath);
            abort(500, 'Could not create the module archive.');
        }

        $readme = [$module->title, str_repeat('=', strlen($module->title))];
        if ($module->description) {
            $readme[] = $module->description;
        }
        $readme[] = '';

        foreach ($contents as $i => $c) {
            $n = $i + 1;
            $readme[] = sprintf('%d. %s (%s)', $n, $c->title, $c->type);

            if (in_array($c->type, ['text', 'code']) && $c->content_body) {
                // Bodies are inlined into the README — no separate file needed.
                $readme[] = $c->content_body;
            } elseif ($c->file_path && Storage::disk('public')->exists($c->file_path)) {
                $name = sprintf(
                    '%02d-%s.%s',
                    $n,
                    Str::slug($c->title) ?: 'content',
                    pathinfo($c->file_path, PATHINFO_EXTENSION) ?: 'bin'
                );
                $zip->addFromString($name, Storage::disk('public')->get($c->file_path));
                $readme[] = 'Included in this zip: ' . $name;
            } elseif ($c->content_url) {
                $readme[] = ($c->type === 'video' ? 'Video (watch online): ' : 'Link: ') . $c->content_url;
            }
            $readme[] = '';
        }

        $zip->addFromString('README.txt', implode("\n", $readme) . "\n");
        $zip->close();

        // deleteFileAfterSend cleans the temp file once the response streams.
        return response()
            ->download($zipPath, (Str::slug($module->title) ?: 'module') . '.zip')
            ->deleteFileAfterSend(true);
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

    /**
     * A model query as the HOST back office sees it: every academy, not just the
     * primary one.
     *
     * `BindPrimaryTenant` binds Jorsas Tech on every back-office route (see its own
     * docblock — it exists because TenantAware would otherwise fail closed there),
     * and `TenantScope` then quietly narrows each query to Jorsas Tech's own rows.
     * That is wrong for anything the host reads: the back office is above tenancy.
     * Before this helper, the host's Students/Courses/Classrooms/Tracks/Teachers/
     * Agents pages, their row actions, and the sidebar counts in adminShellData()
     * all silently showed Jorsas Tech alone.
     *
     * Deliberately NOT a global bypass. TenantScope fails closed under enforcement
     * (`whereRaw('1 = 0')` with no tenant bound), so "no tenant bound" is not a
     * state the host can rely on. Every host query opts out here instead —
     * explicitly, greppably, and only where a human wrote it.
     */
    protected function hostQuery(string $model): \Illuminate\Database\Eloquent\Builder
    {
        return $model::query()->withoutGlobalScope(TenantScope::class);
    }

    /** Every academy, for the "which academy" columns, filters and create pickers. */
    protected function academies(): \Illuminate\Support\Collection
    {
        return \App\Models\Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** tenant_id => academy name, for labelling a row in a host list. */
    protected function academyNames(): \Illuminate\Support\Collection
    {
        return \App\Models\Tenant::query()->pluck('name', 'id');
    }

    protected function adminShellData(): array
    {
        return [
            // config(), not env(): under config:cache env() returns null outside a
            // config file, which silently collapses every admin URL to /{path}
            // without the admin prefix. The value is already bound in
            // config/saas.php under the same key.
            'adminDir' => config('saas.admin_dir', 'admin'),
            // Platform-wide counts. Every one of these is a HOST number, so every
            // one drops the TenantScope — see hostQuery(). Left scoped, the sidebar
            // disagreed with the page it sat next to.
            'pendingRegistrations' => $this->hostQuery(\App\Models\TrainingRegistration::class)->where('status', 'pending')->count(),
            'studentCount' => $this->hostQuery(\App\Models\LmsStudent::class)->count(),
            'tracks' => $this->hostQuery(LmsTrack::class)->count(),
            'courses' => $this->hostQuery(\App\Models\LmsCourse::class)->count(),
            // staffOnly(): the owner's mirror row is an actor, not a staff member.
            'teachers' => $this->hostQuery(\App\Models\LmsTeacher::class)->staffOnly()->count(),
            'classrooms' => $this->hostQuery(LmsClassroom::class)->count(),
            // The academy list every host list needs, so no page method has to pass
            // it. `academies` for a <select>, `academyNames` for labelling a row.
            'academies' => $this->academies(),
            'academyNames' => $this->academyNames(),
            // Outstanding safety queues, shown as sidebar badges on every host
            // page so a report or rights request cannot sit unnoticed behind a
            // nav link nobody clicked. Scoped to PENDING: a count that included
            // resolved items would only ever grow, which is a badge people learn
            // to ignore. Tenant scope dropped — these are platform-wide queues.
            'openReportCount' => \App\Models\AcademyReport::query()
                ->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->open()
                ->count(),
            'openRightsCount' => \App\Models\RightsRequest::query()
                ->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->open()
                ->count(),
        ];
    }
}
