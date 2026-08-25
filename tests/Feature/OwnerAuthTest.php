<?php

namespace Tests\Feature;

use App\Models\LmsSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner login is the returning-owner front door. It authenticates against the
 * cross-tenant `users` table, but the session it mints must be stamped with the
 * tenant_id of the organisation the owner administers — that stamp is what lets
 * ResolveTenantFromSession bind the tenant on every later owner request (so
 * billing/onboarding pass RequireTenant once enforcement is on).
 */
class OwnerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prove the login/stamp path behaves under the cutover flag it ships with.
        config(['saas.enforce_tenancy' => true]);
    }

    private function makeOwnerUser(Tenant $tenant, string $email = 'owner@acme.test', string $password = 'secret123'): User
    {
        $user = User::create([
            'first_name' => 'Owner',
            'last_name' => 'One',
            'username' => $email,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        DB::table('tenant_admins')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return $user;
    }

    public function test_owner_login_returns_token_and_stamps_session_tenant_id(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = $this->makeOwnerUser($tenant);

        $response = $this->postJson('/api/frontend/lms/owner-login', [
            'email' => 'owner@acme.test',
            'password' => 'secret123',
            'tenant_slug' => 'acme',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);

        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'owner',
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_owner_login_by_tenant_id_also_works(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = $this->makeOwnerUser($tenant);

        $response = $this->postJson('/api/frontend/lms/owner-login', [
            'email' => 'owner@acme.test',
            'password' => 'secret123',
            'tenant_id' => $tenant->id,
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_owner_login_rejects_non_member_of_tenant(): void
    {
        // Two tenants; the user is an owner of Acme only.
        $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $this->makeOwnerUser($acme);

        $response = $this->postJson('/api/frontend/lms/owner-login', [
            'email' => 'owner@acme.test',
            'password' => 'secret123',
            'tenant_slug' => 'other',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('lms_sessions', ['tenant_id' => $other->id]);
    }

    public function test_owner_login_rejects_bad_password(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->makeOwnerUser($tenant);

        $response = $this->postJson('/api/frontend/lms/owner-login', [
            'email' => 'owner@acme.test',
            'password' => 'wrong-password',
            'tenant_slug' => 'acme',
        ]);

        $response->assertStatus(422);
    }
}
