<?php

namespace App\Http\Controllers\Lms;

use App\Mail\QaInviteMail;
use App\Models\QaEvent;
use App\Models\QaSlot;
use App\Models\QaTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public QA testing pass, plus the moderator surface behind it.
 *
 * This is the only place on the platform where an anonymous visitor can turn a
 * URL into a video-room credential, so it is deliberately narrow and boxed four
 * ways:
 *
 *  1. `ensureQaEnabled()` — the whole feature ships dark behind
 *     `saas.qa_events_enabled` and 404s until it is switched on for an event.
 *  2. The event's own window — registration and joining both refuse outside it,
 *     so the door closes itself on the day.
 *  3. Named rate limiters (`qa-register`, `qa-join`) in AppServiceProvider.
 *  4. Idempotency per (event, email), plus a resend cooldown, so a retry cannot
 *     multiply rows and the endpoint cannot be used to bomb an inbox.
 *
 * Extends BaseLmsController purely to reuse `jitsiConfig()` and `mintJaasToken()`
 * rather than growing a second JaaS implementation that could drift from the one
 * the classrooms use. Nothing here reads an LMS session: a tester has no account.
 */
class QaEventController extends BaseLmsController
{
    /**
     * How long after an invite before the same address can be emailed again. A
     * repeat registration is normally someone who lost the mail, so re-sending is
     * the point; doing it on every request would make this an inbox-spam cannon.
     */
    private const RESEND_COOLDOWN_MINUTES = 2;

    /** 404 unless the feature is switched on. See the class docblock. */
    protected function ensureQaEnabled(): void
    {
        if (! config('saas.qa_events_enabled')) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * The registration page's bootstrap: who the event is, and which slots are
     * still open.
     *
     * A closed-but-existing event answers 200 with `is_open: false` rather than a
     * 404, because the page has something useful to say ("registration has
     * closed") and a bare error page does not. Only an unknown or switched-off
     * event 404s.
     */
    public function show(string $slug): JsonResponse
    {
        $this->ensureQaEnabled();

        $event = QaEvent::query()->where('slug', $slug)->where('is_active', true)->first();

        if (! $event) {
            return response()->json(['message' => 'That testing event does not exist.'], 404);
        }

        return response()->json([
            'event' => $this->eventPayload($event),
            'slots' => $event->isOpen()
                ? $event->slots->map(fn (QaSlot $slot) => $this->slotPayload($slot))->values()->all()
                : [],
        ]);
    }

    /**
     * Which event is running right now, for the signup popup on the marketing site.
     *
     * Takes no slug because the visitor never asked for an event: they landed on
     * jorsastech.com and the popup has to decide for itself whether there is
     * anything to invite them to. So the answer is server-side, and `null` is a
     * perfectly normal reply: most of the time no event is open and the site
     * behaves exactly as it did before this existed.
     *
     * Answers 200 with `event: null` rather than 404 in that case, because a 404
     * would show up in the browser console of every visitor to the marketing site
     * as a failed request, which is noise for a page that is working correctly.
     */
    public function active(): JsonResponse
    {
        // Still 404s when the feature is switched off, so a dark deploy cannot be
        // discovered through this endpoint any more than through the others.
        $this->ensureQaEnabled();

        $event = QaEvent::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (QaEvent $candidate) => $candidate->isOpen());

        if (! $event) {
            return response()->json(['event' => null, 'slots' => []]);
        }

        return response()->json([
            'event' => $this->eventPayload($event),
            'slots' => $event->slots
                ->filter(fn (QaSlot $slot) => $slot->isOpen())
                ->map(fn (QaSlot $slot) => $this->slotPayload($slot))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Register a tester and email their join link.
     *
     * Answers the same way whether or not the address was already on the list, so
     * the endpoint cannot be used to discover who has signed up.
     */
    public function register(Request $request): JsonResponse
    {
        $this->ensureQaEnabled();

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:190'],
            // Required: the tester sheet is handed to the iungo team on the day and
            // the phone number is how a session runner reaches someone whose link
            // failed. It is the one field beyond name and email that earns its place.
            'phone' => ['required', 'string', 'max:40'],
            'slot_id' => ['required', 'integer'],
        ]);

        $event = QaEvent::query()->where('slug', $data['slug'])->where('is_active', true)->first();

        if (! $event) {
            return response()->json(['message' => 'That testing event does not exist.'], 404);
        }

        if (! $event->isOpen()) {
            return response()->json([
                'message' => 'Registration for this testing event has closed.',
                'closed' => true,
            ], 422);
        }

        $slot = QaSlot::query()
            ->where('id', $data['slot_id'])
            ->where('qa_event_id', $event->id)
            ->first();

        if (! $slot) {
            return response()->json([
                'message' => 'Pick a testing slot to continue.',
                'slot_invalid' => true,
            ], 422);
        }

        if (! $slot->isOpen()) {
            return response()->json([
                'message' => 'That slot is no longer open. Please pick another.',
                'slot_invalid' => true,
            ], 422);
        }

        $email = mb_strtolower(trim($data['email']));

        // findOrNew rather than firstOrCreate: an existing tester keeps their
        // TOKEN, so their old link keeps working. Re-minting on every re-register
        // would silently break the link they already have open in another tab.
        $tester = QaTester::query()
            ->where('qa_event_id', $event->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $isNew = $tester === null;
        $tester ??= new QaTester();

        // A host removing a tester is a deliberate act, and re-submitting the same
        // email must not quietly undo it. Checked before anything is written.
        if (! $isNew && $tester->removed_at) {
            return response()->json([
                'message' => 'This testing pass has been cancelled. Contact the team running the session.',
            ], 403);
        }

        // Capacity, checked before any write and only when this registration ADDS
        // a seat to the target room: a new tester, or an existing one switching
        // rooms. Someone re-submitting the form for the room they already hold is
        // not adding anything, so a room that filled up behind them must not block
        // them from re-sending their own link.
        $addsSeat = $isNew || (int) $tester->qa_slot_id !== (int) $slot->id;

        if ($addsSeat && ! $slot->hasRoomFor()) {
            return response()->json([
                'message' => 'That slot is full. Please pick another.',
                'slot_invalid' => true,
            ], 422);
        }

        $tester->fill([
            'tenant_id' => $event->tenant_id,
            'qa_event_id' => $event->id,
            'qa_slot_id' => $slot->id,
            'name' => trim($data['name']),
            'email' => $email,
            'phone' => trim($data['phone']),
        ]);

        if ($isNew) {
            $tester->token = QaTester::mintToken();
            $tester->ip = $request->ip();
            $tester->user_agent = mb_substr((string) $request->userAgent(), 0, 255);
        }

        try {
            $tester->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two submissions for the same address landed at once (a double-click
            // from two tabs). The unique index on (event, email) is what caught it,
            // and the right answer is the row that already exists rather than a 500
            // on the busiest public page of the day. Re-reading also lands the
            // second request on the FIRST one's token, so both callers end up with
            // the same working link.
            $tester = QaTester::query()
                ->where('qa_event_id', $event->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if (! $tester) {
                throw $e;
            }
        }

        // The expiry depends on the event, so it is set after the row exists. Filled
        // when missing OR already past, never when still live: an existing tester
        // keeps the deadline they have, so re-submitting the form cannot walk a link
        // further out. An EXPIRED link is the one case that must be refreshed, or a
        // tester whose link died would be re-sent the same dead link forever and
        // locked out with no way back in. It cannot run away regardless, because
        // tokenLifetimeEnd() is capped at the event's own end plus a day.
        if (! $tester->token_expires_at || $tester->token_expires_at->isPast()) {
            $tester->forceFill(['token_expires_at' => $tester->tokenLifetimeEnd($event)])->save();
        }

        $this->sendInvite($tester, $event);

        return response()->json([
            'ok' => true,
            'message' => 'Check your inbox for your join link.',
        ]);
    }

    /**
     * Exchange a tester's join link for a participant room token.
     *
     * The token in the URL is the whole credential, so this is the security-
     * relevant path: it mints `moderator: false` unconditionally, and there is no
     * input a caller can supply that changes that.
     */
    public function join(string $token): JsonResponse
    {
        $this->ensureQaEnabled();

        $tester = QaTester::query()->where('token', $token)->first();

        if (! $tester) {
            return response()->json(['message' => 'This link is not valid.'], 404);
        }

        if (! $tester->isUsable()) {
            return response()->json(['message' => $tester->unusableReason()], 410);
        }

        $slot = $tester->slot;

        if (! $slot) {
            return response()->json(['message' => 'Your testing slot has not been set yet.'], 409);
        }

        if (! $slot->isOpen()) {
            // Distinct from a dead link: the link is fine, it is just not this
            // slot's time yet. The page can say so with the real window.
            return response()->json([
                'message' => $slot->starts_at && now()->lt($slot->starts_at)
                    ? 'Your slot has not started yet. It opens at ' . $slot->windowLabel() . '.'
                    : 'Your slot has finished.',
                'slot_not_open' => true,
                'slot' => $this->slotPayload($slot),
            ], 403);
        }

        $cfg = $this->jitsiConfig();

        if (! $cfg) {
            return response()->json(['message' => 'Live sessions are not configured yet.'], 503);
        }

        $jwt = $this->mintJaasToken($cfg, $slot->room, [
            'id' => 'qa-' . $tester->id,
            'name' => $tester->displayName(),
            'email' => $tester->email,
        ], false);

        // Attendance-lite: `joined_at` is the first time they actually opened the
        // room, which is the number the host sheet cares about. There is no
        // duration tracking here on purpose: the LMS attendance machinery keys on
        // a course and a student, neither of which a tester has.
        $tester->forceFill([
            'joined_at' => $tester->joined_at ?? now(),
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'room' => $slot->room,
            'jwt' => $jwt,
            'domain' => $cfg['domain'],
            'app_id' => $cfg['appId'],
            'user_name' => $tester->displayName(),
            'moderator' => false,
            'event' => $this->eventPayload($tester->event),
            'slot' => $this->slotPayload($slot),
        ]);
    }

    /**
     * The moderator surface, held by the iungo team rather than by a tester.
     *
     * Two modes, one endpoint. Without `?slot=` it answers the event and its rooms
     * so the host page can offer a picker. With `?slot=` it mints a moderator token
     * for that room.
     *
     * The token is minted on demand rather than for every room up front because a
     * JaaS token expires two hours after it is minted: a host who opened the page
     * at 9am would be holding dead tokens by the afternoon session, and there is no
     * way for the page to tell.
     */
    public function host(Request $request, string $token): JsonResponse
    {
        $this->ensureQaEnabled();

        $event = QaEvent::query()->where('host_token', $token)->where('is_active', true)->first();

        if (! $event) {
            return response()->json(['message' => 'This host link is not valid.'], 404);
        }

        $slotId = $request->query('slot');

        if ($slotId === null || $slotId === '') {
            return response()->json([
                'event' => $this->eventPayload($event),
                'slots' => $event->slots->map(fn (QaSlot $slot) => $this->slotPayload($slot))->values()->all(),
            ]);
        }

        $slot = QaSlot::query()
            ->where('id', (int) $slotId)
            ->where('qa_event_id', $event->id)
            ->first();

        if (! $slot) {
            return response()->json(['message' => 'That room does not belong to this event.'], 404);
        }

        $cfg = $this->jitsiConfig();

        if (! $cfg) {
            return response()->json(['message' => 'Live sessions are not configured yet.'], 503);
        }

        // A host is not gated on the slot's window the way a tester is: the iungo
        // team needs to be in the room before the first tester arrives and to stay
        // after the last one leaves, or nobody can open or close the session.
        //
        // The email claim is the platform's own from-address rather than an empty
        // string. Every other caller passes a real address (a student, a teacher),
        // so this is the only path that would send an empty one, and JaaS validates
        // the claim's shape when it is present. The address is not shown anywhere a
        // tester can see: the display name below is what renders in the room.
        $jwt = $this->mintJaasToken($cfg, $slot->room, [
            'id' => 'qa-host-' . $event->id,
            // Named after the academy running the session ("iungo host" for the
            // first event) rather than hardcoded, so a second event does not put
            // the wrong brand in front of its own testers.
            'name' => $this->hostDisplayName($event),
            'email' => (string) config('mail.from.address'),
        ], true);

        return response()->json([
            'room' => $slot->room,
            'jwt' => $jwt,
            'domain' => $cfg['domain'],
            'app_id' => $cfg['appId'],
            'user_name' => $this->hostDisplayName($event),
            'moderator' => true,
            'event' => $this->eventPayload($event),
            'slot' => $this->slotPayload($slot),
        ]);
    }

    /** How the host shows up in the room's participant list. */
    private function hostDisplayName(QaEvent $event): string
    {
        $brand = trim((string) $event->tenant?->name);

        return $brand !== '' ? $brand . ' host' : 'Host';
    }

    /**
     * Send (or re-send) a tester's join link, honouring the resend cooldown.
     *
     * Mail is synchronous here (`queue.default = sync`), so a dead SMTP host would
     * otherwise turn a signup into a 500 and lose the row that was just written.
     * The failure is logged and swallowed instead: the tester exists, the host
     * back office can re-send, and the person can use the form again.
     */
    private function sendInvite(QaTester $tester, QaEvent $event): void
    {
        if ($tester->last_emailed_at && $tester->last_emailed_at->gt(now()->subMinutes(self::RESEND_COOLDOWN_MINUTES))) {
            return;
        }

        try {
            Mail::to($tester->email)->send(new QaInviteMail(
                $tester->fresh(['event', 'slot']),
                $event->testerJoinUrl($tester->token),
            ));

            $tester->forceFill(['last_emailed_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('QA invite email failed', [
                'qa_tester_id' => $tester->id,
                'event' => $event->slug,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function eventPayload(?QaEvent $event): array
    {
        if (! $event) {
            return [];
        }

        return [
            'slug' => $event->slug,
            'name' => $event->name,
            'blurb' => $event->blurb,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'is_open' => $event->isOpen(),
            // The academy behind the event, so the page can show the collaboration
            // as "<academy> x Jorsas Tech" rather than hardcoding a name.
            'brand_name' => $event->tenant?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function slotPayload(QaSlot $slot): array
    {
        $taken = $slot->testers()->whereNull('removed_at')->count();

        return [
            'id' => $slot->id,
            'label' => $slot->label,
            'window' => $slot->windowLabel(),
            'starts_at' => $slot->starts_at?->toIso8601String(),
            'ends_at' => $slot->ends_at?->toIso8601String(),
            'capacity' => $slot->capacity,
            'taken' => $taken,
            'remaining' => $slot->capacity === null ? null : max(0, $slot->capacity - $taken),
            'is_open' => $slot->isOpen(),
        ];
    }
}
