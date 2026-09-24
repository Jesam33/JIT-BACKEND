<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One time slot on a QA event, which is to say one video room.
 *
 * Slots exist because a single room holding every tester is not a testing
 * session: at 250+ people nobody can speak, so the sessions degrade into a
 * webinar with a chat box. Each slot is its own room, which keeps every room
 * small enough that testers can actually talk through what they are seeing.
 *
 * Not tenant-scoped, for the reason documented on QaEvent.
 */
class QaSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'qa_event_id',
        'label',
        'starts_at',
        'ends_at',
        'room',
        'capacity',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $slot): void {
            if (empty($slot->room)) {
                $slot->room = static::mintRoom((int) $slot->qa_event_id, (string) $slot->label);
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(QaEvent::class, 'qa_event_id');
    }

    public function testers()
    {
        return $this->hasMany(QaTester::class, 'qa_slot_id');
    }

    /**
     * Mint a room name for a slot.
     *
     * The `jit-` prefix and the shape mirror BaseLmsController::ensureRoom(), so
     * these read as the same family of rooms, but the segment after the prefix
     * (`qa`) keeps them structurally distinct from an academy's own rooms
     * (`jit-{tenant}-c{id}` / `-s{id}`) and a collision is impossible by
     * construction rather than by luck.
     *
     * Note the room name is NOT a security boundary: mintJaasToken() sets the JWT
     * `room` claim to '*', so a valid token can join any room on the app. This is a
     * stable, unguessable label, nothing more.
     */
    public static function mintRoom(int $eventId, string $label): string
    {
        $hash = substr(sha1(config('app.key') . '|qa|' . $eventId . '|' . $label . '|' . Str::random(8)), 0, 12);

        return 'jit-qa-' . $eventId . '-' . $hash;
    }

    /**
     * Whether testers may join this room right now. Same two-part rule as the
     * event (switched on, and inside the window); a blank window counts as open
     * so a slot can be tested before its times are set.
     */
    public function isOpen(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && now()->lt($this->starts_at)) {
            return false;
        }

        return ! $this->ends_at || now()->lte($this->ends_at);
    }

    /** Whether the slot has room for one more tester. A null capacity is uncapped. */
    public function hasRoomFor(): bool
    {
        if ($this->capacity === null) {
            return true;
        }

        return $this->testers()->whereNull('removed_at')->count() < $this->capacity;
    }

    /** The human window, e.g. "10:00 - 11:30". Falls back to the stored label. */
    public function windowLabel(): string
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return (string) $this->label;
        }

        return $this->starts_at->format('H:i') . ' - ' . $this->ends_at->format('H:i');
    }
}
