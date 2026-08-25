<?php

namespace App\Http\Controllers;

use App\Models\LmsCourse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
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
    public function primaryShow(): JsonResponse
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

        return $this->storefrontFor($tenant);
    }

    /** Course detail under the primary institute (apex /institute/{course}). */
    public function primaryCourse(string $courseSlug): JsonResponse
    {
        return $this->courseFor(Tenant::primary(), $courseSlug);
    }

    /** Storefront for a specific institute by slug (/i/{slug}). */
    public function show(string $slug): JsonResponse
    {
        return $this->storefrontFor(Tenant::query()->where('slug', $slug)->first());
    }

    /** Course detail under a specific institute (/i/{slug}/courses/{course}). */
    public function course(string $slug, string $courseSlug): JsonResponse
    {
        return $this->courseFor(Tenant::query()->where('slug', $slug)->first(), $courseSlug);
    }

    // ─── internals ───────────────────────────────────────────────────

    private function storefrontFor(?Tenant $tenant): JsonResponse
    {
        if (! $tenant) {
            throw new NotFoundHttpException('Institute not found.');
        }

        app()->instance('currentTenant', $tenant);

        $courses = LmsCourse::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCourse $course) => $this->courseCard($course))
            ->values();

        return response()->json([
            'institute' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'branding' => $tenant->brandingArray(),
            'profile' => $tenant->profileArray(),
            'courses' => $courses,
        ]);
    }

    private function courseFor(?Tenant $tenant, string $courseSlug): JsonResponse
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

        return response()->json([
            'institute' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'branding' => $tenant->brandingArray(),
            'profile' => $tenant->profileArray(),
            'course' => [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'description' => $course->description,
                'requirements' => $course->requirements,
                'price' => (float) $course->price,
                'max_students' => $course->max_students,
                'registered_count' => $course->registered_count,
                'slots_remaining' => $course->slotsRemaining(),
                'is_full' => $course->isFull(),
                'is_live_available' => $course->is_live_available,
                'is_prerecorded_available' => $course->is_prerecorded_available,
            ],
        ]);
    }

    private function courseCard(LmsCourse $course): array
    {
        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'price' => (float) $course->price,
            'max_students' => $course->max_students,
            'registered_count' => $course->registered_count,
            'slots_remaining' => $course->slotsRemaining(),
            'is_full' => $course->isFull(),
            'is_live_available' => $course->is_live_available,
            'is_prerecorded_available' => $course->is_prerecorded_available,
        ];
    }
}
