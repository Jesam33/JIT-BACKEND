<?php

namespace Tests\Feature;

use App\Models\LmsSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The owner's own LOGIN account (Profile page → Personal details tab):
 * reading + updating their name/email, and changing their password. These
 * touch the users-table row behind the owner session, never the tenant's
 * public storefront profile (settings.profile.*), and stay owner-only.
 */
class OwnerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prove behaviour under the cutover flag these routes ship with.
        config(['saas.enforce_tenancy' => true]);
    }

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

    /** Create an owner user + tenant_admins row, mint an owner session, return [token, user]. */
    private function owner(Tenant $tenant, string $email = 'owner@acme.test'): array
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

        return [$token, $user];
    }

    public function test_owner_reads_their_account_details(): void
    {
        $acme = $this->makeTenant('acme');
        [$token, $user] = $this->owner($acme);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/owner/account')
            ->assertOk()
            ->assertJsonPath('account.first_name', 'Owner')
            ->assertJsonPath('account.last_name', 'One')
            ->assertJsonPath('account.email', 'owner@acme.test');
    }

    public function test_owner_updates_name_and_email_and_username_follows(): void
    {
        $acme = $this->makeTenant('acme');
        [$token, $user] = $this->owner($acme);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account', [
                'first_name' => 'Ada',
                'last_name' => 'Owner',
                'email' => 'Ada@NewEmail.test',
            ])
            ->assertOk()
            ->assertJsonPath('account.email', 'ada@newemail.test');

        $user->refresh();
        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('ada@newemail.test', $user->email);
        // Login is by email; setup() seeds username = email, keep them in sync
        // so the users-table uniqueness and any username lookups stay coherent.
        $this->assertSame('ada@newemail.test', $user->username);
    }

    public function test_email_collision_with_another_user_is_rejected(): void
    {
        $acme = $this->makeTenant('acme');
        [$token, $user] = $this->owner($acme);

        User::create([
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'username' => 'someone@else.test',
            'email' => 'someone@else.test',
            'password' => Hash::make('secret123'),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account', [
                'first_name' => 'Owner',
                'last_name' => 'One',
                'email' => 'someone@else.test',
            ])
            ->assertStatus(422);

        $this->assertSame('owner@acme.test', $user->fresh()->email);
    }

    public function test_owner_changes_password_with_current_password(): void
    {
        $acme = $this->makeTenant('acme');
        [$token, $user] = $this->owner($acme);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account/password', [
                'current_password' => 'secret123',
                'password' => 'a-much-better-pass',
                'password_confirmation' => 'a-much-better-pass',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-much-better-pass', $user->fresh()->password));
    }

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $acme = $this->makeTenant('acme');
        [$token, $user] = $this->owner($acme);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account/password', [
                'current_password' => 'not-the-password',
                'password' => 'a-much-better-pass',
                'password_confirmation' => 'a-much-better-pass',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    public function test_owner_sets_and_clears_the_academy_niche(): void
    {
        $acme = $this->makeTenant('acme');
        [$token] = $this->owner($acme);

        // A custom ("Other") niche stores as free text on the tenant.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account', [
                'first_name' => 'Owner',
                'last_name' => 'One',
                'email' => 'owner@acme.test',
                'niche' => 'Aviation training',
            ])
            ->assertOk()
            ->assertJsonPath('account.niche', 'Aviation training');

        $this->assertSame('Aviation training', data_get($acme->fresh()->settings, 'profile.niche'));

        // The read side reports it back.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/owner/account')
            ->assertOk()
            ->assertJsonPath('account.niche', 'Aviation training');

        // An empty niche clears it (same blank → null mapping as updateProfile).
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/account', [
                'first_name' => 'Owner',
                'last_name' => 'One',
                'email' => 'owner@acme.test',
                'niche' => '',
            ])
            ->assertOk()
            ->assertJsonPath('account.niche', null);

        $this->assertNull(data_get($acme->fresh()->settings, 'profile.niche'));
    }

    public function test_account_endpoints_reject_a_non_owner_session(): void
    {
        $acme = $this->makeTenant('acme');

        // A student bearer token resolves the tenant (so the request passes
        // tenant.required), but it must NOT reach the owner's account: the
        // controller only accepts owner sessions.
        $student = $this->asTenant($acme, fn () => \App\Models\LmsStudent::create([
            'first_name' => 'Stu', 'last_name' => 'Dent', 'email' => 'stu@acme.test',
            'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));
        $studentToken = Str::random(80);
        $this->asTenant($acme, fn () => LmsSession::create([
            'role' => 'student', 'user_id' => $student->id, 'token' => $studentToken, 'expires_at' => now()->addDay(),
        ]));

        $authed = $this->withHeader('Authorization', 'Bearer ' . $studentToken);
        $authed->getJson('/api/frontend/lms/owner/account')->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson('/api/frontend/lms/owner/account', [
                'first_name' => 'Nope',
                'last_name' => 'Nope',
                'email' => 'nope@nope.test',
            ])->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson('/api/frontend/lms/owner/account/password', [
                'current_password' => 'x',
                'password' => 'y-you-doing',
                'password_confirmation' => 'y-you-doing',
            ])->assertStatus(403);
    }
}
