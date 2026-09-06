<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide (host) announcement. Created `queued` by the jorsastech
 * super-admin, then fanned out into per-recipient notification rows across every
 * tenant by the `lms:dispatch-announcements` command.
 *
 * Deliberately NOT TenantAware, this belongs to the platform, not an institute.
 */
class PlatformAnnouncement extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_FAILED = 'failed';

    /** Audiences an announcement may target. */
    public const AUDIENCES = ['student', 'staff', 'agent'];

    protected $fillable = [
        'title',
        'body',
        'audiences',
        'status',
        'created_by',
        'created_by_name',
        'recipients_count',
        'dispatched_at',
        'error',
    ];

    protected $casts = [
        'audiences' => 'array',
        'recipients_count' => 'integer',
        'dispatched_at' => 'datetime',
    ];
}
