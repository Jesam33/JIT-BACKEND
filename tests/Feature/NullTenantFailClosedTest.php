<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Tenant;
use App\Models\LmsCourse;

/**
 * Phase 1 fail-closed behaviour: a tenant-scoped request that cannot resolve an
 * organisation is rejected (400 TENANT_REQUIRED) rather than silently serving a
 * `default` org or all rows. The console path (seeders/backfill) stays open.
 */
class NullTenantFailClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_required_route_without_tenant_aborts_400_when_enforced(): void
    {
        config(['saas.enforce_tenancy' => true]);

        // No bearer token, no tenant header -> nothing can bind a tenant.
        $response = $this->getJson('/api/frontend/lms/me');

        $response->assertStatus(400)
            ->assertJsonPath('code', 'TENANT_REQUIRED');
    }

    public function test_tenant_required_route_does_not_fail_closed_when_enforcement_off(): void
    {
        config(['saas.enforce_tenancy' => false]);

        $response = $this->getJson('/api/frontend/lms/me');

        // Gate disabled: the request reaches the controller, which rejects the
        // missing session with 401 — never the 400 TENANT_REQUIRED gate.
        $response->assertStatus(401);
        $this->assertNotSame('TENANT_REQUIRED', $response->json('code'));
    }

    public function test_console_context_reads_across_tenants_for_backfill(): void
    {
        config(['saas.enforce_tenancy' => true]);

        $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $b = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        app()->instance('currentTenant', $a);
        LmsCourse::create(['title' => 'A', 'is_active' => true]);
        app()->instance('currentTenant', $b);
        LmsCourse::create(['title' => 'B', 'is_active' => true]);

        // Unbound while running in console (PHPUnit) -> the scope no-ops so seeders
        // and the establish-primary backfill can read every org's rows.
        app()->forgetInstance('currentTenant');
        $this->assertTrue(app()->runningInConsole());
        $this->assertSame(2, LmsCourse::count());
    }
}
