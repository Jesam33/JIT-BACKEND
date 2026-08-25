<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Models\LmsCourse;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsSession;
use App\Scopes\TenantScope;

/**
 * Phase 1 tenancy: reads for one organisation must never surface another's rows.
 * Verified at the model level (the shared TenantScope every TenantAware model
 * inherits) and end-to-end over HTTP (session -> tenant -> scoped read).
 */
class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    /** Run $fn with $tenant bound, mimicking a real resolved request, then unbind. */
    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);

        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    public function test_reads_are_scoped_to_the_bound_tenant_across_core_and_gap_tables(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        // Seed the same shapes in both orgs. lms_teachers is one of the 7 tables
        // that only just gained a tenant_id column, so it exercises the gap fix.
        $this->asTenant($a, function () {
            LmsCourse::create(['title' => 'A Course', 'is_active' => true]);
            LmsStudent::create(['first_name' => 'Ada', 'last_name' => 'A', 'email' => 'ada@alpha.test', 'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true]);
            LmsTeacher::create(['name' => 'Tutor A', 'email' => 'tutor@alpha.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]);
        });
        $this->asTenant($b, function () {
            LmsCourse::create(['title' => 'B Course', 'is_active' => true]);
            LmsCourse::create(['title' => 'B Course 2', 'is_active' => true]);
            LmsStudent::create(['first_name' => 'Bem', 'last_name' => 'B', 'email' => 'bem@beta.test', 'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true]);
            LmsTeacher::create(['name' => 'Tutor B', 'email' => 'tutor@beta.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]);
        });

        // Bound to A: only A's rows are visible.
        app()->instance('currentTenant', $a);
        $this->assertSame(1, LmsCourse::count());
        $this->assertSame('A Course', LmsCourse::first()->title);
        $this->assertSame(1, LmsStudent::count());
        $this->assertSame('ada@alpha.test', LmsStudent::first()->email);
        $this->assertSame(1, LmsTeacher::count());
        $this->assertSame('tutor@alpha.test', LmsTeacher::first()->email);

        // Bound to B: only B's rows are visible.
        app()->instance('currentTenant', $b);
        $this->assertSame(2, LmsCourse::count());
        $this->assertSame('bem@beta.test', LmsStudent::first()->email);
        $this->assertSame('tutor@beta.test', LmsTeacher::first()->email);

        // The rows genuinely coexist in both orgs — this is isolation, not absence.
        app()->forgetInstance('currentTenant');
        $this->assertSame(3, LmsCourse::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(2, LmsStudent::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(2, LmsTeacher::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_fetching_another_tenants_row_by_id_is_not_visible(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $courseB = $this->asTenant($b, fn () => LmsCourse::create(['title' => 'B Only', 'is_active' => true]));

        // An A-bound lookup of B's known id must miss (scoped away), even though
        // the row exists when the scope is lifted.
        app()->instance('currentTenant', $a);
        $this->assertNull(LmsCourse::find($courseB->id));
        $this->assertNotNull(LmsCourse::withoutGlobalScope(TenantScope::class)->find($courseB->id));
    }

    public function test_authenticated_session_scopes_http_reads_to_its_tenant(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $teacherA = $this->asTenant($a, fn () => LmsTeacher::create(['name' => 'Tutor A', 'email' => 'tutor@alpha.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]));
        // A same-shaped teacher in B must never surface for an A session.
        $this->asTenant($b, fn () => LmsTeacher::create(['name' => 'Tutor B', 'email' => 'tutor@beta.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]));

        $token = Str::random(80);
        $this->asTenant($a, fn () => LmsSession::create(['role' => 'staff', 'user_id' => $teacherA->id, 'token' => $token, 'expires_at' => now()->addDay()]));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/staff/me');

        $response->assertOk()
            ->assertJsonPath('id', $teacherA->id)
            ->assertJsonPath('email', 'tutor@alpha.test');
    }
}
