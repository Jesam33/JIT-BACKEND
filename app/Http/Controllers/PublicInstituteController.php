<?php

namespace App\Http\Controllers;

use App\Models\LmsCourse;
use App\Models\Tenant;
use App\Services\CurrencyService;
use App\Support\CourseCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, per-institute storefront: each institute's own mini-site showing
 * ONLY its own active courses, themed with its own white-label branding, from
 * which prospective students self-register.
 *
 * The tenant is resolved explicitly from the {slug} in the URL (or from
 * Tenant::primary() for the apex /institute page) and bound as currentTenant,
 * so LmsCourse's global TenantScope filters to this one institute. That makes
 * scoping correct regardless of how the request arrived, a server-rendered
 * fetch carries no X-Tenant-Slug header, and regardless of the enforce_tenancy
 * flag (the scope filters whenever a tenant is bound). This closes the leak
 * where the old, unscoped catalog returned every tenant's courses at once.
 *
 * Deliberately NOT gated by TRAINING_FEATURE_ENABLED: the storefront IS the
 * product surface and is inherently per-tenant, unlike the legacy single-site
 * intake catalog.
 */
class PublicInstituteController extends Controller
{
    /** Storefront for the primary institute (the apex /institute page). */
    public function primaryShow(Request $request): JsonResponse
    {
        $tenant = Tenant::primary();

        // A fresh platform with no tenants yet should render an empty (but
        // valid) storefront rather than 404 the marketing page.
        if (! $tenant) {
            return response()->json([
                'institute' => [
                    'name' => 'Online Academy',
                    'slug' => '',
                    'show_powered_by' => false,
                    'show_agent_program' => false,
                    'entity_label' => 'Online Academy',
                    'entity_label_plural' => 'Online Academies',
                ],
                'branding' => Tenant::defaultBranding(),
                'profile' => Tenant::defaultProfile(),
                'courses' => [],
            ]);
        }

        return $this->storefrontFor($tenant, $request);
    }

    /** Course detail under the primary institute (apex /institute/{course}). */
    public function primaryCourse(Request $request, string $courseSlug): JsonResponse
    {
        return $this->courseFor(Tenant::primary(), $courseSlug, $request);
    }

    /** Storefront for a specific institute by slug (/i/{slug}). */
    public function show(Request $request, string $slug): JsonResponse
    {
        return $this->storefrontFor(Tenant::query()->where('slug', $slug)->first(), $request);
    }

    /** Course detail under a specific institute (/i/{slug}/courses/{course}). */
    public function course(Request $request, string $slug, string $courseSlug): JsonResponse
    {
        return $this->courseFor(Tenant::query()->where('slug', $slug)->first(), $courseSlug, $request);
    }

    /**
     * The "Campuses" directory (jorsastech's own nav): every Pro-and-above
     * academy, shown as an avatar card that links to its storefront. Only Pro+
     * academies are listed, the plan tier is the paid placement, so a Free/Basic
     * academy never appears here. The primary (Jorsas) is itself excluded: this
     * is a showcase of the customer academies built on the platform.
     *
     * Each entry carries the academy's brand (name, logo, entity label), a short
     * description (its public tagline, then its About text), and a handful of its
     * active course titles as a taster, plus the count so the card can say
     * "+N more". Tenant scope is intentionally bypassed (this is a cross-tenant
     * directory) and each academy's course count is scoped explicitly by id.
     */
    public function campuses(): JsonResponse
    {
        $primarySlug = config('saas.primary_slug', 'jorsas');

        // Pro-and-above = the plans whose config unlocks a Pro-tier feature.
        // Deriving it from the feature flag (rather than a hardcoded slug list)
        // keeps the tier definition in config: any plan that grants ai_materials
        // (Gamma, a Pro+ perk) counts as a showcased campus.
        $plans = (array) config('saas.plans', []);
        $proPlans = [];
        foreach ($plans as $slug => $def) {
            if (data_get($def, 'features.ai_materials')) {
                $proPlans[] = $slug;
            }
        }

        $tenants = Tenant::query()
            ->where('slug', '!=', $primarySlug)
            ->where('status', 'active')
            ->whereIn('plan', $proPlans)
            ->orderBy('name')
            ->get();

        $campuses = $tenants->map(function (Tenant $tenant) {
            $branding = $tenant->brandingArray();
            $profile = $tenant->profileArray();

            $courses = LmsCourse::query()
                ->withTenant($tenant->id)
                ->where('is_active', true)
                ->orderBy('title')
                ->limit(6)
                ->pluck('title')
                ->values();

            $courseCount = LmsCourse::query()
                ->withTenant($tenant->id)
                ->where('is_active', true)
                ->count();

            $about = $profile['tagline'] ?: $profile['about'];
            $description = $about ? \Illuminate\Support\Str::limit(strip_tags($about), 160) : null;

            return [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo_url' => $branding['logo_url'],
                'primary_color' => $branding['primary_color'],
                'entity_label' => $branding['entity_label'],
                'description' => $description,
                'niche' => $profile['niche'],
                'course_titles' => $courses,
                'course_count' => $courseCount,
            ];
        })->values();

        return response()->json(['campuses' => $campuses]);
    }

    // ─── internals ───────────────────────────────────────────────────

    private function storefrontFor(?Tenant $tenant, Request $request): JsonResponse
    {
        if (! $tenant) {
            throw new NotFoundHttpException('Institute not found.');
        }

        app()->instance('currentTenant', $tenant);

        $ctx = $this->pricingContext($tenant, $request);

        $courses = LmsCourse::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get();

        $ctx['card'] = CourseCards::context($courses->pluck('id')->all(), $tenant->name);

        $serialized = $courses
            ->map(fn (LmsCourse $course) => $this->serializeCourse($course, $ctx, false))
            ->values();

        return response()->json([
            'institute' => $this->instituteMeta($tenant),
            'branding' => $tenant->brandingArray(),
            'profile' => $tenant->profileArray(),
            'courses' => $serialized,
        ]);
    }

    private function courseFor(?Tenant $tenant, string $courseSlug, Request $request): JsonResponse
    {
        if (! $tenant) {
            throw new NotFoundHttpException('Institute not found.');
        }

        app()->instance('currentTenant', $tenant);

        $course = LmsCourse::query()
            ->where('slug', $courseSlug)
            ->where('is_active', true)
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $ctx = $this->pricingContext($tenant, $request);
        $ctx['card'] = CourseCards::context([$course->id], $tenant->name);

        return response()->json([
            'institute' => $this->instituteMeta($tenant),
            'branding' => $tenant->brandingArray(),
            'profile' => $tenant->profileArray(),
            'course' => $this->serializeCourse($course, $ctx, true),
        ]);
    }

    /**
     * The storefront's `institute` descriptor. Carries the display name/slug,
     * the "Powered by Jorsas" flag (off once a plan removes branding), whether
     * the Admission-Marketer Network is offered on this plan (so the storefront
     * hides "Become an agent" on plans without it), and the tenant's own entity
     * label (what this academy calls itself, customer-facing text only).
     */
    private function instituteMeta(Tenant $tenant): array
    {
        $label = $tenant->entityLabelArray();

        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'show_powered_by' => ! $tenant->planFeature('remove_branding'),
            // The Admission-Marketer Network is a customer-academy offering: the
            // "Become an agent" banner shows on a non-primary academy whose plan
            // includes it, never on the Jorsas primary storefront.
            'show_agent_program' => ! $tenant->isPrimary() && $tenant->planFeature('admission_marketer'),
            'entity_label' => $label['singular'],
            'entity_label_plural' => $label['plural'],
        ];
    }

    /**
     * Per-request pricing context, computed once and reused for every course:
     * the localized DISPLAY currency (from a forwarded country / manual currency
     * selection) and whether paid courses are purchasable (a non-primary institute
     * that hasn't linked a payout subaccount yet can't take money, see the
     * matching backend gate in {@see LmsIntakeController::initializePayment}).
     *
     * @return array{fx:CurrencyService,country:?string,forced_currency:?string,charge_currency:string,block_unlinked:bool}
     */
    private function pricingContext(Tenant $tenant, Request $request): array
    {
        // Country is a HINT only (never sets the amount). Forwarded by the Next.js
        // storefront as ?country= / X-Visitor-Country (Laravel can't see the real
        // visitor IP, the storefront is server-rendered). A manual currency pick
        // arrives as ?currency= and overrides the country→currency mapping for display.
        $country = strtoupper(trim((string) ($request->query('country', $request->header('X-Visitor-Country', ''))))) ?: null;
        $forced = strtoupper(trim((string) $request->query('currency', ''))) ?: null;

        $isNonPrimary = $tenant->slug && $tenant->slug !== config('saas.primary_slug', 'jorsas');
        $hasSubaccount = (bool) data_get($tenant->settings, 'paystack.subaccount_code');

        $fx = app(CurrencyService::class);

        return [
            'fx' => $fx,
            'country' => $country,
            'forced_currency' => $forced,
            'charge_currency' => $fx->chargeCurrencyForCountry($country),
            'block_unlinked' => $isNonPrimary && ! $hasSubaccount,
            // Pre-recorded video is a paid-plan feature. On a plan without it the
            // storefront must not offer pre-recorded at all (the radio is disabled),
            // regardless of the per-course setting.
            'plan_prerecorded' => $tenant->planFeature('pre_recorded_video'),
        ];
    }

    /**
     * Serialize one course for the storefront. `$detail` adds the fields the
     * course page needs (requirements). The base NGN `price` is always present
     * (existing clients rely on it); the localized display fields sit alongside
     * it, and `purchasable` reflects the payout-linked gate.
     *
     * @param  array{fx:CurrencyService,country:?string,forced_currency:?string,charge_currency:string,block_unlinked:bool,card?:array}  $ctx
     */
    private function serializeCourse(LmsCourse $course, array $ctx, bool $detail): array
    {
        $price = (float) $course->price;

        $display = $ctx['forced_currency']
            ? $ctx['fx']->displayInCurrency($price, $ctx['forced_currency'])
            : $ctx['fx']->displayFor($price, $ctx['country']);

        // Optional "was" price, the card struck-through renders it ONLY when it
        // exceeds the current price (the frontend enforces this too). Display uses
        // the SAME FX path as price_display so both sit in the same currency.
        $originalPrice = $course->original_price !== null ? (float) $course->original_price : null;
        $originalPriceDisplay = null;
        if ($originalPrice !== null) {
            $od = $ctx['forced_currency']
                ? $ctx['fx']->displayInCurrency($originalPrice, $ctx['forced_currency'])
                : $ctx['fx']->displayFor($originalPrice, $ctx['country']);
            $originalPriceDisplay = $od['amount'];
        }

        $card = CourseCards::fieldsFor($ctx['card'] ?? [], $course->id);

        // Free courses always enroll; paid courses are blocked only for a
        // non-primary institute that hasn't linked a payout account yet.
        $purchasable = ! ($price > 0 && $ctx['block_unlinked']);

        // Pre-recorded is only actually offered when the per-course toggle AND the
        // plan feature both allow it. A separate (cheaper) pre-recorded price is
        // surfaced only in that case, and localized through the SAME FX path as the
        // live price so both sit in one currency. Null ⇒ the frontend charges the
        // live price for the pre-recorded mode too (no cheaper option).
        $prerecordedAvailable = $course->is_prerecorded_available && ($ctx['plan_prerecorded'] ?? true);
        $prerecordedPrice = ($prerecordedAvailable && $course->prerecorded_price !== null)
            ? (float) $course->prerecorded_price
            : null;
        $prerecordedPriceDisplay = null;
        if ($prerecordedPrice !== null) {
            $pd = $ctx['forced_currency']
                ? $ctx['fx']->displayInCurrency($prerecordedPrice, $ctx['forced_currency'])
                : $ctx['fx']->displayFor($prerecordedPrice, $ctx['country']);
            $prerecordedPriceDisplay = $pd['amount'];
        }

        $payload = [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'price' => $price,
            'currency' => 'NGN',
            'display_currency' => $display['currency'],
            'display_symbol' => $display['symbol'],
            'price_display' => $display['amount'],
            'is_base_currency' => $display['is_base'],
            'charge_currency' => $price > 0 ? $ctx['charge_currency'] : 'NGN',
            'purchasable' => $purchasable,
            'original_price' => $originalPrice,
            'original_price_display' => $originalPriceDisplay,
            'cover_image_url' => $course->cover_image_url,
            'rating_average' => $card['rating_average'],
            'rating_count' => $card['rating_count'],
            'instructor_name' => $card['instructor_name'],
            'is_bestseller' => $card['is_bestseller'],
            'max_students' => $course->max_students,
            'registered_count' => $course->registered_count,
            'slots_remaining' => $course->slotsRemaining(),
            'is_full' => $course->isFull(),
            'is_live_available' => $course->is_live_available,
            // Pre-recorded requires BOTH the per-course toggle AND a plan that
            // unlocks pre-recorded video, so a Free academy never offers it.
            'is_prerecorded_available' => $prerecordedAvailable,
            // Cohort registration window: closed when every cohort's cutoff
            // (registration_deadline ?? start_date, or end_date) has passed.
            // A no-cohort course stays open (pre-cohort behaviour).
            'registration_open' => $course->registrationOpen(),
            'registration_closes_at' => $course->openCohort()?->registrationClosesAt()?->toIso8601String(),
            'next_cohort_starts_at' => $course->tracks()->whereNotNull('start_date')->orderBy('start_date')->value('start_date'),
            // Separate (cheaper) pre-recorded price + its localized display. Null
            // when there's no distinct pre-recorded price (falls back to `price`).
            'prerecorded_price' => $prerecordedPrice,
            'prerecorded_price_display' => $prerecordedPriceDisplay,
        ];

        if ($detail) {
            $payload['requirements'] = $course->requirements;
        }

        return $payload;
    }
}
