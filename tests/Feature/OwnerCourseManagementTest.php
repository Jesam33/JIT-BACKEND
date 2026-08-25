<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Institute owners manage their own courses and cohorts from the /lms/admin
 * dashboard (OwnerAdminController). These writes mirror the JIT super-admin CRUD
 * but must stay tenant-scoped: every row is stamped/filtered to the owner's org,
 * and course/instructor ids passed by the client are re-resolved through the
 * scoped models so a foreign tenant's id can never be smuggled in.
 */
class OwnerCourseManagementTest extends TestCase
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

    /** Create an owner user + tenant_admins row, mint an owner session, return the bearer token. */
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

    private function authed(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_owner_creates_course_stamped_with_their_tenant(): void
    {
        $acme = $this->makeTenant('acme');
        $token = $this->ownerToken($acme);

        $response = $this->authed($token)->postJson('/api/frontend/lms/owner/courses', [
            'title' => 'Full Stack Web Development',
            'description' => 'Learn to ship apps.',
            'price' => 50000,
            'max_students' => 30,
            'is_live_available' => true,
            'is_prerecorded_available' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('course.title', 'Full Stack Web Development')
            ->assertJsonPath('course.max_students', 30)
            ->assertJsonPath('course.is_prerecorded_available', false);

        $course = LmsCourse::withoutGlobalScope(TenantScope::class)
            ->where('title', 'Full Stack Web Development')
            ->first();
        $this->assertNotNull($course);
        $this->assertSame($acme->id, $course->tenant_id);
    }

    public function test_owner_update_applies_only_present_fields(): void
    {
        $acme = $this->makeTenant('acme');
        $token = $this->ownerToken($acme);

        $course = $this->asTenant($acme, fn () => LmsCourse::create([
            'title' => 'Original',
            'description' => 'Original description',
            'price' => 10000,
            'max_students' => 20,
            'is_active' => true,
        ]));

        // Send only the description — title/price/capacity must survive untouched.
        $this->authed($token)
            ->putJson("/api/frontend/lms/owner/courses/{$course->id}", ['description' => 'Updated description'])
            ->assertOk()
            ->assertJsonPath('course.description', 'Updated description')
            ->assertJsonPath('course.title', 'Original')
            ->assertJsonPath('course.max_students', 20);

        $fresh = LmsCourse::withoutGlobalScope(TenantScope::class)->find($course->id);
        $this->assertSame('Updated description', $fresh->description);
        $this->assertEquals(10000, (int) $fresh->price);
    }

    public function test_owner_cannot_update_another_tenants_course(): void
    {
        $acme = $this->makeTenant('acme');
        $beta = $this->makeTenant('beta');
        $token = $this->ownerToken($acme);

        $betaCourse = $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Only', 'is_active' => true]));

        // Scoped findOrFail can't see beta's row for an acme owner -> 404.
        $this->authed($token)
            ->putJson("/api/frontend/lms/owner/courses/{$betaCourse->id}", ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Beta Only', LmsCourse::withoutGlobalScope(TenantScope::class)->find($betaCourse->id)->title);
    }

    public function test_owner_cannot_delete_another_tenants_course(): void
    {
        $acme = $this->makeTenant('acme');
        $beta = $this->makeTenant('beta');
        $token = $this->ownerToken($acme);

        $betaCourse = $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Only', 'is_active' => true]));

        $this->authed($token)
            ->deleteJson("/api/frontend/lms/owner/courses/{$betaCourse->id}")
            ->assertNotFound();

        $this->assertNotNull(LmsCourse::withoutGlobalScope(TenantScope::class)->find($betaCourse->id));
    }

    public function test_owner_creates_cohort_with_instructor_and_group_chat(): void
    {
        $acme = $this->makeTenant('acme');
        $token = $this->ownerToken($acme);

        [$course, $teacher] = $this->asTenant($acme, fn () => [
            LmsCourse::create(['title' => 'Data Science', 'is_active' => true]),
            LmsTeacher::create(['name' => 'Tutor A', 'email' => 'tutor@acme.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]),
        ]);

        $response = $this->authed($token)->postJson('/api/frontend/lms/owner/tracks', [
            'name' => 'March 2026 Batch',
            'course_id' => $course->id,
            'instructor_id' => $teacher->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('track.name', 'March 2026 Batch')
            ->assertJsonPath('track.instructor_id', $teacher->id)
            ->assertJsonPath('track.course', 'Data Science');

        $track = LmsTrack::withoutGlobalScope(TenantScope::class)->where('name', 'March 2026 Batch')->first();
        $this->assertNotNull($track);
        $this->assertSame($acme->id, $track->tenant_id);
        // Every cohort gets its group chat, same as the super-admin path.
        $this->assertNotNull(LmsGroupChat::withoutGlobalScope(TenantScope::class)->where('track_id', $track->id)->first());
    }

    public function test_store_track_rejects_a_foreign_tenants_course_id(): void
    {
        $acme = $this->makeTenant('acme');
        $beta = $this->makeTenant('beta');
        $token = $this->ownerToken($acme);

        $betaCourse = $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Course', 'is_active' => true]));

        // Smuggling beta's course id into an acme write must be refused, not stamped.
        $this->authed($token)
            ->postJson('/api/frontend/lms/owner/tracks', ['name' => 'Sneaky', 'course_id' => $betaCourse->id])
            ->assertStatus(422);

        $this->assertSame(0, LmsTrack::withoutGlobalScope(TenantScope::class)->where('name', 'Sneaky')->count());
    }

    public function test_store_track_rejects_a_foreign_tenants_instructor_id(): void
    {
        $acme = $this->makeTenant('acme');
        $beta = $this->makeTenant('beta');
        $token = $this->ownerToken($acme);

        $acmeCourse = $this->asTenant($acme, fn () => LmsCourse::create(['title' => 'Acme Course', 'is_active' => true]));
        $betaTeacher = $this->asTenant($beta, fn () => LmsTeacher::create(['name' => 'Beta Tutor', 'email' => 'tutor@beta.test', 'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true]));

        $this->authed($token)
            ->postJson('/api/frontend/lms/owner/tracks', [
                'name' => 'Cross Assign',
                'course_id' => $acmeCourse->id,
                'instructor_id' => $betaTeacher->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, LmsTrack::withoutGlobalScope(TenantScope::class)->where('name', 'Cross Assign')->count());
    }

    public function test_non_owner_token_cannot_manage_courses(): void
    {
        $acme = $this->makeTenant('acme');
        // A staff-role session is not an owner session; ownerContext() returns null.
        $token = Str::random(80);
        $this->asTenant($acme, fn () => LmsSession::create([
            'role' => 'staff',
            'user_id' => 1,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]));

        $this->authed($token)
            ->postJson('/api/frontend/lms/owner/courses', ['title' => 'Nope'])
            ->assertStatus(403);

        $this->assertSame(0, LmsCourse::withoutGlobalScope(TenantScope::class)->where('title', 'Nope')->count());
    }
}
