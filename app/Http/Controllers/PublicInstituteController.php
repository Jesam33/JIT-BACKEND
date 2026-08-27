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
 * scoping correct regardless of how the request arrived — a server-rendered
 * fetch carries no X-Tenant-Slug header — and regardless of the enforce_tenancy
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
                'institute' => ['name' => 'Institute', 'slug' => ''],
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
            'institute' => ['name' => $tenant->name, 'slug' => $tenant->slug],
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
            'institute' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'branding' => $tenant->brandingArray(),
            'profile' => $tenant->profileArray(),
            'course' => $this->serializeCourse($course, $ctx, true),
        ]);
    }

    /**
     * Per-request pricing context, computed once and reused for every course:
     * the localized DISPLAY currency (from a forwarded country / manual currency
     * selection) and whether paid courses are purchasable (a non-primary institute
     * that hasn't linked a payout subaccount yet can't take money — see the
     * matching backend gate in {@see LmsIntakeController::initializePayment}).
     *
     * @return array{fx:CurrencyService,country:?string,forced_currency:?string,charge_currency:string,block_unlinked:bool}
     */
    private function pricingContext(Tenant $tenant, Request $request): array
    {
        // Country is a HINT only (never sets the amount). Forwarded by the Next.js
        // storefront as ?country= / X-Visitor-Country (Laravel can't see the real
        // visitor IP — the storefront is server-rendered). A manual currency pick
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

        // Optional "was" price — the card struck-through renders it ONLY when it
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
            'is_prerecorded_available' => $course->is_prerecorded_available,
        ];

        if ($detail) {
            $payload['requirements'] = $course->requirements;
        }

        return $payload;
    }
}
