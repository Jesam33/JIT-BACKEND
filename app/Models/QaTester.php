<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A registered QA tester (see the create_qa_testing_tables migration).
 *
 * The `token` is the tester's entire credential. There is no password and no
 * session: the emailed link carries the token, the join endpoint exchanges it for
 * a participant JWT, and that is the whole auth story. Possession of the inbox the
 * link was sent to is the verification, which is what makes "name, email and phone
 * only" possible without an open door.
 *
 * Not tenant-scoped, for the reason documented on QaEvent.
 */
class QaTester extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'qa_event_id',
        'qa_slot_id',
        'name',
        'email',
        'phone',
        'token',
        'token_expires_at',
        'joined_at',
        'last_seen_at',
        'last_emailed_at',
        'ip',
        'user_agent',
        'removed_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_emailed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(QaEvent::class, 'qa_event_id');
    }

    public function slot()
    {
        return $this->belongsTo(QaSlot::class, 'qa_slot_id');
    }

    public static function mintToken(): string
    {
        return Str::random(48);
    }

    /**
     * How long a join link stays good for. Defaults to the event's own end so the
     * links die with the day, with a floor of one day so an event with no dates
     * set still gets links that outlive a slow email.
     *
     * Takes the event optionally because callers that have just set `qa_event_id`
     * hold it already, and the relation on the unsaved-then-saved model may not
     * have been loaded yet.
     */
    public function tokenLifetimeEnd(?QaEvent $event = null): \Carbon\CarbonInterface
    {
        $event ??= $this->event;
        $eventEnd = $event?->ends_at;

        return $eventEnd && $eventEnd->isFuture()
            ? $eventEnd->copy()->addDay()
            : now()->addDay();
    }

    /**
     * Whether this link may still be exchanged for a room token. Three ways to be
     * dead: the host removed it, it expired, or the event was switched off.
     */
    public function isUsable(): bool
    {
        if ($this->removed_at) {
            return false;
        }

        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return false;
        }

        return (bool) $this->event?->isOpen();
    }

    /** Why this link no longer works, for a message the tester can act on. */
    public function unusableReason(): string
    {
        if ($this->removed_at) {
            // Says "the team running the session" rather than naming iungo: this
            // column backs every QA event, not just the first one.
            return 'This testing pass has been cancelled. Contact the team running the session if you think that is wrong.';
        }

        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return 'This link has expired. Sign up again to get a fresh one.';
        }

        if (! $this->event?->isOpen()) {
            return 'This testing event is not open right now.';
        }

        return 'This link is no longer valid.';
    }

    /** The tester's own join link, rebuilt from the stored token. */
    public function joinUrl(): string
    {
        return $this->event->testerJoinUrl($this->token);
    }

    /** The name Jitsi will display. Falls back so a blank name never renders empty. */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : 'Tester';
    }
}
