<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1. A cohort can be Unassigned, and removing a teacher never deletes their
 *    cohorts (lms_tracks.instructor_id is nullable + nullOnDelete).
 * 2. Anti-scam: an academy can't delete a course students have paid for.
 */
class UnassignedCohortsAndCourseDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);

        $user = User::create([
            'first_name' => 'Owner', 'last_name' => 'One', 'username' => 'owner@acme.test',
            'email' => 'owner@acme.test', 'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert(['tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->ownerToken = Str::random(80);
        $this->asTenant(fn () => LmsSession::create([
            'role' => 'owner', 'user_id' => $user->id, 'token' => $this->ownerToken, 'expires_at' => now()->addDay(),
        ]));
    }

    private function asTenant(callable $fn)
    {
        app()->instance('currentTenant', $this->acme);
        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    private function owner(): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken);
    }

    private function course(): LmsCourse
    {
        return $this->asTenant(fn () => LmsCourse::create(['title' => 'Web Dev', 'price' => 20000, 'is_active' => true]));
    }

    private function teacher(string $email = 'teach@acme.test'): LmsTeacher
    {
        return $this->asTenant(fn () => LmsTeacher::create(['name' => 'Teacher', 'email' => $email, 'password' => 'x']));
    }

    // ── 1. Unassigned cohorts ───────────────────────────────────────────────

    public function test_owner_can_create_and_unassign_a_cohort(): void
    {
        $course = $this->course();

        $id = $this->owner()
            ->postJson('/api/frontend/lms/owner/tracks', ['name' => 'No teacher yet', 'course_id' => $course->id, 'instructor_id' => null])
            ->assertCreated()
            ->json('track.id');
        $this->assertNull(LmsTrack::withoutGlobalScope(TenantScope::class)->find($id)->instructor_id);

        $teacher = $this->teacher();
        $this->owner()->putJson("/api/frontend/lms/owner/tracks/{$id}", ['instructor_id' => $teacher->id])->assertOk();
        $this->owner()->putJson("/api/frontend/lms/owner/tracks/{$id}", ['instructor_id' => 0])->assertOk();

        $this->assertNull(LmsTrack::withoutGlobalScope(TenantScope::class)->find($id)->instructor_id);
    }

    public function test_removing_a_teacher_keeps_their_cohort_and_its_students(): void
    {
        $course = $this->course();
        $teacher = $this->teacher();
        [$track, $student] = $this->asTenant(function () use ($course, $teacher) {
            $track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $course->id, 'instructor_id' => $teacher->id]);
            LmsGroupChat::create(['track_id' => $track->id]);
            $student = LmsStudent::create(['first_name' => 'Ada', 'last_name' => 'L', 'email' => 'ada@x.test', 'password' => 'x', 'selected_course_id' => $course->id]);
            LmsEnrollment::create(['student_id' => $student->id, 'track_id' => $track->id]);

            return [$track, $student];
        });

        // No longer refused: the owner is told to reassign instead.
        $this->owner()->deleteJson("/api/frontend/lms/owner/staff/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('cohorts_led', 1);

        // The scheduled deletion completes.
        $this->assertTrue(LmsTeacher::withoutGlobalScope(TenantScope::class)->find($teacher->id)->purge());

        $fresh = LmsTrack::withoutGlobalScope(TenantScope::class)->find($track->id);
        $this->assertNotNull($fresh, 'The cohort must survive its teacher being removed.');
        $this->assertNull($fresh->instructor_id);
        $this->assertTrue(LmsEnrollment::withoutGlobalScope(TenantScope::class)->where('student_id', $student->id)->where('track_id', $track->id)->exists());
        $this->assertTrue(LmsGroupChat::withoutGlobalScope(TenantScope::class)->where('track_id', $track->id)->exists());
    }

    public function test_the_database_itself_unassigns_rather_than_deletes(): void
    {
        $course = $this->course();
        $teacher = $this->teacher();
        $track = $this->asTenant(fn () => LmsTrack::create(['name' => 'Cohort B', 'course_id' => $course->id, 'instructor_id' => $teacher->id]));

        DB::table('lms_teachers')->where('id', $teacher->id)->delete();

        $this->assertNull(DB::table('lms_tracks')->where('id', $track->id)->value('instructor_id'));
        $this->assertTrue(DB::table('lms_tracks')->where('id', $track->id)->exists());
    }

    public function test_students_in_an_unassigned_cohort_can_still_use_chat(): void
    {
        $course = $this->course();
        [$student, $token] = $this->asTenant(function () use ($course) {
            $track = LmsTrack::create(['name' => 'Unassigned', 'course_id' => $course->id]);
            $student = LmsStudent::create(['first_name' => 'Ada', 'last_name' => 'L', 'email' => 'ada@x.test', 'password' => 'x', 'selected_course_id' => $course->id]);
            LmsEnrollment::create(['student_id' => $student->id, 'track_id' => $track->id]);
            $token = Str::random(80);
            LmsSession::create(['role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addDay()]);

            return [$student, $token];
        });

        $student = $this->withHeader('Authorization', 'Bearer ' . $token);

        $student->getJson('/api/frontend/lms/chats/bootstrap')
            ->assertOk()
            ->assertJsonPath('dm_thread', null)
            ->assertJsonPath('track.name', 'Unassigned');

        $student->getJson('/api/frontend/lms/chats/dm/messages')->assertOk()->assertExactJson([]);

        $student->postJson('/api/frontend/lms/chats/dm/messages', ['content' => 'hello?'])
            ->assertStatus(422)
            ->assertJsonPath('no_instructor', true);
    }

    // ── 2. A paid-for course can't be deleted ───────────────────────────────

    private function registration(LmsCourse $course, float $paid): void
    {
        $this->asTenant(function () use ($course, $paid) {
            $registration = TrainingRegistration::create([
                'first_name' => 'Ada', 'last_name' => 'L', 'date_of_birth' => '2000-01-01', 'qualification_level' => 'x',
                'phone_number' => '1', 'email' => Str::random(6) . '@x.test', 'whatsapp' => '1',
                'course_id' => $course->id, 'course_name' => $course->title, 'learning_mode' => 'live',
                'course_price' => $paid, 'status' => 'approved',
            ]);
            Payment::create([
                'registration_id' => $registration->id, 'reference' => 'R-' . Str::random(10),
                'amount' => $paid, 'currency' => 'NGN', 'status' => 'success', 'gateway' => $paid > 0 ? 'paystack' : 'free',
            ]);
        });
    }

    public function test_a_course_students_paid_for_cannot_be_deleted(): void
    {
        $course = $this->course();
        $this->registration($course, 20000);

        $this->owner()->deleteJson("/api/frontend/lms/owner/courses/{$course->id}")
            ->assertStatus(422)
            ->assertJsonPath('delete_blocked', true);

        $this->assertNotNull(LmsCourse::withoutGlobalScope(TenantScope::class)->find($course->id));

        // The Courses list says why, so the button can be greyed out.
        $row = collect($this->owner()->getJson('/api/frontend/lms/owner/courses')->assertOk()->json('courses'))
            ->firstWhere('id', $course->id);
        $this->assertStringContainsString('Unpublish', (string) $row['delete_blocked_reason']);

        // Unpublishing still works.
        $this->owner()->putJson("/api/frontend/lms/owner/courses/{$course->id}", ['is_active' => false])->assertOk();
    }

    public function test_a_course_nobody_paid_for_can_be_deleted(): void
    {
        $empty = $this->course();
        $this->owner()->deleteJson("/api/frontend/lms/owner/courses/{$empty->id}")->assertOk();

        // A comped (₦0) enrolment is not a payment.
        $comped = $this->course();
        $this->registration($comped, 0);
        $this->owner()->deleteJson("/api/frontend/lms/owner/courses/{$comped->id}")->assertOk();
    }
}
