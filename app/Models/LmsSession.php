<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsSession extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'role',
        'user_id',
        'token',
        'expires_at',
        // Where this session was opened. Recorded so the device can be named in a
        // "new sign-in" alert and listed on the profile; see LoginDeviceTracker.
        'ip',
        'user_agent',
        'last_seen_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * The request-derived columns every session row records, spread into a
     * create() call: `[...$base, ...LmsSession::requestMeta($request)]`.
     *
     * Exists so the six places that mint a session cannot drift apart on how a
     * device is recorded — a session with an ip but no user agent is invisible to
     * LoginDeviceTracker's fingerprint match, which would silently break both the
     * "new sign-in" alert and per-device sign-out for whoever missed a field.
     *
     * The user agent is truncated to the column width here rather than at each
     * call site because a UA can exceed 255 characters, and MySQL in strict mode
     * rejects the insert outright rather than trimming it.
     */
    public static function requestMeta(?\Illuminate\Http\Request $request): array
    {
        if (! $request) {
            return [];
        }

        return [
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            // Every session is alive the moment it is minted, and this is the
            // field the "sign out other devices" list sorts by.
            'last_seen_at' => now(),
        ];
    }
}
