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

        // Keep the Paystack payout split in step with the new plan's commission so
        // an upgrade/downgrade actually changes what the platform retains on this
        // institute's course sales, otherwise the % frozen at subaccount creation
        // would persist. Best-effort and only for a subaccount we manage; it never
        // lets a gateway hiccup break plan activation. (No-op at signup, the bank
        // is linked later, and for the primary, which has no subaccount.)
        $this->syncPayoutCommission();
    }

    /**
     * Whether this tenant's linked Paystack subaccount is one the PLATFORM created
     * from bank details (Path B in OwnerAdminController::updatePaymentSettings), 
     * as opposed to a code the owner pasted in (Path A). Only a platform-managed
     * subaccount belongs to our integration and carries a split we set, so only it
     * is safe to re-sync on a plan change; a pasted code is the owner's own
     * arrangement (possibly on another Paystack account) and is never touched.
     */
    public function payoutSubaccountManaged(): bool
    {
        $paystack = (array) (data_get($this->settings, 'paystack') ?? []);
        if (empty($paystack['subaccount_code'])) {
            return false;
        }

        // The explicit flag set at creation wins; fall back to the presence of the
        // bank details we'd only hold if we created it (covers any row linked
        // before the flag existed).
        return array_key_exists('managed', $paystack)
            ? (bool) $paystack['managed']
            : ! empty($paystack['bank_code']);
    }

    /**
     * Re-point this tenant's Paystack subaccount split at its current plan's
     * commission percent. No-op unless a platform-managed subaccount is linked
     * (a pasted code is left untouched). Best-effort: any failure is logged and
     * swallowed so it never blocks plan activation, the split simply stays at its
     * previous value until the next successful sync. On success the applied % is
     * recorded in settings so the owner payments UI reflects the live split.
     */
    public function syncPayoutCommission(): void
    {
        if (! $this->payoutSubaccountManaged()) {
            return;
        }

        $settings = (array) ($this->settings ?? []);
        $paystack = (array) ($settings['paystack'] ?? []);
        $code = trim((string) ($paystack['subaccount_code'] ?? ''));
        if ($code === '') {
            return;
        }

        $commission = $this->commissionPercent();

        try {
            $service = app(\App\Services\PaystackService::class);
            if (! $service->isConfigured()) {
                return; // gateway off (local/test), nothing to sync
            }

            $result = $service->updateSubaccount($code, $commission);
            if (! ($result['status'] ?? false)) {
                \Illuminate\Support\Facades\Log::warning('Paystack subaccount split sync not confirmed', [
                    'tenant_id' => $this->id,
                    'subaccount' => $code,
                    'commission' => $commission,
                    'message' => $result['message'] ?? null,
                ]);

                return;
            }

            // Record the split we actually applied so the owner UI shows the live %.
            $paystack['percentage_charge'] = $commission;
            $settings['paystack'] = $paystack;
            $this->update(['settings' => $settings]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Paystack subaccount split sync failed', [
                'tenant_id' => $this->id,
                'subaccount' => $code,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether this is the platform's own primary institute (Jorsas). The primary
     * is never limited or gated, it is always treated as the top plan.
     */
    public function isPrimary(): bool
    {
        return $this->slug === config('saas.primary_slug', 'jorsas');
    }

    /**
     * The effective plan definition for this tenant, merged over the free-plan
     * shape so every key (limits, features, commission) is always present even
     * for a partially-configured plan. The primary institute always resolves to
     * the top ('enterprise') plan regardless of what's stored on the row.
     *
     * Single source of truth for every plan-derived decision (limits, feature
     * gates, commission) so the whole pricing model is retuned in config alone.
     */
    public function planConfig(): array
    {
        $plans = (array) config('saas.plans', []);
        $free = (array) ($plans['free'] ?? []);

        if ($this->isPrimary()) {
            $top = (array) ($plans['enterprise'] ?? $plans['pro'] ?? []);

            return array_replace_recursive($free, $top);
        }

        $plan = $this->plan ?: 'free';
        $current = (array) ($plans[$plan] ?? []);

        return array_replace_recursive($free, $current);
    }

    /** The resolved plan slug for this tenant (primary always reads as the top plan, 'enterprise'). */
    public function planSlug(): string
    {
        $plans = (array) config('saas.plans', []);

        if ($this->isPrimary()) {
            return isset($plans['enterprise']) ? 'enterprise' : 'pro';
        }

        $plan = $this->plan ?: 'free';

        return isset($plans[$plan]) ? $plan : 'free';
    }

    /**
     * A numeric plan limit (courses|students|staff), or null when unlimited.
     * A missing key also reads as unlimited (null), fail-open on config typos
     * rather than accidentally capping at zero.
     */
    public function planLimit(string $key): ?int
    {
        $limits = (array) (data_get($this->planConfig(), 'limits') ?? []);
        if (! array_key_exists($key, $limits)) {
            return null;
        }

        $value = $limits[$key];

        return $value === null ? null : (int) $value;
    }

    /**
     * Whether a plan feature is enabled. Known keys: live_classes, chat,
     * certificates, pre_recorded_video, admission_marketer, remove_branding,
     * advanced_analytics, advanced_reporting, custom_domain, priority_support,
     * ai_materials, api_access, white_label.
     */
    public function planFeature(string $key): bool
    {
        return (bool) data_get($this->planConfig(), "features.$key", false);
    }

    /** The platform commission percent for this tenant's plan (per-plan; falls back to the global default). */
    public function commissionPercent(): float
    {
        return (float) data_get(
            $this->planConfig(),
            'commission_percent',
            config('saas.platform_commission_percent', 2)
        );
    }

    /**
     * Plan state + limits + current usage, shaped for the owner UI (billing and
     * dashboard). Usage counts are tenant-scoped to this institute; pass true to
     * include them (they run three COUNT queries).
     */
    public function planSummaryArray(bool $withUsage = true): array
    {
        $config = $this->planConfig();

        $summary = [
            'slug' => $this->planSlug(),
            'name' => (string) ($config['name'] ?? ucfirst($this->planSlug())),
            'label' => (string) ($config['label'] ?? ''),
            'contact_sales' => (bool) ($config['contact_sales'] ?? false),
            'commission_percent' => $this->commissionPercent(),
            'limits' => [
                'courses' => $this->planLimit('courses'),
                'students' => $this->planLimit('students'),
                'staff' => $this->planLimit('staff'),
            ],
            'features' => [
                'live_classes' => $this->planFeature('live_classes'),
                'chat' => $this->planFeature('chat'),
                'certificates' => $this->planFeature('certificates'),
                'pre_recorded_video' => $this->planFeature('pre_recorded_video'),
                'admission_marketer' => $this->planFeature('admission_marketer'),
                'remove_branding' => $this->planFeature('remove_branding'),
                'advanced_analytics' => $this->planFeature('advanced_analytics'),
                'advanced_reporting' => $this->planFeature('advanced_reporting'),
                'custom_domain' => $this->planFeature('custom_domain'),
                'priority_support' => $this->planFeature('priority_support'),
                'ai_materials' => $this->planFeature('ai_materials'),
                'api_access' => $this->planFeature('api_access'),
                'white_label' => $this->planFeature('white_label'),
            ],
        ];

        if ($withUsage) {
            $summary['usage'] = [
                'courses' => \App\Models\LmsCourse::query()->withTenant($this->id)->count(),
                'students' => \App\Models\LmsStudent::query()->withTenant($this->id)->count(),
                'staff' => \App\Models\LmsTeacher::query()->withTenant($this->id)->count(),
            ];
        }

        return $summary;
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
     * What this tenant calls its own organisation, in customer-facing copy, 
     * the primary (Jorsas) is an "Institute"; every other academy defaults to
     * "Online Academy" and its owner can rename it in Customisation. This is
     * TEXT only: routes, columns, and identifiers never change. Depends on the
     * slug, so it's resolved fresh (not stored) for the primary.
     */
    public function defaultEntityLabel(): string
    {
        return $this->isPrimary() ? 'Institute' : 'Online Academy';
    }

    /**
     * The singular + plural entity label for this tenant, merged over the
     * default. The plural is the stored override when present, else derived
     * from the singular via Str::plural. Single source every surface reads so
     * the storefront, portals, and emails all name the entity identically.
     */
    public function entityLabelArray(): array
    {
        $b = (array) (data_get($this->settings, 'branding') ?? []);

        $singular = trim((string) ($b['entity_label'] ?? '')) ?: $this->defaultEntityLabel();
        $plural = trim((string) ($b['entity_label_plural'] ?? '')) ?: \Illuminate\Support\Str::plural($singular);

        return [
            'singular' => $singular,
            'plural' => $plural,
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
        $label = $this->entityLabelArray();

        return [
            // Rebuilt against the current host so a logo URL frozen at upload time
            // (localhost → live, http → https) still resolves, and legacy absolute
            // rows self-heal without a data migration (see App\Support\MediaUrl).
            'logo_url' => \App\Support\MediaUrl::url($b['logo_url'] ?? $d['logo_url']),
            'primary_color' => $b['primary_color'] ?? $d['primary_color'],
            'secondary_color' => $b['secondary_color'] ?? $d['secondary_color'],
            'background_color' => $b['background_color'] ?? $d['background_color'],
            'font_family' => $b['font_family'] ?? $d['font_family'],
            // What the owner calls their organisation (customer-facing text only).
            'entity_label' => $label['singular'],
            'entity_label_plural' => $label['plural'],
            // The academy's display name, customer-facing text. Public institute
            // pages (login / signup / agent) use it to title the browser tab with
            // the academy instead of leaking the platform's inherited "Jorsas
            // Tech". Empty when the tenant has no name; the client only applies it
            // on a NON-primary academy (the primary keeps the default title).
            'name' => trim((string) $this->name),
        ];
    }

    /**
     * The display NAME for this tenant's outgoing transactional mail, the
     * sender's from-NAME and the email header wordmark. A student invited by
     * "Perka Foundation Class" must see that academy, never "Jorsas". Falls back
     * to the platform mail name only when the tenant has no name of its own.
     */
    public function brandMailName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : ((string) config('mail.from.name') ?: 'Jorsas');
    }

    /**
     * The accent colour for this tenant's outgoing transactional mail, the
     * email header background and the CTA button/link. Drawn from the same
     * white-label palette the portals theme off ({@see brandingArray()}), so an
     * academy's invite/reset emails match its storefront. Defaults to platform red.
     */
    public function brandMailColor(): string
    {
        $color = trim((string) ($this->brandingArray()['primary_color'] ?? ''));

        return $color !== '' ? $color : '#ed180d';
    }

    /**
     * Where replies to this tenant's transactional mail should land. The
     * from-ADDRESS stays on the platform's verified domain (deliverability), but a
     * reply belongs to the INSTITUTE: its published public contact email
     * (settings.profile.contact.email) when set + valid, else the owner's own
     * login email (tenant_admins.role = owner). Null when neither is known, the
     * mailable then simply omits Reply-To. Mirrors the per-run map built in
     * SendNotificationEmails::replyToMap(), for the single-send invite/forgot paths.
     */
    public function brandMailReplyTo(): ?string
    {
        $contact = data_get($this->settings, 'profile.contact.email');
        if (is_string($contact) && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return $contact;
        }

        $ownerEmail = \Illuminate\Support\Facades\DB::table('tenant_admins')
            ->join('users', 'users.id', '=', 'tenant_admins.user_id')
            ->where('tenant_admins.tenant_id', $this->id)
            ->where('tenant_admins.role', 'owner')
            ->value('users.email');

        return (is_string($ownerEmail) && filter_var($ownerEmail, FILTER_VALIDATE_EMAIL))
            ? $ownerEmail
            : null;
    }

    /**
     * The full per-institute mail identity, sender NAME, accent COLOUR and
     * REPLY-TO, as one array, with a platform fallback when no tenant resolves.
     * The single source for both {@see \App\Http\Controllers\Lms\BaseLmsController::mailBranding()}
     * (callers that bind the recipient's tenant) and the model-carrying mailables
     * ({@see \App\Mail\Concerns\BrandedMailable}), which resolve their brand from
     * the row's tenant_id, so a paying academy's payment/approval emails are
     * never stamped "Jorsas".
     *
     * @return array{name: string, color: string, reply_to: ?string}
     */
    public static function brandMailArray(?self $tenant): array
    {
        if (! $tenant instanceof self) {
            return [
                'name' => (string) config('mail.from.name') ?: 'Jorsas',
                'color' => '#ed180d',
                'reply_to' => null,
            ];
        }

        return [
            'name' => $tenant->brandMailName(),
            'color' => $tenant->brandMailColor(),
            'reply_to' => $tenant->brandMailReplyTo(),
        ];
    }

    /**
     * {@see brandMailArray()} resolved from a tenant id, a mailable holds the
     * recipient row's tenant_id, not the model. Tenant carries no global scope,
     * so a plain find() reaches any academy; a null/zero id yields the fallback.
     */
    public static function brandMailById(?int $tenantId): array
    {
        return static::brandMailArray($tenantId ? static::find($tenantId) : null);
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
            // Rebuilt against the current host so a cover frozen at upload time still
            // resolves (see brandingArray()/App\Support\MediaUrl).
            'cover_url' => \App\Support\MediaUrl::url($p['cover_url'] ?? null),
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
