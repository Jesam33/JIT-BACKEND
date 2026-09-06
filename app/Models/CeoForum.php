<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A CEO's Forum: a platform-hosted live meeting for institute owners.
 *
 * Created `scheduled` by the jorsastech super-admin, emailed to every institute
 * owner (invite + a reminder ~1h before) by the `lms:send-ceo-forum-emails`
 * command, and joined in-portal from the owner console via a minted JaaS token.
 *
 * Deliberately NOT TenantAware, this belongs to the platform, not an institute.
 * `tenant_id` is a fixed 0 so the shared Jitsi room helper can build a stable
 * room slug (jit-0-f{id}-{hash}) without a real tenant.
 */
class CeoForum extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    /** How many minutes before the start time the join door opens. */
    public const JOIN_LEAD_MINUTES = 10;

    /** Grace window after the scheduled end during which joining still works. */
    public const JOIN_GRACE_MINUTES = 15;

    protected $fillable = [
        'title',
        'topic',
        'scheduled_at',
        'duration_minutes',
        'host_name',
        'meeting_id',
        'status',
        'recording_url',
        'cover_image',
        'invite_sent_at',
        'reminder_sent_at',
        'created_by',
        'created_by_name',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'invite_sent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    /**
     * Platform sentinel tenant id. Lets BaseLmsController::ensureRoom() derive a
     * stable room name for a model that has no real institute behind it.
     */
    public function getTenantIdAttribute(): int
    {
        return 0;
    }

    /** When the forum is scheduled to finish. */
    public function endsAt(): \Illuminate\Support\Carbon
    {
        return $this->scheduled_at->copy()->addMinutes((int) ($this->duration_minutes ?: 60));
    }

    /**
     * True while the room should accept joins: from JOIN_LEAD_MINUTES before the
     * start until JOIN_GRACE_MINUTES after the scheduled end. Cancelled forums
     * never open.
     */
    public function isJoinable(): bool
    {
        if ($this->status === self::STATUS_CANCELLED || ! $this->scheduled_at) {
            return false;
        }

        $now = now();
        $opens = $this->scheduled_at->copy()->subMinutes(self::JOIN_LEAD_MINUTES);
        $closes = $this->endsAt()->addMinutes(self::JOIN_GRACE_MINUTES);

        return $now->betweenIncluded($opens, $closes);
    }

    /** A forum belongs to the archive once it has ended or has a recording. */
    public function isPast(): bool
    {
        return $this->status === self::STATUS_ENDED
            || ! empty($this->recording_url)
            || ($this->scheduled_at && now()->greaterThan($this->endsAt()));
    }
}
