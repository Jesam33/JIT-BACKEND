<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

/**
 * A device an account has signed in from at least once.
 *
 * Rows outlive `lms_sessions` on purpose — see LoginDeviceTracker for why. The
 * fingerprint is the identity; ip and user_agent are kept alongside it so the
 * profile can show something a human recognises ("Chrome on Windows") without
 * re-deriving it from a hash.
 *
 * @see \App\Support\LoginDeviceTracker
 */
class LmsLoginDevice extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'role',
        'user_id',
        'fingerprint',
        'ip',
        'user_agent',
        'device_label',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
