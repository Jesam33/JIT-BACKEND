<?php

namespace Tests\Feature;

use App\Mail\LmsPasswordResetMail;
use App\Models\LmsSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner onboarding actions create real, tenant-stamped LMS rows and send the
 * invitee a "set your password" link. Runs under enforcement so the owner bearer
 * binds the tenant through the middleware and the created rows inherit it.
 */
class OwnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
        Mail::fake();
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

    public function test_import_students_creates_tenant_stamped_rows_and_invites(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/onboarding/import-students', [
                'tenant' => $tenant->id,
                'emails' => ['ada@acme.test', 'grace@acme.test'],
            ]);

        $response->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('invited', 2);

        $this->assertDatabaseHas('lms_students', [
            'email' => 'ada@acme.test',
            'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseHas('lms_students', [
            'email' => 'grace@acme.test',
            'tenant_id' => $tenant->id,
        ]);

        Mail::assertSent(LmsPasswordResetMail::class);
    }

    public function test_import_students_skips_invalid_emails(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/onboarding/import-students', [
                'tenant' => $tenant->id,
                'emails' => ['valid@acme.test', 'not-an-email', ''],
            ]);

        $response->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('skipped', 2);
    }

    public function test_invite_staff_creates_pending_teacher(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $token = $this->ownerToken($tenant);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/onboarding/invite-staff', [
                'tenant' => $tenant->id,
                'email' => 'teacher@acme.test',
            ]);

        $response->assertOk()
            ->assertJsonPath('invited', 'teacher@acme.test')
            ->assertJsonStructure(['teacher_id']);

        $this->assertDatabaseHas('lms_teachers', [
            'email' => 'teacher@acme.test',
            'tenant_id' => $tenant->id,
        ]);

        Mail::assertSent(LmsPasswordResetMail::class);
    }

    public function test_onboarding_rejects_caller_without_owner_token(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        // A student session binds the tenant (RequireTenant passes) but is not an
        // owner/admin of it, so authorizeOwnerForTenant must reject.
        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'student',
            'user_id' => 1,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/onboarding/invite-staff', [
                'tenant' => $tenant->id,
                'email' => 'teacher@acme.test',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('lms_teachers', ['email' => 'teacher@acme.test']);
    }
}
