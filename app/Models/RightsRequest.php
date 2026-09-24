<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

/**
 * A data-rights request from a student, staffer or academy owner, answered by the
 * platform.
 *
 * One table for all three requester types: the platform answers them identically,
 * and three near-identical tables would drift apart.
 *
 * `requester_email` is a deliberate SNAPSHOT, copied from the account at the
 * moment of the request. The erasure flow destroys the email on the source row, so
 * without the copy the request that asked for the erasure could no longer be
 * replied to. This is the one place copying an email is correct rather than a
 * denormalisation smell.
 */
class RightsRequest extends Model
{
    use HasFactory;
    use TenantAware;

    public const TYPES = [
        'access' => 'A copy of the data you hold about me',
        'rectification' => 'Correct something that is wrong',
        'erasure' => 'Delete my personal data',
        'portability' => 'Move my data somewhere else',
        'restrict' => 'Stop using my data for something',
        'object' => 'Object to how my data is used',
    ];

    public const STATUSES = ['open', 'in_progress', 'completed', 'refused'];

    protected $fillable = [
        'tenant_id',
        'requester_role',
        'requester_id',
        'requester_email',
        'type',
        'details',
        'status',
        'response_note',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'requester_id' => 'integer',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }
}
