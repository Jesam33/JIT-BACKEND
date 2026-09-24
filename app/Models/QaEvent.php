<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A one-off QA testing event (see the create_qa_testing_tables migration).
 *
 * NOTE ON TENANCY, because this is the one place the convention is deliberately
 * inverted: this model does NOT use the TenantAware trait, and neither does
 * QaSlot or QaTester.
 *
 * Every TenantAware model carries TenantScope, which fails CLOSED: with no tenant
 * bound and `saas.enforce_tenancy` on, it compiles to `whereRaw('1 = 0')`. The
 * public registration and join endpoints run with nothing bound by design (the
 * academy is resolved from THIS row, not from a header or a session), so a scoped
 * lookup here would silently find nothing and 404 the entire event. Being outside
 * the trait is what makes the flow work at all.
 *
 * `tenant_id` is therefore set explicitly rather than stamped, and it means "the
 * academy this event belongs to", not "who may query this row". The only reader
 * is the host back office, which wants every event anyway.
 */
class QaEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'blurb',
        'starts_at',
        'ends_at',
        'host_token',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function slots()
    {
        return $this->hasMany(QaSlot::class)->orderBy('sort_order')->orderBy('id');
    }

    public function testers()
    {
        return $this->hasMany(QaTester::class);
    }

    /** Resolve an event by its public URL segment. Null when unknown or switched off. */
    public static function findOpen(string $slug): ?self
    {
        return static::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Whether the event is accepting registrations right now. Two independent
     * conditions and both must hold: the event is switched on, and now is inside
     * its window. A blank window counts as open so an event can be set up and
     * tested before its dates are pinned down.
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

    /** A fresh moderator token, generated once when the event is created. */
    public static function mintHostToken(): string
    {
        return Str::random(48);
    }

    /** The public join link handed to a tester. */
    public function testerJoinUrl(string $token): string
    {
        return $this->baseUrl() . '/join/' . $token;
    }

    /** The moderator link, held by the iungo team rather than by a tester. */
    public function hostUrl(): string
    {
        return $this->baseUrl() . '/host/' . $this->host_token;
    }

    /** The public registration page for this event. */
    public function registerUrl(): string
    {
        return $this->baseUrl();
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('saas.frontend_url'), '/') . '/qa/' . $this->slug;
    }
}
