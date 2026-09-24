<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The QA testing pass: a self-contained, one-off event that gathers external
 * testers onto the platform for a day of app testing over our own video rooms.
 *
 * Deliberately NOT modelled on the LMS. A tester is not a student: they have no
 * course, no cohort, no enrolment and no portal. Reusing `lms_students` would
 * have meant minting real academy accounts (and a bound tenant, a track, a group
 * chat) for people who will never log in again after the day. Three small tables
 * that can be dropped cleanly afterwards is the honest shape.
 *
 * ── qa_events ───────────────────────────────────────────────────────────────
 * One row per event, and it does three jobs beyond naming the thing:
 *
 *  1. It carries the TENANT. The public register endpoint resolves the academy
 *     from this row rather than from a header or a session, so the whole flow
 *     works with nothing bound and no tenant-resolution dependency.
 *  2. It is the TIME BOX. Both endpoints refuse outside [starts_at, ends_at], so
 *     the most abusable public surface on the platform closes itself the moment
 *     the day is over, even if nobody remembers to switch it off.
 *  3. It is the KILL SWITCH (`is_active`), which closes it sooner if needed.
 *
 * `host_token` is the moderator link. A Jitsi room with no moderator is a room
 * nobody can manage (no kick, no end, and the recording feature is gated on
 * moderator in BaseLmsController::mintJaasToken), so the event carries exactly
 * one, printed when the event is set up.
 *
 * ── qa_slots ────────────────────────────────────────────────────────────────
 * The rooms. Testers pick one slot at signup and each slot is its own room, which
 * is the whole point: a single room with 250+ people in it is a webinar, not a
 * testing session. `room` is minted once, mirroring the naming in
 * BaseLmsController::ensureRoom() while staying structurally distinct from an
 * academy's own rooms (`jit-{tenant}-c{id}` / `-s{id}`) so a collision is
 * impossible by construction: jit-qa-{event}-{hash}.
 *
 * ── qa_testers ──────────────────────────────────────────────────────────────
 * The people, and the join token IS their credential: no password, no session,
 * no magic-link round trip. Possession of the inbox the link was sent to is the
 * entire verification story, which is exactly what "name, email and phone only"
 * requires.
 *
 * `token` is stored in PLAIN TEXT, matching `training_registrations.invite_token`
 * and `lms_sessions.token`. That is a deliberate trade: it is what lets the host
 * back office show and re-send a tester's own link when it goes astray, which for
 * a one-day event is the single most likely support request. The stakes are a
 * seat in a public beta testing room, and the link expires with the event.
 *
 * `(qa_event_id, email)` is unique: registering twice re-sends the same link
 * rather than creating a second person, so a form retry cannot multiply rows or
 * emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qa_events')) {
            Schema::create('qa_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                // The public path segment: /qa/{slug}. Unique so the URL is unambiguous.
                $t->string('slug', 60)->unique();
                $t->string('name');
                // Short copy for the registration page hero. Nullable so the page
                // falls back to a sensible default rather than rendering empty.
                $t->text('blurb')->nullable();
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('ends_at')->nullable();
                // The moderator link. Unique because it is a credential, not an id.
                $t->string('host_token', 64)->unique();
                $t->boolean('is_active')->default(true);
                $t->timestamps();

                $t->index('tenant_id', 'qa_events_tenant_idx');
            });
        }

        if (! Schema::hasTable('qa_slots')) {
            Schema::create('qa_slots', function (Blueprint $t): void {
                $t->id();
                // Plain id, no FK: the event row is never deleted on its own, and
                // a constraint here would only complicate the post-event purge.
                $t->unsignedBigInteger('qa_event_id');
                // Display label, e.g. "10:00 - 11:30".
                $t->string('label', 80);
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('ends_at')->nullable();
                // The Jitsi room. Minted once and stable across joins.
                $t->string('room', 80)->unique();
                // Null = no slot-level cap. Free-form testers self-select, so this
                // is a soft ceiling the host can set, not a plan limit.
                $t->unsignedInteger('capacity')->nullable();
                $t->boolean('is_active')->default(true);
                $t->integer('sort_order')->default(0);
                $t->timestamps();

                $t->index(['qa_event_id', 'sort_order'], 'qa_slots_event_order_idx');
            });
        }

        if (! Schema::hasTable('qa_testers')) {
            Schema::create('qa_testers', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('qa_event_id');
                // Nullable: a tester who registered before slots were finalised.
                $t->unsignedBigInteger('qa_slot_id')->nullable();
                // One `name` field, not first/last. The signup form asks for a name
                // and nothing else, so splitting it here would invent structure the
                // tester never provided.
                $t->string('name');
                $t->string('email');
                $t->string('phone', 40)->nullable();
                // The join credential. See the class docblock on plain-text storage.
                $t->string('token', 64)->unique();
                $t->dateTime('token_expires_at')->nullable();
                $t->dateTime('joined_at')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                // When the join link was last emailed. This exists to stop the
                // registration endpoint being used as an inbox-spam cannon: a
                // repeat registration is normally someone who lost the email, so
                // it re-sends, but not more than once every couple of minutes
                // (see QaEventController::register). Rate limiting alone cannot
                // cover this, because an attacker with many addresses would still
                // land one send per address per window.
                $t->dateTime('last_emailed_at')->nullable();
                // Recorded for abuse investigation on a public endpoint, not shown.
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                // Set by the host to revoke a link without deleting the row, so the
                // record of who registered survives.
                $t->dateTime('removed_at')->nullable();
                $t->timestamps();

                // The idempotency key: a second registration on the same email
                // updates this row and re-sends its existing link.
                $t->unique(['qa_event_id', 'email'], 'qa_testers_event_email_unique');
                $t->index(['qa_event_id', 'qa_slot_id'], 'qa_testers_event_slot_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_testers');
        Schema::dropIfExists('qa_slots');
        Schema::dropIfExists('qa_events');
    }
};
