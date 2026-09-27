<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;

/**
 * An academy owner's notification row (tenant-wide, not per-user).
 *
 * Mirrors LmsNotification / LmsTeacherNotification / AgentNotification exactly,
 * minus the identity column: see the create_lms_owner_notifications_table
 * migration for why the owner side is scoped to the academy rather than a user.
 * Written through App\Support\Notify, never by hand — the fan-out decides who
 * hears about what.
 */
class LmsOwnerNotification extends Model
{
    use TenantAware;

    protected $table = 'lms_owner_notifications';

    protected $fillable = [
        'type',
        'title',
        'body',
        'reference_type',
        'reference_id',
        'is_read',
        'emailed_at',
        'email_attempts',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'emailed_at' => 'datetime',
        'email_attempts' => 'integer',
    ];
}
