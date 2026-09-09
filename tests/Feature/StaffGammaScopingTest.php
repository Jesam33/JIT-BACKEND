<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsModule;
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
 * Staff AI training materials (StaffGammaController, Gamma Pro+). The teacher
 * gets the same generate → poll → save flow the owner has, but every save /
 * module-list target must be a course they are ASSIGNED to (via their cohorts,
 * LmsTrack.instructor_id) — the same scoping StaffPortalController applies to
 * materials. generate/status hit the external Gamma API and are not covered
 * here; save + courseModules exercise the context resolution, the tenant
 * scope, the assignment hooks and the ai_materials PlanGate without it.
 */
class StaffGammaScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
    }

    private function makeTenant(string $slug, string $plan = 'pro'): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'plan' => $plan]);
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

    /** Create a teacher + staff session (tenant_id stamped, like real login), return [$token, $teacher]. */
    private function staffToken(Tenant $tenant): array
    {
        $teacher = $this->asTenant($tenant, fn () => LmsTeacher::create([
            'name' => 'Tutor', 'email' => 'tutor@' . $tenant->slug . '.test',
            'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'staff',
            'user_id' => $teacher->id,
            'token' => $token,
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        return [$token, $teacher];
    }

    /** Create an owner user + tenant_admins row, mint an owner session, return the bearer token. */
    private function ownerToken(Tenant $tenant): string
    {
        $email = 'owner@' . $tenant->slug . '.test';
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
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        return $token;
    }

    /** A course + a cohort taught by $teacher (the assignment that unlocks it). */
    private function assignedCourse(Tenant $tenant, LmsTeacher $teacher, string $title): LmsCourse
    {
        return $this->asTenant($tenant, function () use ($teacher, $title) {
            $course = LmsCourse::create(['title' => $title, 'is_active' => true]);
            LmsTrack::create(['name' => $title . ' Batch', 'course_id' => $course->id, 'instructor_id' => $teacher->id]);
            return $course;
        });
    }

    public function test_staff_saves_gamma_link_into_assigned_course(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'HTML Slides',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $course->id,
            ])
            ->assertCreated()
            ->assertJsonPath('target', 'course');

        $this->assertDatabaseHas('lms_materials', [
            'course_id' => $course->id,
            'title' => 'HTML Slides',
            'type' => 'link',
        ]);
    }

    public function test_staff_cannot_save_into_unassigned_course_of_their_institute(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $assigned = $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $other->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)->where('title', 'Sneaky')->count());
        // The assigned course is untouched too.
        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)->where('course_id', $assigned->id)->count());
    }

    public function test_staff_cannot_save_into_module_of_unassigned_course(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));
        $module = $this->asTenant($tenant, fn () => LmsModule::create(['course_id' => $other->id, 'title' => 'Week 1']));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'module_id' => $module->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('lms_module_contents')->where('module_id', $module->id)->count());
    }

    public function test_staff_module_picker_is_scoped_to_assigned_courses(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $assigned = $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $this->asTenant($tenant, fn () => LmsModule::create(['course_id' => $assigned->id, 'title' => 'Week 1']));
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/frontend/lms/staff/courses/{$assigned->id}/modules")
            ->assertOk()
            ->assertJsonCount(1, 'modules');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/frontend/lms/staff/courses/{$other->id}/modules")
            ->assertStatus(403);
    }

    public function test_cross_tenant_course_id_404s_instead_of_authorizing(): void
    {
        $acme = $this->makeTenant('acme');
        [$token] = $this->staffToken($acme);
        $beta = $this->makeTenant('beta');
        $betaCourse = $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Only', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $betaCourse->id,
            ])
            ->assertNotFound();
    }

    public function test_free_plan_academy_gets_the_pro_gate_402(): void
    {
        $tenant = $this->makeTenant('freebie', 'free');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'HTML Slides',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $course->id,
            ])
            ->assertStatus(402);
    }

    public function test_owner_save_still_reaches_any_course_of_their_institute(): void
    {
        // Regression guard for the hook refactor: the owner variant must keep
        // allowing every tenant course, assigned to a teacher or not.
        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $course = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Owner Course', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/ai/materials/save', [
                'title' => 'Owner Slides',
                'url' => 'https://gamma.app/doc/owner123',
                'course_id' => $course->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('lms_materials', ['course_id' => $course->id, 'title' => 'Owner Slides']);
    }

    public function test_staff_me_exposes_the_ai_materials_flag(): void
    {
        $pro = $this->makeTenant('acme');
        [$proToken] = $this->staffToken($pro);

        $this->withHeader('Authorization', 'Bearer ' . $proToken)
            ->getJson('/api/frontend/lms/staff/me')
            ->assertOk()
            ->assertJsonPath('ai_materials', true);

        $free = $this->makeTenant('freebie', 'free');
        [$freeToken] = $this->staffToken($free);

        $this->withHeader('Authorization', 'Bearer ' . $freeToken)
            ->getJson('/api/frontend/lms/staff/me')
            ->assertOk()
            ->assertJsonPath('ai_materials', false);
    }
}
