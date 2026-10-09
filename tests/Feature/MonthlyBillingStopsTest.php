<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsNotification;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\CourseBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Monthly billing must stop whenever there is no longer a reason to charge:
 * course deleted, academy closed, student suspended/deleted, owner stops it,
 * course switched to one-time, cohort over. And a monthly course's cohorts
 * must always have an end date, so billing can never run forever.
 */
class MonthlyBillingStopsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);
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

    /** The academy's one instructor (lms_tracks.instructor_id is required). */
    private function instructorId(): int
    {
        return $this->asTenant(fn () => (int) LmsTeacher::firstOrCreate(
            ['email' => 'teach@acme.test'],
            ['name' => 'Teacher', 'password' => 'x']
        )->id);
    }

    /** A monthly course with one dated cohort. */
    private function monthlyCourse(string $title = 'Coding Club'): LmsCourse
    {
        $instructorId = $this->instructorId();

        return $this->asTenant(function () use ($title, $instructorId) {
            $course = LmsCourse::create(['title' => $title, 'price' => 15000, 'billing_type' => 'monthly', 'is_active' => true]);
            LmsTrack::create([
                'name' => $title . ' cohort', 'course_id' => $course->id, 'instructor_id' => $instructorId,
                'end_date' => now()->addMonths(6)->toDateString(),
            ]);

            return $course;
        });
    }

    /** A student paying monthly for $course, paid up for another two weeks. */
    private function monthlyStudent(LmsCourse $course, string $email = 'ada@learner.test'): array
    {
        return $this->asTenant(function () use ($course, $email) {
            $registration = TrainingRegistration::create([
                'first_name' => 'Ada', 'last_name' => 'Learner', 'date_of_birth' => '2000-01-01',
                'qualification_level' => 'SSCE', 'phone_number' => '0800', 'email' => $email, 'whatsapp' => '0800',
                'course_id' => $course->id, 'course_name' => $course->title, 'learning_mode' => 'live',
                'course_price' => 15000, 'status' => 'approved',
            ]);
            $registration->forceFill(['billing_status' => 'active', 'paid_until' => now()->addDays(14)])->save();

            $student = LmsStudent::create([
                'first_name' => 'Ada', 'last_name' => 'Learner', 'email' => $email, 'password' => 'x',
                'selected_course_id' => $course->id, 'training_registration_id' => $registration->id,
            ]);
            LmsEnrollment::create(['student_id' => $student->id, 'track_id' => LmsTrack::where('course_id', $course->id)->value('id')]);

            return [$student, $registration];
        });
    }

    private function ownerToken(): string
    {
        $user = User::create([
            'first_name' => 'Owner', 'last_name' => 'One', 'username' => 'owner@acme.test',
            'email' => 'owner@acme.test', 'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert(['tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'owner']);

        $token = Str::random(80);
        $this->asTenant(fn () => LmsSession::create([
            'role' => 'owner', 'user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay(),
        ]));

        return $token;
    }

    private function registration(TrainingRegistration $r): TrainingRegistration
    {
        return TrainingRegistration::withoutGlobalScope(TenantScope::class)->findOrFail($r->id);
    }

    private function studentWasTold(LmsStudent $student, string $contains): bool
    {
        return LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->where('type', 'billing_ended')
            ->where('body', 'like', '%' . $contains . '%')
            ->exists();
    }

    // ── 1. Automatic stops ──────────────────────────────────────────────────

    public function test_deleting_the_course_stops_billing_and_tells_the_student(): void
    {
        $course = $this->monthlyCourse();
        [$student, $registration] = $this->monthlyStudent($course);

        $this->asTenant(fn () => $course->delete());

        $fresh = $this->registration($registration);
        $this->assertSame('ended', $fresh->billing_status);
        $this->assertSame(CourseBilling::END_COURSE_DELETED, $fresh->billing_end_reason);
        $this->assertTrue($this->studentWasTold($student, 'no longer offered'));
    }

    public function test_suspending_a_student_stops_billing_and_reactivating_resumes_it(): void
    {
        [$student, $registration] = $this->monthlyStudent($this->monthlyCourse());

        $student->deactivate();
        $this->assertSame('ended', $this->registration($registration)->billing_status);
        $this->assertSame(CourseBilling::END_STUDENT_INACTIVE, $this->registration($registration)->billing_end_reason);

        // Paid month still running → back to normal billing.
        $student->reactivate();
        $this->assertSame('active', $this->registration($registration)->billing_status);
    }

    public function test_a_student_reactivated_after_their_month_ran_out_pays_before_access_returns(): void
    {
        [$student, $registration] = $this->monthlyStudent($this->monthlyCourse());

        $student->deactivate();
        $this->registration($registration)->forceFill(['paid_until' => now()->subDays(10)])->save();
        $student->reactivate();

        $fresh = $this->registration($registration);
        // Cancelled, not active: no surprise card charge, and access stays
        // paused until they pay themselves.
        $this->assertSame('cancelled', $fresh->billing_status);
        $this->assertTrue(CourseBilling::isLocked($fresh));
    }

    public function test_deleting_a_student_stops_billing(): void
    {
        [$student, $registration] = $this->monthlyStudent($this->monthlyCourse());

        $student->schedulePurge();

        $this->assertSame('ended', $this->registration($registration)->billing_status);
    }

    public function test_the_sweep_stops_billing_when_the_academy_closes(): void
    {
        [, $registration] = $this->monthlyStudent($this->monthlyCourse());

        $this->acme->forceFill(['purge_after' => now()->addDays(30)])->save();
        Artisan::call('lms:process-course-renewals');

        $fresh = $this->registration($registration);
        $this->assertSame('ended', $fresh->billing_status);
        $this->assertSame(CourseBilling::END_ACADEMY_CLOSED, $fresh->billing_end_reason);
    }

    public function test_the_sweep_catches_a_course_removed_without_the_model(): void
    {
        // e.g. deleted straight in the database: the foreign key nulls course_id.
        $course = $this->monthlyCourse();
        [, $registration] = $this->monthlyStudent($course);
        DB::table('training_registrations')->where('id', $registration->id)->update(['course_id' => null]);

        Artisan::call('lms:process-course-renewals');

        $this->assertSame(CourseBilling::END_COURSE_DELETED, $this->registration($registration)->billing_end_reason);
    }

    // ── 2. Owner controls ───────────────────────────────────────────────────

    public function test_owner_can_stop_monthly_billing_for_a_whole_course(): void
    {
        $course = $this->monthlyCourse();
        [$ada, $adaReg] = $this->monthlyStudent($course, 'ada@learner.test');
        [, $bobReg] = $this->monthlyStudent($course, 'bob@learner.test');

        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken())
            ->postJson("/api/frontend/lms/owner/courses/{$course->id}/stop-monthly-billing")
            ->assertOk()
            ->assertJsonPath('stopped', 2)
            ->assertJsonPath('course.monthly_billed_count', 0);

        foreach ([$adaReg, $bobReg] as $r) {
            $fresh = $this->registration($r);
            $this->assertSame('ended', $fresh->billing_status);
            $this->assertSame(CourseBilling::END_STOPPED_BY_ACADEMY, $fresh->billing_end_reason);
            $this->assertFalse(CourseBilling::isLocked($fresh->forceFill(['paid_until' => now()->subYear()])));
        }
        $this->assertTrue($this->studentWasTold($ada, 'stopped monthly billing'));
    }

    public function test_owner_can_stop_monthly_billing_for_one_student(): void
    {
        $course = $this->monthlyCourse();
        [$ada, $adaReg] = $this->monthlyStudent($course, 'ada@learner.test');
        [, $bobReg] = $this->monthlyStudent($course, 'bob@learner.test');

        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken())
            ->postJson("/api/frontend/lms/owner/students/{$ada->id}/stop-monthly-billing")
            ->assertOk();

        $this->assertSame('ended', $this->registration($adaReg)->billing_status);
        $this->assertSame('active', $this->registration($bobReg)->billing_status);
    }

    public function test_switching_to_one_time_stops_existing_billing_only_when_asked(): void
    {
        $token = $this->ownerToken();

        $kept = $this->monthlyCourse('Kept');
        [, $keptReg] = $this->monthlyStudent($kept, 'ada@learner.test');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/frontend/lms/owner/courses/{$kept->id}", ['billing_type' => 'one_time'])
            ->assertOk()->assertJsonPath('billing_stopped', 0);
        $this->assertSame('active', $this->registration($keptReg)->billing_status);

        $stopped = $this->monthlyCourse('Stopped');
        [, $stoppedReg] = $this->monthlyStudent($stopped, 'bob@learner.test');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/frontend/lms/owner/courses/{$stopped->id}", ['billing_type' => 'one_time', 'stop_existing_billing' => true])
            ->assertOk()->assertJsonPath('billing_stopped', 1);
        $this->assertSame('ended', $this->registration($stoppedReg)->billing_status);
    }

    // ── 3. Monthly cohorts need an end date ─────────────────────────────────

    public function test_a_monthly_courses_cohort_needs_an_end_date(): void
    {
        $course = $this->monthlyCourse();
        $token = $this->ownerToken();
        $instructorId = $this->instructorId();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/tracks', ['name' => 'Open ended', 'course_id' => $course->id, 'instructor_id' => $instructorId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/tracks', [
                'name' => 'Dated', 'course_id' => $course->id, 'instructor_id' => $instructorId,
                'end_date' => now()->addMonths(3)->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_a_course_cannot_become_monthly_while_a_cohort_has_no_end_date(): void
    {
        $instructorId = $this->instructorId();
        $course = $this->asTenant(function () use ($instructorId) {
            $c = LmsCourse::create(['title' => 'One-off', 'price' => 15000, 'is_active' => true]);
            LmsTrack::create(['name' => 'Undated cohort', 'course_id' => $c->id, 'instructor_id' => $instructorId]);

            return $c;
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken())
            ->putJson("/api/frontend/lms/owner/courses/{$course->id}", ['billing_type' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_type']);

        $this->assertSame('one_time', LmsCourse::withoutGlobalScope(TenantScope::class)->find($course->id)->billing_type);
    }

    public function test_one_time_courses_keep_optional_cohort_dates(): void
    {
        $instructorId = $this->instructorId();
        $track = $this->asTenant(function () use ($instructorId) {
            $c = LmsCourse::create(['title' => 'One-off', 'price' => 15000, 'is_active' => true]);

            return LmsTrack::create(['name' => 'No dates', 'course_id' => $c->id, 'instructor_id' => $instructorId]);
        });

        $this->assertNull($track->end_date);
    }
}
