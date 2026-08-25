<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan',
        'subscription_status',
        'current_period_end',
        'stripe_customer_id',
        'stripe_subscription_id',
        'paystack_customer_code',
        'paystack_subscription_id',
        'paystack_init_reference',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'current_period_end' => 'datetime',
    ];

    /**
     * Activate a paid plan on this tenant. Idempotent: called by both the
     * synchronous billing verify path and the Paystack webhook, so re-running it
     * with the same plan simply refreshes the period. Kept on the model so both
     * call sites write identical subscription state.
     */
    public function activatePlan(string $plan): void
    {
        $this->update([
            'plan' => $plan,
            'subscription_status' => 'active',
            'current_period_end' => now()->addMonthNoOverflow(),
        ]);
    }

    /**
     * The base white-label palette (the current red/blue theme). Single
     * source of the branding defaults that stored values merge over.
     */
    public static function defaultBranding(): array
    {
        return [
            'logo_url' => null,
            'primary_color' => '#ed180d',
            'secondary_color' => '#2e82b5',
            // Null = use the standard theme's ambient glow. When set to a hex the
            // institute's public storefront paints a matching radial glow instead.
            'background_color' => null,
            'font_family' => 'default',
        ];
    }

    /**
     * This tenant's white-label branding merged over the defaults. Shared by
     * the owner customization endpoints and the student/staff portal branding
     * read, so every portal themes itself identically to the owner's choices.
     */
    public function brandingArray(): array
    {
        $b = (array) (data_get($this->settings, 'branding') ?? []);
        $d = static::defaultBranding();

        return [
            'logo_url' => $b['logo_url'] ?? $d['logo_url'],
            'primary_color' => $b['primary_color'] ?? $d['primary_color'],
            'secondary_color' => $b['secondary_color'] ?? $d['secondary_color'],
            'background_color' => $b['background_color'] ?? $d['background_color'],
            'font_family' => $b['font_family'] ?? $d['font_family'],
        ];
    }

    /**
     * The empty public-profile shape: hero tagline/about/cover, contact details
     * and social links that make each institute's /i/{slug} page a real mini-site
     * rather than a bare course list. Single source of the profile keys that
     * stored values merge over (parallels defaultBranding()).
     */
    public static function defaultProfile(): array
    {
        return [
            'tagline' => null,
            'about' => null,
            'cover_url' => null,
            'contact' => [
                'email' => null,
                'phone' => null,
                'whatsapp' => null,
                'address' => null,
            ],
            'socials' => [
                'website' => null,
                'facebook' => null,
                'instagram' => null,
                'twitter' => null,
                'linkedin' => null,
            ],
        ];
    }

    /**
     * This institute's public profile merged over the empty defaults. Stored in
     * settings.profile (like branding) and read by the owner editor and the
     * public storefront so both see an identical, fully-keyed shape.
     */
    public function profileArray(): array
    {
        $p = (array) (data_get($this->settings, 'profile') ?? []);
        $contact = (array) ($p['contact'] ?? []);
        $socials = (array) ($p['socials'] ?? []);

        return [
            'tagline' => $p['tagline'] ?? null,
            'about' => $p['about'] ?? null,
            'cover_url' => $p['cover_url'] ?? null,
            'contact' => [
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'whatsapp' => $contact['whatsapp'] ?? null,
                'address' => $contact['address'] ?? null,
            ],
            'socials' => [
                'website' => $socials['website'] ?? null,
                'facebook' => $socials['facebook'] ?? null,
                'instagram' => $socials['instagram'] ?? null,
                'twitter' => $socials['twitter'] ?? null,
                'linkedin' => $socials['linkedin'] ?? null,
            ],
        ];
    }

    /**
     * Resolve the primary (sole/first) organisation: the configured primary
     * slug, else the historical 'default' tenant, else the lowest-id row.
     * Single source of truth shared by the tenant resolver fallback, the
     * admin-panel binder middleware, and the establish-primary command.
     */
    public static function primary(): ?self
    {
        return static::query()->where('slug', config('saas.primary_slug', 'default'))->first()
            ?? static::query()->where('slug', 'default')->first()
            ?? static::query()->orderBy('id')->first();
    }
}
