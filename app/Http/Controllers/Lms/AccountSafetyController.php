<?php

namespace App\Http\Controllers\Lms;

use App\Mail\AcademyReportMail;
use App\Mail\RightsRequestMail;
use App\Models\AcademyReport;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\RightsRequest;
use App\Models\Tenant;
use App\Support\LoginDeviceTracker;
use App\Support\NotificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * The account holder's safety and privacy controls: complain about an academy,
 * exercise a data right, and manage the devices signed in to the account.
 *
 * One controller for three concerns because they share an audience and a screen
 * (the profile's "Help & privacy" tab) and, more importantly, because two of them
 * are the two halves of one story: an academy cannot erase a student who has paid
 * (see LmsStudent::purgeBlockedReason()), and that student's way out is a rights
 * request to the platform. Splitting them across controllers would put the
 * refusal and its remedy in different files.
 *
 * Devices are here rather than on the auth controllers because they are what a
 * worried account holder reaches for after a "new sign-in" email lands — the
 * alert and the response to it belong together.
 */
class AccountSafetyController extends BaseLmsController
{
    /**
     * Report an academy to Jorsas.
     *
     * Students only, and only about THEIR OWN academy: the tenant is taken from
     * the resolved session, never from the request body, so a student cannot file
     * a report against an academy they have no relationship with.
     *
     * One open report per student per academy. Without that cap this is a spam
     * cannon pointed at the platform's inbox, and a student with a grievance can
     * make their point once, in full, in the details field. A second attempt
     * returns the existing report's id rather than an error the UI has to
     * translate, and the frontend uses it to say "you already reported this".
     */
    public function reportAcademy(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(AcademyReport::CATEGORIES))],
            'details' => ['required', 'string', 'min:20', 'max:4000'],
        ]);

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = LmsStudent::query()->find($session->user_id);

        if (! $student) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // A report concerns the academy the student is enrolled in, which is the
        // session's tenant. Not a request field, by design.
        $tenantId = $session->tenant_id;

        if (! $tenantId) {
            return response()->json([
                'message' => 'We could not tell which academy this report is about. Please sign in again from your academy and retry.',
            ], 422);
        }

        $existing = AcademyReport::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('student_id', $student->id)
            ->where('tenant_id', $tenantId)
            ->open()
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You have already reported this academy. Jorsas Tech is looking into it and will be in touch by email.',
                'already_reported' => true,
                'report' => $this->reportPayload($existing),
            ], 422);
        }

        $report = AcademyReport::query()->create([
            'tenant_id' => $tenantId,
            'student_id' => $student->id,
            'category' => $validated['category'],
            'details' => $validated['details'],
            'status' => 'open',
        ]);

        $this->mailJorsasAboutReport($report, $student);

        return response()->json([
            'message' => 'Thank you. Your report has been sent to Jorsas Tech, who will look into it and reply by email.',
            'report' => $this->reportPayload($report),
        ], 201);
    }

    /**
     * Exercise a data right (access, erasure, portability, …) against Jorsas.
     *
     * A student or staffer asks about their own account; an academy owner asks
     * about their academy. Both land in the same queue because the platform
     * answers both, and the request records the ROLE as well as the id — an
     * owner's request has no tenant to be scoped to, and a student's may outlive
     * the account it names.
     *
     * The email is snapshotted onto the row. That is deliberate: an erasure
     * request must still be answerable after the account is anonymised, and the
     * address on the account is about to stop existing.
     */
    public function rightsRequest(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $this->validateRightsRequest($request);

        [$account, $role] = $this->actor($request);

        if (! $account) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $this->fileRightsRequest(
            $request,
            $validated,
            $role,
            $account->id,
            $account->email,
            null,
        );
    }

    /** The academy owner's version of the same request. */
    public function ownerRightsRequest(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $this->validateRightsRequest($request);

        $session = $this->sessionFromRequest($request, 'owner');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = \App\Models\User::query()->find($session->user_id);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // The owner's request is about their ACADEMY, so it carries the tenant
        // even though the requester is not the tenant. tenant_id is nullable on
        // the table precisely so this case and the platform-level one can share it.
        return $this->fileRightsRequest(
            $request,
            $validated,
            'owner',
            $user->id,
            $user->email,
            $session->tenant_id,
        );
    }

    /** The account's devices, for the profile's Devices card. */
    public function devices(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->anySession($request);

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $current = LoginDeviceTracker::fingerprint($request);

        return response()->json([
            'devices' => LoginDeviceTracker::devices($session->role, $session->user_id)
                ->map(fn ($d) => [
                    'fingerprint' => $d->fingerprint,
                    'label' => $d->device_label,
                    'ip' => $d->ip,
                    'first_seen_at' => $d->first_seen_at,
                    'last_seen_at' => $d->last_seen_at,
                    // Lets the profile mark the row you are reading the page on,
                    // and hide the "sign out" button that would end your own
                    // session mid-click.
                    'is_current' => $d->fingerprint === $current,
                ])
                ->values(),
            'current_fingerprint' => $current,
        ]);
    }

    /**
     * Sign out one device. The current device is refused: signing yourself out
     * here would leave the page you are standing on unable to tell you it worked.
     */
    public function signOutDevice(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:40'],
        ]);

        $session = $this->anySession($request);

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($validated['fingerprint'] === LoginDeviceTracker::fingerprint($request)) {
            return response()->json([
                'message' => 'That is the device you are using now. Use "Sign out everywhere else" or log out instead.',
                'is_current' => true,
            ], 422);
        }

        // Agent sessions are not revocable here (different table, no agent device
        // screen) — see LoginDeviceTracker. Refuse rather than report a success
        // that revoked nothing.
        if (! in_array($session->role, ['student', 'staff', 'owner'], true)) {
            return response()->json(['message' => 'This account cannot manage devices here.'], 403);
        }

        $revoked = LoginDeviceTracker::signOutDevice($session->role, $session->user_id, $validated['fingerprint']);

        return response()->json([
            'message' => $revoked > 0
                ? 'That device has been signed out.'
                : 'That device had no active session. It has been forgotten, so signing in from it will alert you again.',
            'sessions_revoked' => $revoked,
        ]);
    }

    /**
     * Sign out everywhere except here.
     *
     * The device list is kept (see LoginDeviceTracker::signOutAll): this is the
     * panic button someone presses after a suspicious email, and re-alerting them
     * about their own laptop minutes later would be alarming at the worst moment.
     */
    public function signOutEverywhere(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->anySession($request);

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! in_array($session->role, ['student', 'staff', 'owner'], true)) {
            return response()->json(['message' => 'This account cannot manage devices here.'], 403);
        }

        $revoked = LoginDeviceTracker::signOutAll(
            $session->role,
            $session->user_id,
            LoginDeviceTracker::fingerprint($request),
        );

        return response()->json([
            'message' => $revoked > 0
                ? "Signed out of {$revoked} other session" . ($revoked === 1 ? '' : 's') . '.'
                : 'No other sessions were open.',
            'sessions_revoked' => $revoked,
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function validateRightsRequest(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(RightsRequest::TYPES))],
            'details' => ['required', 'string', 'min:20', 'max:4000'],
        ]);
    }

    private function fileRightsRequest(
        Request $request,
        array $validated,
        string $role,
        int $requesterId,
        ?string $email,
        ?int $tenantId,
    ): JsonResponse {
        $rightsRequest = RightsRequest::query()->create([
            'tenant_id' => $tenantId,
            'requester_role' => $role,
            'requester_id' => $requesterId,
            // Snapshot, not a foreign key: an erasure request must still be
            // answerable once the account it names has been anonymised.
            'requester_email' => $email,
            'type' => $validated['type'],
            'details' => $validated['details'],
            'status' => 'open',
        ]);

        $this->mailJorsasAboutRightsRequest($rightsRequest);

        return response()->json([
            'message' => $this->rightsAcknowledgement($validated['type']),
            'request' => [
                'id' => $rightsRequest->id,
                'type' => $rightsRequest->type,
                'type_label' => RightsRequest::TYPES[$rightsRequest->type] ?? $rightsRequest->type,
                'status' => $rightsRequest->status,
                'created_at' => $rightsRequest->created_at,
            ],
        ], 201);
    }

    /**
     * The promise made back to the requester. For erasure it has to say plainly
     * that some records survive — the academy's financial history and the proof
     * that a course was bought — because a "your data will be erased" that later
     * turns out to be partial is worse than saying so up front.
     */
    private function rightsAcknowledgement(string $type): string
    {
        return match ($type) {
            'erasure' => 'Your erasure request has been sent to Jorsas Tech. They will reply by email. Please note that records of payments and enrolments may be retained where they are needed for accounting, tax or to evidence a course that was purchased.',
            'access' => 'Your request for a copy of your data has been sent to Jorsas Tech. They will reply by email.',
            'portability' => 'Your data-portability request has been sent to Jorsas Tech. They will reply by email with your data in a portable format.',
            'rectification' => 'Your correction request has been sent to Jorsas Tech. They will reply by email.',
            'restrict' => 'Your request to restrict processing has been sent to Jorsas Tech. They will reply by email.',
            'object' => 'Your objection has been sent to Jorsas Tech. They will reply by email.',
            default => 'Your request has been sent to Jorsas Tech. They will reply by email.',
        };
    }

    private function mailJorsasAboutReport(AcademyReport $report, LmsStudent $student): void
    {
        $to = $this->platformInbox();

        if (! $to) {
            return;
        }

        try {
            $tenant = Tenant::query()->withoutGlobalScopes()->find($report->tenant_id);

            Mail::to($to)->send(AcademyReportMail::make([
                'academy' => $tenant?->name ?: 'Unknown academy',
                'academy_id' => $report->tenant_id,
                'student' => trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')) ?: ($student->email ?? 'A student'),
                'student_email' => $student->email,
                'category' => $report->category,
                'category_label' => AcademyReport::CATEGORIES[$report->category] ?? $report->category,
                'details' => $report->details,
                'url' => $this->hostReportsUrl(),
            ]));
        } catch (\Throwable $e) {
            // Best effort, and never fatal: a mail misconfiguration must not turn
            // a filed report into a 500 the student reads as "it didn't send".
            report($e);
        }
    }

    private function mailJorsasAboutRightsRequest(RightsRequest $rightsRequest): void
    {
        $to = $this->platformInbox();

        if (! $to) {
            return;
        }

        try {
            $tenant = $rightsRequest->tenant_id
                ? Tenant::query()->withoutGlobalScopes()->find($rightsRequest->tenant_id)
                : null;

            Mail::to($to)->send(RightsRequestMail::make('submitted', [
                'type' => $rightsRequest->type,
                'type_label' => RightsRequest::TYPES[$rightsRequest->type] ?? $rightsRequest->type,
                'role' => $rightsRequest->requester_role,
                'email' => $rightsRequest->requester_email,
                'academy' => $tenant?->name,
                'details' => $rightsRequest->details,
                'url' => $this->hostRightsUrl(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Where platform-facing mail goes.
     *
     * config(), never env(): the app runs under `config:cache` in production,
     * where a bare env() call returns null and the mail silently goes nowhere
     * (this exact trap has already bitten the feature flags and the Paystack key).
     * Falls back to the platform's own from-address so that a missing key means
     * "send it to us" rather than "drop it".
     */
    private function platformInbox(): ?string
    {
        return config('saas.report_email') ?: config('mail.from.address');
    }

    private function hostReportsUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/' . trim((string) config('saas.admin_dir', 'admin'), '/')
            . '/lms/reports';
    }

    private function hostRightsUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/' . trim((string) config('saas.admin_dir', 'admin'), '/')
            . '/lms/rights-requests';
    }

    /**
     * The signed-in account, whichever portal it came from — the devices card is
     * on the student, staff and owner profiles, so all three session roles are
     * accepted here.
     */
    private function anySession(Request $request): ?\App\Models\LmsSession
    {
        foreach (['student', 'staff', 'owner'] as $role) {
            if ($session = $this->sessionFromRequest($request, $role)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Who is asking for their rights. Mirrors AccountLifecycleController::actor —
     * students first, then staff; an owner uses ownerRightsRequest().
     *
     * @return array{0: LmsStudent|LmsTeacher|null, 1: string}
     */
    private function actor(Request $request): array
    {
        if ($session = $this->sessionFromRequest($request, 'student')) {
            return [LmsStudent::query()->find($session->user_id), 'student'];
        }

        if ($session = $this->sessionFromRequest($request, 'staff')) {
            return [LmsTeacher::query()->find($session->user_id), 'staff'];
        }

        return [null, ''];
    }

    private function reportPayload(AcademyReport $report): array
    {
        return [
            'id' => $report->id,
            'category' => $report->category,
            'category_label' => AcademyReport::CATEGORIES[$report->category] ?? $report->category,
            'details' => $report->details,
            'status' => $report->status,
            'created_at' => $report->created_at,
        ];
    }
}
