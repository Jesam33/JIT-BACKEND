<?php

namespace Tests\Feature;

use App\Mail\LmsPasswordResetMail;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression for the "reset works but login says invalid credentials" bug: a
 * staff/student account living under a NON-primary institute would reset fine
 * (the token carries the institute), but the emailed link dropped the institute
 * so the browser fell back to the primary slug and login — which is strictly
 * scoped to the requested institute — could not see the account.
 *
 * The fix: forgot-password stamps + emails the account's OWN institute
 * (?tenant={slug}), so the whole reset -> login journey stays on it.
 */
class PasswordResetTenantLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** Pull the raw reset token + tenant out of the one emailed reset link. */
    private function capturedResetLinkQuery(): array
    {
        $link = null;
        Mail::assertSent(LmsPasswordResetMail::class, function (LmsPasswordResetMail $mail) use (&$link) {
            $link = $mail->resetLink;

            return true;
        });

        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_staff_forgot_from_wrong_portal_stamps_and_emails_the_accounts_institute(): void
    {
        Mail::fake();

        Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        // Staff exists ONLY under beta.
        $this->asTenant($beta, fn () => LmsTeacher::create([
            'name' => 'Charlie', 'email' => 'charlie@example.test',
            'password' => Hash::make('old-pass-123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        // Request lands on the WRONG portal (alpha) — mirrors ?tenant=jorsas for a
        // pfa account. The de-scoped fallback must still find beta and bind it.
        $this->withHeader('X-Tenant-Slug', 'alpha')
            ->postJson('/api/frontend/lms/staff/forgot-password', ['email' => 'charlie@example.test'])
            ->assertOk();

        $this->assertDatabaseHas('lms_password_resets', [
            'role' => 'staff', 'email' => 'charlie@example.test', 'tenant_id' => $beta->id,
        ]);

        Mail::assertSent(LmsPasswordResetMail::class, fn (LmsPasswordResetMail $mail) => $mail->hasTo('charlie@example.test')
            && str_contains($mail->resetLink, 'tenant=beta'));
    }

    public function test_staff_can_login_after_reset_scoped_to_its_own_institute(): void
    {
        Mail::fake();

        Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $teacher = $this->asTenant($beta, fn () => LmsTeacher::create([
            'name' => 'Charlie', 'email' => 'charlie@example.test',
            'password' => Hash::make('old-pass-123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        // Forgot from the wrong portal still reaches the beta account.
        $this->withHeader('X-Tenant-Slug', 'alpha')
            ->postJson('/api/frontend/lms/staff/forgot-password', ['email' => 'charlie@example.test'])
            ->assertOk();

        $query = $this->capturedResetLinkQuery();
        $this->assertSame('beta', $query['tenant'] ?? null, 'reset link must carry the account institute');
        $token = $query['token'] ?? '';
        $this->assertNotEmpty($token);

        // Reset on the institute the link carried.
        $this->withHeader('X-Tenant-Slug', 'beta')
            ->postJson('/api/frontend/lms/staff/reset-password', [
                'email' => 'charlie@example.test',
                'token' => $token,
                'password' => 'new-pass-456',
            ])->assertOk();

        // Old password no longer works.
        $this->withHeader('X-Tenant-Slug', 'beta')
            ->postJson('/api/frontend/lms/staff/login', ['email' => 'charlie@example.test', 'password' => 'old-pass-123'])
            ->assertStatus(422);

        // New password logs in on beta's portal — the exact step that used to 422.
        $this->withHeader('X-Tenant-Slug', 'beta')
            ->postJson('/api/frontend/lms/staff/login', ['email' => 'charlie@example.test', 'password' => 'new-pass-456'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'staff', 'user_id' => $teacher->id, 'tenant_id' => $beta->id,
        ]);
    }

    public function test_student_reset_link_also_carries_its_institute(): void
    {
        Mail::fake();

        Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $this->asTenant($beta, fn () => LmsStudent::create([
            'first_name' => 'Bea', 'last_name' => 'B', 'email' => 'bea@example.test',
            'password' => Hash::make('old-pass-123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));

        $this->withHeader('X-Tenant-Slug', 'alpha')
            ->postJson('/api/frontend/lms/forgot-password', ['email' => 'bea@example.test'])
            ->assertOk();

        $this->assertDatabaseHas('lms_password_resets', [
            'role' => 'student', 'email' => 'bea@example.test', 'tenant_id' => $beta->id,
        ]);

        Mail::assertSent(LmsPasswordResetMail::class, fn (LmsPasswordResetMail $mail) => str_contains($mail->resetLink, 'tenant=beta'));
    }
}
