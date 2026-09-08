<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A custom hostname pointed at the platform by a Pro/Enterprise academy
 * (learn.theiracademy.com). Verified by a DNS TXT record before ResolveTenant
 * will honour it, so a host can never bind a tenant until the owner has proven
 * control of the domain.
 *
 * Deliberately NOT TenantAware: it is looked up by host on unauthenticated
 * public requests (before any tenant is bound), and its own tenant_id column is
 * the thing being resolved. Writes are always explicitly tenant-scoped by the
 * owner controller.
 */
class TenantDomain extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';

    protected $fillable = [
        'tenant_id',
        'host',
        'status',
        'verification_token',
        'verified_at',
        'is_primary',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'verified_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Whether this domain is verified and may resolve a tenant. */
    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * Normalise a user-supplied domain to a bare lowercase host: strip any
     * scheme, path, port, leading "www.", and surrounding whitespace. Returns
     * null when nothing host-like remains. Single source both the resolver and
     * the owner controller use so a stored host always matches an incoming one.
     */
    public static function normalizeHost(?string $input): ?string
    {
        $value = strtolower(trim((string) $input));
        if ($value === '') {
            return null;
        }

        // Drop a scheme if the owner pasted a full URL.
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        } else {
            // No scheme: still trim any path/port the owner may have appended.
            $value = explode('/', $value)[0];
            $value = explode(':', $value)[0];
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // A leading www. is treated as the same site as the apex host.
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        // Must look like a dotted hostname (at least one dot, valid characters).
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
            return null;
        }

        return $value;
    }

    /** A fresh verification token for a new domain row. */
    public static function newToken(): string
    {
        return 'jorsas-verify-' . Str::lower(Str::random(32));
    }

    /** The DNS TXT record host + value the owner must publish to verify. */
    public function dnsInstructions(): array
    {
        return [
            'type' => 'TXT',
            'name' => '_jorsas-verify.' . $this->host,
            'value' => $this->verification_token,
        ];
    }
}
