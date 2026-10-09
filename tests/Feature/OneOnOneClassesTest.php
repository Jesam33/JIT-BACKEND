<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsOwnerNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherNotification;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One-on-one classes: a third way to take a course. Private sessions with an
 * assigned teacher, never visible to (or joinable by) anyone else.
 */
class OneOnOneClassesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private LmsTeacher $teacher;

    private LmsCourse $course;

    private LmsTrack $track;

    private LmsModule $module1;

    private LmsModule $module2;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['saas.enforce_tenancy' => true]);

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
            'settings' => ['paystack' => ['subaccount_code' => 'ACCT_acme']],
        ]);

        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create([
                'title' => 'Web Dev', 'price' => 30000, 'is_active' => true,
                'is_one_on_one_available' => true, 'one_on_one_price' => 90000,
            ]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id]);
            $this->module1 = LmsModule::create(['course_id' => $this->course->id, 'title' => 'Module 1', 'status' => 'published', 'sort_order' => 1]);
            $this->module2 = LmsModule::create(['course_id' => $this->course->id, 'title' => 'Module 2', 'status' => 'published', 'sort_order' => 2]);
        });
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

    private function student(string $email, string $mode): LmsStudent
    {
        return $this->asTenant(function () use ($email, $mode) {
            $s = LmsStudent::create([
                'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'L', 'email' => $email, 'password' => 'x',
                'selected_course_id' => $this->course->id, 'learning_mode' => $mode,
            ]);
            LmsEnrollment::create(['student_id' => $s->id, 'track_id' => $this->track->id]);

            return $s->fresh();
        });
    }

    private function token(string $role, int $userId): string
    {
        $token = Str::random(80);
        $this->asTenant(fn () => LmsSession::create(['role' => $role, 'user_id' => $userId, 'token' => $token, 'expires_at' => now()->addDay()]));

        return $token;
    }

    private function as(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function groupClass(LmsModule $module, string $when = '+1 day'): LmsScheduledClass
    {
        return $this->asTenant(fn () => LmsScheduledClass::create([
            'module_id' => $module->id, 'teacher_id' => $this->teacher->id, 'title' => 'Group: ' . $module->title,
            'starts_at' => now()->modify($when), 'ends_at' => now()->modify($when)->addHour(), 'status' => 'scheduled',
        ]));
    }

    private function privateSession(LmsStudent $student, LmsModule $module, string $when = '+1 day'): LmsScheduledClass
    {
        return $this->asTenant(fn () => LmsScheduledClass::create([
            'module_id' => $module->id, 'teacher_id' => $this->teacher->id, 'student_id' => $student->id,
            'title' => 'Private: ' . $student->first_name, 'starts_at' => now()->modify($when),
            'ends_at' => now()->modify($when)->addHour(), 'status' => 'scheduled',
        ]));
    }

    // ── Buying it ──────────────────────────────────────────────────────────

    private function register(string $mode)
    {
        return $this->postJson('/api/frontend/training/register', [
            'first_name' => 'Ada', 'last_name' => 'L', 'date_of_birth' => '2000-01-01', 'qualification_level' => 'SSCE',
            'phone_number' => '0800', 'email' => 'buyer@x.test', 'whatsapp' => '0800',
            'course_id' => $this->course->id, 'learning_mode' => $mode, 'institute_slug' => 'acme',
        ]);
    }

    public function test_one_on_one_is_sold_at_its_own_price(): void
    {
        $id = $this->register('one_on_one')->assertCreated()->json('registration_id');

        $registration = TrainingRegistration::withoutGlobalScope(TenantScope::class)->find($id);
        $this->assertSame('one_on_one', $registration->learning_mode);
        $this->assertEquals(90000.0, (float) $registration->course_price);
    }

    public function test_one_on_one_is_refused_when_the_course_or_plan_does_not_offer_it(): void
    {
        $this->asTenant(fn () => $this->course->update(['is_one_on_one_available' => false]));
        $this->register('one_on_one')->assertStatus(422)->assertJsonPath('one_on_one_unavailable', true);

        $this->asTenant(fn () => $this->course->update(['is_one_on_one_available' => true]));
        $this->acme->update(['plan' => 'free']);
        $this->register('one_on_one')->assertStatus(422)->assertJsonPath('one_on_one_unavailable', true);
    }

    // ── Teacher assignment ─────────────────────────────────────────────────

    public function test_a_new_one_on_one_student_gets_the_cohort_instructor_as_teacher(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');

        $this->assertSame($this->teacher->id, (int) $ada->one_on_one_teacher_id);
        $this->assertTrue(LmsTeacherNotification::withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $this->teacher->id)->where('type', 'one_on_one_assigned')->exists());
    }

    public function test_the_owner_is_told_when_no_teacher_is_available(): void
    {
        $this->asTenant(fn () => $this->track->update(['instructor_id' => null]));

        $ada = $this->student('ada@x.test', 'one_on_one');

        $this->assertNull($ada->one_on_one_teacher_id);
        $this->assertTrue(LmsOwnerNotification::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->acme->id)->where('type', 'one_on_one_needs_teacher')->exists());
    }

    public function test_the_owner_can_change_a_students_teacher(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $other = $this->asTenant(fn () => LmsTeacher::create(['name' => 'Mr Bello', 'email' => 'bello@acme.test', 'password' => 'x']));

        $user = User::create(['first_name' => 'O', 'last_name' => 'W', 'username' => 'o@acme.test', 'email' => 'o@acme.test', 'password' => Hash::make('secret123')]);
        DB::table('tenant_admins')->insert(['tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->as($this->token('owner', $user->id))
            ->postJson("/api/frontend/lms/owner/students/{$ada->id}/one-on-one-teacher", ['teacher_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('one_on_one.teacher_name', 'Mr Bello');

        $this->assertSame($other->id, (int) $ada->fresh()->one_on_one_teacher_id);
    }

    // ── Privacy: who sees and joins what ───────────────────────────────────

    public function test_private_sessions_are_seen_only_by_their_student(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $tola = $this->student('tola@x.test', 'one_on_one');
        $gina = $this->student('gina@x.test', 'live');

        $group = $this->groupClass($this->module1);
        $adasSession = $this->privateSession($ada, $this->module1);

        $ids = fn (string $token) => collect($this->as($token)->getJson('/api/frontend/lms/timetable')->assertOk()->json())
            ->where('class_type', 'scheduled')->pluck('id')->all();

        $this->assertSame([$adasSession->id], $ids($this->token('student', $ada->id)), 'Ada sees only her own session.');
        $this->assertSame([], $ids($this->token('student', $tola->id)), "Tola never sees Ada's session.");
        $this->assertSame([$group->id], $ids($this->token('student', $gina->id)), 'Group students see only group classes.');
    }

    public function test_nobody_can_join_a_class_that_is_not_theirs(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $gina = $this->student('gina@x.test', 'live');
        $group = $this->groupClass($this->module1);
        $adasSession = $this->privateSession($ada, $this->module1);

        $join = fn (string $token, int $id) => $this->as($token)
            ->postJson("/api/frontend/lms/classrooms/{$id}/sdk-signature", ['class_type' => 'scheduled']);

        $ginaToken = $this->token('student', $gina->id);
        $adaToken = $this->token('student', $ada->id);

        $join($ginaToken, $adasSession->id)->assertStatus(403);
        $join($adaToken, $group->id)->assertStatus(403);

        // Their own: past the access check (503 = live video keys not set in tests).
        $join($adaToken, $adasSession->id)->assertStatus(503);
        $join($ginaToken, $group->id)->assertStatus(503);
    }

    public function test_a_one_on_one_students_modules_unlock_with_their_own_sessions(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $adaToken = $this->token('student', $ada->id);

        // The group has had Module 1; Ada hasn't. Module 2 stays locked for her.
        $this->groupClass($this->module1, '-2 days');
        $this->as($adaToken)->getJson("/api/frontend/lms/modules/{$this->module2->id}")->assertStatus(403);

        $this->privateSession($ada, $this->module1, '-1 day');
        $this->as($adaToken)->getJson("/api/frontend/lms/modules/{$this->module2->id}")->assertOk();
    }

    // ── The teacher's side ─────────────────────────────────────────────────

    public function test_a_teacher_schedules_moves_and_cancels_sessions_and_the_student_is_told(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $teacherToken = $this->token('staff', $this->teacher->id);

        $this->as($teacherToken)->getJson('/api/frontend/lms/staff/one-on-one')
            ->assertOk()->assertJsonPath('students.0.id', $ada->id);

        $id = $this->as($teacherToken)->postJson("/api/frontend/lms/staff/one-on-one/{$ada->id}/sessions", [
            'module_id' => $this->module1->id,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addHour()->toIso8601String(),
        ])->assertCreated()->json('session.id');

        $this->assertSame($ada->id, (int) LmsScheduledClass::withoutGlobalScope(TenantScope::class)->find($id)->student_id);

        $this->as($teacherToken)->putJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}", [
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'ends_at' => now()->addDays(3)->addHour()->toIso8601String(),
        ])->assertOk();

        $this->as($teacherToken)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}/cancel")
            ->assertOk()->assertJsonPath('session.status', 'cancelled');

        $types = LmsNotification::withoutGlobalScope(TenantScope::class)->where('student_id', $ada->id)->pluck('type')->all();
        $this->assertContains('class_scheduled', $types);
        $this->assertContains('class_rescheduled', $types);
        $this->assertContains('class_cancelled', $types);
    }

    public function test_another_teacher_cannot_touch_someone_elses_student(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $other = $this->asTenant(fn () => LmsTeacher::create(['name' => 'Mr Bello', 'email' => 'bello@acme.test', 'password' => 'x']));
        $otherToken = $this->token('staff', $other->id);

        $this->as($otherToken)->getJson('/api/frontend/lms/staff/one-on-one')->assertOk()->assertJsonCount(0, 'students');
        $this->as($otherToken)->postJson("/api/frontend/lms/staff/one-on-one/{$ada->id}/sessions", [
            'module_id' => $this->module1->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertNotFound();
    }

    public function test_a_session_must_use_a_module_of_the_students_course(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $foreign = $this->asTenant(function () {
            $c = LmsCourse::create(['title' => 'Other', 'price' => 1000, 'is_active' => true]);

            return LmsModule::create(['course_id' => $c->id, 'title' => 'Elsewhere', 'status' => 'published', 'sort_order' => 1]);
        });

        $this->as($this->token('staff', $this->teacher->id))->postJson("/api/frontend/lms/staff/one-on-one/{$ada->id}/sessions", [
            'module_id' => $foreign->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_group_class_announcements_skip_one_on_one_students(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $gina = $this->student('gina@x.test', 'live');

        $this->as($this->token('staff', $this->teacher->id))->postJson("/api/frontend/lms/staff/modules/{$this->module1->id}/schedule", [
            'title' => 'Group class', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertCreated();

        $told = fn (LmsStudent $s) => LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $s->id)->where('type', 'class_scheduled')->exists();
        $this->assertTrue($told($gina));
        $this->assertFalse($told($ada));
    }

    // ── Chat ───────────────────────────────────────────────────────────────

    public function test_one_on_one_students_chat_privately_with_their_teacher_only(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $adaToken = $this->token('student', $ada->id);

        $this->as($adaToken)->getJson('/api/frontend/lms/chats/bootstrap')
            ->assertOk()
            ->assertJsonPath('one_on_one', true)
            ->assertJsonPath('group_chat', null)
            ->assertJsonPath('dm_thread.instructor_id', $this->teacher->id);

        $this->as($adaToken)->getJson('/api/frontend/lms/chats/group/messages')->assertStatus(403);
    }

    // ── Teacher leaves ─────────────────────────────────────────────────────

    public function test_when_a_teacher_leaves_sessions_are_cancelled_and_the_owner_is_told(): void
    {
        $ada = $this->student('ada@x.test', 'one_on_one');
        $future = $this->privateSession($ada, $this->module1, '+3 days');
        $past = $this->privateSession($ada, $this->module1, '-3 days');

        $this->assertTrue($this->teacher->purge());

        $this->assertSame('cancelled', LmsScheduledClass::withoutGlobalScope(TenantScope::class)->find($future->id)->status);
        $this->assertSame('scheduled', LmsScheduledClass::withoutGlobalScope(TenantScope::class)->find($past->id)->status, 'History is left alone.');
        $this->assertNull($ada->fresh()->one_on_one_teacher_id);
        $this->assertTrue(LmsOwnerNotification::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->acme->id)->where('type', 'one_on_one_needs_teacher')->exists());
        $this->assertTrue(LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $ada->id)->where('type', 'class_cancelled')->exists());
    }

    // ── Signup can't change the mode that was paid for ──────────────────────

    public function test_signup_keeps_a_paid_one_on_one_mode(): void
    {
        $registration = $this->asTenant(fn () => TrainingRegistration::create([
            'first_name' => 'Ada', 'last_name' => 'L', 'date_of_birth' => '2000-01-01', 'qualification_level' => 'x',
            'phone_number' => '1', 'email' => 'paid@x.test', 'whatsapp' => '1', 'course_id' => $this->course->id,
            'course_name' => 'Web Dev', 'learning_mode' => 'one_on_one', 'course_price' => 90000,
            'status' => 'approved', 'invite_token' => 'tok-' . Str::random(20),
        ]));

        // The invite signup page only knows live / pre-recorded and sends "live".
        $this->postJson('/api/frontend/lms/signup', [
            'token' => $registration->invite_token, 'password' => 'password123', 'learning_mode' => 'live',
        ])->assertOk();

        $this->assertSame('one_on_one', LmsStudent::withoutGlobalScope(TenantScope::class)->where('email', 'paid@x.test')->value('learning_mode'));
    }
}
