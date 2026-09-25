<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/**
 * A device an account has signed in from at least once.
 *
 * Rows outlive `lms_sessions` on purpose — see LoginDeviceTracker for why. The
 * fingerprint is the identity; ip and user_agent are kept alongside it so the
 * profile can show something a human recognises ("Chrome on Windows") without
 * re-deriving it from a hash.
 *
 * NOT TenantAware, deliberately. This model originally had the trait, which
 * added a global `where tenant_id = ?` scope against a table whose migration
 * never created that column: every query threw "Unknown column
 * lms_login_devices.tenant_id". The damage was hidden rather than loud, because
 * LoginDeviceTracker::record() swallows its own failures by design, so logins
 * kept working while no device was ever recorded and no alert was ever sent.
 *
 * Scoping was never the right shape here. The table's uniqueness is
 * (role, user_id, fingerprint) with no tenant in the key, and an account's
 * user_id is already unique per role across the whole platform, so a tenant
 * filter narrows nothing. Every caller passes both explicitly, which is a
 * tighter identity than the scope ever was. Adding a `tenant_id` column would
 * only have moved the failure: TenantAware's creating hook fails closed with
 * "Cannot create ... without a tenant context", which throws on any login path
 * that runs without a tenant bound.
 *
 * @see \App\Support\LoginDeviceTracker
 */
class LmsLoginDevice extends Model
{
    use HasFactory;

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
