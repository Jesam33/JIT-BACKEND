<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Tenant;
use App\Models\LmsCourse;

/**
 * Course slugs are unique PER INSTITUTE, not globally. Regression guard for the
 * 500 an owner hit when creating a course whose title slugified to one another
 * institute already owned — the pre-tenancy global unique(slug) index threw a
 * 1062 duplicate-key. See migration 2026_08_22_000003 (composite tenant_id+slug
 * unique) and LmsCourse::booted() (per-tenant slug disambiguation).
 */
class CourseSlugTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    /** Run $fn with $tenant bound, mimicking a resolved request, then unbind. */
    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);
        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    public function test_two_institutes_can_each_own_the_same_course_slug(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $courseA = $this->asTenant($a, fn () => LmsCourse::create(['title' => 'Aperture Class', 'is_active' => true]));
        // Before the fix, this second create threw a 1062 duplicate-key on the
        // global slug index and surfaced to the owner as a 500.
        $courseB = $this->asTenant($b, fn () => LmsCourse::create(['title' => 'Aperture Class', 'is_active' => true]));

        $this->assertSame('aperture-class', $courseA->slug);
        $this->assertSame('aperture-class', $courseB->slug);
        $this->assertNotSame($courseA->id, $courseB->id);
    }

    public function test_duplicate_title_within_one_institute_gets_a_numbered_slug(): void
    {
        $a = $this->makeTenant('alpha');

        [$first, $second, $third] = $this->asTenant($a, fn () => [
            LmsCourse::create(['title' => 'Aperture Class', 'is_active' => true]),
            LmsCourse::create(['title' => 'Aperture Class', 'is_active' => true]),
            LmsCourse::create(['title' => 'Aperture Class', 'is_active' => true]),
        ]);

        $this->assertSame('aperture-class', $first->slug);
        $this->assertSame('aperture-class-2', $second->slug);
        $this->assertSame('aperture-class-3', $third->slug);
    }
}
