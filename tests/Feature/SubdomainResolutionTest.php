<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subdomain → tenant resolution on the production front door. With APP_DOMAIN set,
 * `acme.jorsastech.com` must bind tenant `acme`; infra/reserved hosts like
 * `www.jorsastech.com` must bind nothing. Asserted on a tenant.required route
 * under enforcement, where a bound tenant yields 403 (auth guard) and an unbound
 * one yields 400 (RequireTenant) — a clean discriminator that needs no session.
 */
class SubdomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
        // Exercise the production APP_DOMAIN branch of ResolveTenant. env() reads
        // $_SERVER via its default adapter, so this makes env('APP_DOMAIN') resolve.
        $_SERVER['APP_DOMAIN'] = 'jorsastech.com';
        putenv('APP_DOMAIN=jorsastech.com');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_DOMAIN']);
        putenv('APP_DOMAIN');
        parent::tearDown();
    }

    public function test_tenant_subdomain_binds_tenant_on_required_route(): void
    {
        Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        // No auth: if the subdomain bound tenant `acme`, RequireTenant passes and
        // the billing guard denies with 403 (not an owner). A 400 would mean the
        // tenant was never bound.
        //
        // The host MUST travel via an absolute URL: Laravel's Request::create()
        // derives HTTP_HOST from the URI and overwrites a 'Host' header, so a
        // relative path would leave getHost()='localhost' and never resolve `acme`.
        $response = $this->getJson('http://acme.jorsastech.com/api/frontend/lms/billing/status');

        $response->assertStatus(403);
    }

    public function test_reserved_subdomain_binds_no_tenant(): void
    {
        // `www` is reserved, so ResolveTenant must not treat it as a tenant. With
        // enforcement on and nothing else bound, RequireTenant aborts 400.
        $response = $this->getJson('http://www.jorsastech.com/api/frontend/lms/billing/status');

        $response->assertStatus(400)->assertJsonPath('code', 'TENANT_REQUIRED');
    }

    public function test_apex_domain_binds_no_tenant(): void
    {
        $response = $this->getJson('http://jorsastech.com/api/frontend/lms/billing/status');

        $response->assertStatus(400)->assertJsonPath('code', 'TENANT_REQUIRED');
    }

    public function test_unknown_subdomain_binds_no_tenant(): void
    {
        // A well-formed but non-existent subdomain resolves to no tenant row.
        $response = $this->getJson('http://ghost.jorsastech.com/api/frontend/lms/billing/status');

        $response->assertStatus(400)->assertJsonPath('code', 'TENANT_REQUIRED');
    }

    public function test_signup_rejects_reserved_slug(): void
    {
        $response = $this->postJson('/api/signup', [
            'name' => 'Reserved Co',
            'slug' => 'api', // reserved
            'admin_name' => 'Reserved Admin',
            'admin_email' => 'reserved@example.test',
            'admin_password' => 'password123',
            'plan' => 'free',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'api']);
    }
}
