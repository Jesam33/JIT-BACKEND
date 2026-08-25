<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsSession;
use App\Scopes\TenantScope;

/**
 * Phase 1 auth ordering: the session is the authoritative source of the tenant.
 * Login stamps tenant_id on the session; a bearer token alone resolves its own
 * tenant; a header naming a different real tenant than the session is rejected.
 */
class SessionTenantAuthTest extends TestCase
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

    public function test_student_login_stamps_session_tenant_id(): void
    {
        $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

        $student = $this->asTenant($a, fn () => LmsStudent::create([
            'first_name' => 'Ada', 'last_name' => 'A', 'email' => 'ada@alpha.test',
            'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));

        $response = $this->withHeader('X-Tenant-Slug', 'alpha')
            ->postJson('/api/frontend/lms/login', ['email' => 'ada@alpha.test', 'password' => 'secret123']);

        $response->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'student',
            'user_id' => $student->id,
            'tenant_id' => $a->id,
        ]);
    }

    public function test_staff_login_stamps_session_tenant_id(): void
    {
        $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

        $teacher = $this->asTenant($a, fn () => LmsTeacher::create([
            'name' => 'Tutor A', 'email' => 'tutor@alpha.test',
            'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        $response = $this->withHeader('X-Tenant-Slug', 'alpha')
            ->postJson('/api/frontend/lms/staff/login', ['email' => 'tutor@alpha.test', 'password' => 'secret123']);

        $response->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'staff',
            'user_id' => $teacher->id,
            'tenant_id' => $a->id,
        ]);
    }

    public function test_bearer_token_alone_resolves_the_sessions_tenant(): void
    {
        $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $student = $this->asTenant($a, fn () => LmsStudent::create([
            'first_name' => 'Ada', 'last_name' => 'A', 'email' => 'ada@alpha.test',
            'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));
        $token = Str::random(80);
        $this->asTenant($a, fn () => LmsSession::create([
            'role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addDay(),
        ]));

        // No tenant header at all — the session must resolve tenant A on its own.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/me');

        $response->assertOk()->assertJsonPath('id', $student->id);
    }

    public function test_header_conflicting_with_session_tenant_is_rejected(): void
    {
        $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $student = $this->asTenant($a, fn () => LmsStudent::create([
            'first_name' => 'Ada', 'last_name' => 'A', 'email' => 'ada@alpha.test',
            'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));
        $token = Str::random(80);
        $this->asTenant($a, fn () => LmsSession::create([
            'role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addDay(),
        ]));

        // Session belongs to A, but the caller claims B via header -> cross-tenant attempt.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-Slug' => 'beta',
        ])->getJson('/api/frontend/lms/me');

        $response->assertStatus(403);
    }

    /**
     * On the bare domain (no header/subdomain — enforcement off, the live default),
     * a student whose email also exists under another institute must authenticate
     * against the account whose password actually matches, and the minted session
     * must carry THAT account's tenant. Regression for null-tenant sessions and
     * wrong-institute logins when the same email is reused across tenants.
     */
    public function test_student_login_without_header_resolves_duplicate_email_by_password(): void
    {
        config(['saas.enforce_tenancy' => false]);

        $alpha = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $alphaStudent = $this->asTenant($alpha, fn () => LmsStudent::create([
            'first_name' => 'Ada', 'last_name' => 'A', 'email' => 'dup@example.test',
            'password' => Hash::make('alpha-pass-1'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));
        $betaStudent = $this->asTenant($beta, fn () => LmsStudent::create([
            'first_name' => 'Bea', 'last_name' => 'B', 'email' => 'dup@example.test',
            'password' => Hash::make('beta-pass-1'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));

        // Beta's password -> Beta's account + Beta's tenant stamped on the session.
        $this->postJson('/api/frontend/lms/login', ['email' => 'dup@example.test', 'password' => 'beta-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'student', 'user_id' => $betaStudent->id, 'tenant_id' => $beta->id,
        ]);

        // Alpha's password -> Alpha's account + Alpha's tenant, from the same bare-domain endpoint.
        $this->postJson('/api/frontend/lms/login', ['email' => 'dup@example.test', 'password' => 'alpha-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'student', 'user_id' => $alphaStudent->id, 'tenant_id' => $alpha->id,
        ]);

        // No session was ever minted with a null tenant.
        $this->assertDatabaseMissing('lms_sessions', ['role' => 'student', 'tenant_id' => null]);
    }

    /** Staff mirror of the duplicate-email bare-domain login resolution. */
    public function test_staff_login_without_header_resolves_duplicate_email_by_password(): void
    {
        config(['saas.enforce_tenancy' => false]);

        $alpha = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $alphaTeacher = $this->asTenant($alpha, fn () => LmsTeacher::create([
            'name' => 'Tutor Alpha', 'email' => 'tutor@example.test',
            'password' => Hash::make('alpha-pass-1'), 'role' => 'Instructor', 'is_active' => true,
        ]));
        $betaTeacher = $this->asTenant($beta, fn () => LmsTeacher::create([
            'name' => 'Tutor Beta', 'email' => 'tutor@example.test',
            'password' => Hash::make('beta-pass-1'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        $this->postJson('/api/frontend/lms/staff/login', ['email' => 'tutor@example.test', 'password' => 'beta-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'staff', 'user_id' => $betaTeacher->id, 'tenant_id' => $beta->id,
        ]);

        $this->postJson('/api/frontend/lms/staff/login', ['email' => 'tutor@example.test', 'password' => 'alpha-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('lms_sessions', [
            'role' => 'staff', 'user_id' => $alphaTeacher->id, 'tenant_id' => $alpha->id,
        ]);

        $this->assertDatabaseMissing('lms_sessions', ['role' => 'staff', 'tenant_id' => null]);
    }

    /**
     * A legacy session whose own tenant_id is null (minted before login stamped a
     * tenant) must still resolve the right institute from the student's row, so the
     * portal loads instead of hanging on "Loading…". Enforcement is ON here, so
     * without recovery RequireTenant would 400 this request.
     */
    public function test_null_tenant_student_session_recovers_tenant_from_student_row(): void
    {
        $alpha = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

        $student = $this->asTenant($alpha, fn () => LmsStudent::create([
            'first_name' => 'Ada', 'last_name' => 'A', 'email' => 'ada@alpha.test',
            'password' => Hash::make('secret123'), 'learning_mode' => 'live', 'onboarding_completed' => true,
        ]));

        // Legacy row: no tenant context at mint time -> tenant_id left null.
        $token = Str::random(80);
        LmsSession::query()->create([
            'role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addDay(),
        ]);
        LmsSession::query()->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)->update(['tenant_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/me');

        $response->assertOk()->assertJsonPath('id', $student->id);
    }

    /** Staff mirror: a null-tenant staff session recovers its tenant from the teacher row. */
    public function test_null_tenant_staff_session_recovers_tenant_from_teacher_row(): void
    {
        $alpha = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

        $teacher = $this->asTenant($alpha, fn () => LmsTeacher::create([
            'name' => 'Tutor A', 'email' => 'tutor@alpha.test',
            'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        $token = Str::random(80);
        LmsSession::query()->create([
            'role' => 'staff', 'user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay(),
        ]);
        LmsSession::query()->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)->update(['tenant_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/staff/me');

        $response->assertOk()->assertJsonPath('id', $teacher->id);
    }
}
