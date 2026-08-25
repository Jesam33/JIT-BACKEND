<?php

namespace Tests\Feature;

use App\Models\LmsSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Self-serve plan upgrades. The owner's bearer session is the only authority:
 * billing derives the tenant from the session (not the middleware-bound tenant),
 * so a student/staff token — which also binds a tenant — cannot reach it.
 * PaystackService is faked; no network calls are made.
 */
class TenantBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Billing lives behind tenant.required; run under enforcement so the test
        // proves the owner bearer binds the tenant and the gate passes.
        config(['saas.enforce_tenancy' => true]);
    }

    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);

        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    /** Create an owner user + admin link + a tenant-stamped owner session. */
    private function ownerToken(Tenant $tenant, string $email = 'owner@acme.test'): string
    {
        $user = User::create([
            'first_name' => 'Owner',
            'last_name' => 'One',
            'username' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);

        DB::table('tenant_admins')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'owner',
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]));

        return $token;
    }

    public function test_checkout_returns_authorization_url_for_paid_plan(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->once()->andReturn([
                'data' => ['authorization_url' => 'https://paystack.test/checkout/abc123'],
            ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'basic']);

        $response->assertOk()
            ->assertJsonPath('authorization_url', 'https://paystack.test/checkout/abc123')
            ->assertJsonStructure(['authorization_url', 'reference']);
    }

    public function test_free_plan_checkout_activates_without_payment(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic']);
        $token = $this->ownerToken($tenant);

        // isConfigured may be probed for billing_configured; no init should happen.
        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->never();
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'free']);

        $response->assertOk()->assertJsonPath('free', true);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'plan' => 'free']);
    }

    public function test_verify_activates_plan_on_faked_success(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $this->mock(PaystackService::class, function ($mock) use ($tenant) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('verifyTransaction')->once()->andReturn([
                'data' => [
                    'status' => 'success',
                    'metadata' => [
                        'tenant_id' => $tenant->id,
                        'plan' => 'basic',
                        'purpose' => 'plan_upgrade',
                    ],
                ],
            ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/billing/verify?reference=JORSAS-UPG-TEST');

        $response->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('plan', 'basic');

        $tenant->refresh();
        $this->assertSame('basic', $tenant->plan);
        $this->assertSame('active', $tenant->subscription_status);
        $this->assertNotNull($tenant->current_period_end);
    }

    public function test_verify_rejects_reference_belonging_to_another_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $this->mock(PaystackService::class, function ($mock) use ($tenant) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('verifyTransaction')->andReturn([
                'data' => [
                    'status' => 'success',
                    'metadata' => [
                        'tenant_id' => $tenant->id + 999, // some other org
                        'plan' => 'basic',
                        'purpose' => 'plan_upgrade',
                    ],
                ],
            ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/billing/verify?reference=NOT-MINE');

        $response->assertStatus(403);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'plan' => 'free']);
    }

    public function test_non_owner_token_cannot_reach_billing(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        // A student session for this tenant — binds the tenant (so RequireTenant
        // passes) but is not an owner, so the billing guard must still deny it.
        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'student',
            'user_id' => 1,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/billing/status');

        $response->assertStatus(403);
    }
}
