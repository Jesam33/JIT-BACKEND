<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherAvailability;
use App\Models\LmsTeacherNotification;
use App\Models\LmsTeacherTimeOff;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\OneOnOneBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One-on-one self-booking: students book open slots in their teacher's week.
 *
 * Clock: Monday 5 Oct 2026, 06:00 Lagos. The teacher is open Wednesdays
 * 09:00 to 12:00 Lagos, so with 60-minute sessions the 14-day horizon holds
 * six slots: 09/10/11 on Wed 7 Oct and Wed 14 Oct.
 */
class OneOnOneBookingTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Africa/Lagos';

    private Tenant $acme;

    private LmsTeacher $teacher;

    private LmsCourse $course;

    private LmsTrack $track;

    private LmsModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'saas.enforce_tenancy' => true,
            'saas.monthly_reminder_timezone' => self::TZ,
            'saas.one_on_one_booking' => ['min_notice_hours' => 12, 'cancel_window_hours' => 24, 'horizon_days' => 14, 'reminder_minutes' => 60],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 06:00', self::TZ));

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);

        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create([
                'title' => 'Web Dev', 'price' => 30000, 'is_active' => true,
                'is_one_on_one_available' => true, 'one_on_one_price' => 90000,
            ]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id, 'end_date' => '2027-06-30']);
            $this->module = LmsModule::create(['course_id' => $this->course->id, 'title' => 'Module 1', 'status' => 'published', 'sort_order' => 1]);
            LmsTeacherAvailability::create(['teacher_id' => $this->teacher->id, 'weekday' => 3, 'start_time' => '09:00', 'end_time' => '12:00']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function student(string $email, string $mode = 'one_on_one'): LmsStudent
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
        $this->asTenant(fn () => LmsSession::create(['role' => $role, 'user_id' => $userId, 'token' => $token, 'expires_at' => now()->addYear()]));

        return $token;
    }

    private function as(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /** A Lagos wall-clock time, as a UTC Carbon (what the app stores). */
    private function lagos(string $at): Carbon
    {
        return Carbon::parse($at, self::TZ)->utc();
    }

    /** @return array<int, string> slot start times as Lagos "Y-m-d H:i" */
    private function slotTimes(LmsStudent $student): array
    {
        return array_map(
            fn ($s) => $s['starts_at']->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'),
            $this->asTenant(fn () => OneOnOneBooking::openSlots($student->fresh()))
        );
    }

    private function book(string $token, string $lagosTime)
    {
        return $this->as($token)->postJson('/api/frontend/lms/one-on-one/booking', [
            'starts_at' => $this->lagos($lagosTime)->toIso8601String(),
            'module_id' => $this->module->id,
        ]);
    }

    // ── Slots ──────────────────────────────────────────────────────────────

    public function test_slots_come_from_the_teachers_weekly_availability(): void
    {
        $this->assertSame([
            '2026-10-07 09:00', '2026-10-07 10:00', '2026-10-07 11:00',
            '2026-10-14 09:00', '2026-10-14 10:00', '2026-10-14 11:00',
        ], $this->slotTimes($this->student('amy@x.test')));
    }

    public function test_slot_length_follows_the_course_session_length(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_minutes' => 90]));

        $this->assertSame(
            ['2026-10-07 09:00', '2026-10-07 10:30', '2026-10-14 09:00', '2026-10-14 10:30'],
            $this->slotTimes($this->student('amy@x.test'))
        );
    }

    public function test_days_off_and_existing_classes_are_not_offered(): void
    {
        $this->asTenant(function () {
            LmsTeacherTimeOff::create(['teacher_id' => $this->teacher->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14']);
            // A group class the teacher already teaches, 10:30 to 11:30.
            LmsScheduledClass::create([
                'module_id' => $this->module->id, 'teacher_id' => $this->teacher->id, 'title' => 'Group',
                'starts_at' => $this->lagos('2026-10-07 10:30'), 'ends_at' => $this->lagos('2026-10-07 11:30'), 'status' => 'scheduled',
            ]);
        });

        $this->assertSame(['2026-10-07 09:00'], $this->slotTimes($this->student('amy@x.test')));
    }

    public function test_slots_inside_the_minimum_notice_are_hidden(): void
    {
        Carbon::setTestNow($this->lagos('2026-10-07 00:00')); // 09:00 is only 9 hours away

        $this->assertSame(
            ['2026-10-14 09:00', '2026-10-14 10:00', '2026-10-14 11:00'],
            $this->slotTimes($this->student('amy@x.test'))
        );
    }

    public function test_group_students_cannot_open_the_booking_page(): void
    {
        $group = $this->student('gus@x.test', 'live');

        $this->as($this->token('student', $group->id))->getJson('/api/frontend/lms/one-on-one/booking')->assertForbidden();
    }

    // ── Booking ────────────────────────────────────────────────────────────

    public function test_a_student_books_a_slot_and_both_sides_are_told(): void
    {
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);

        $page = $this->as($token)->getJson('/api/frontend/lms/one-on-one/booking')->assertOk();
        $this->assertCount(6, $page->json('slots'));
        $this->assertSame('Mrs Ade', $page->json('teacher_name'));
        $this->assertNull($page->json('blocked_reason'));

        $this->book($token, '2026-10-07 10:00')->assertCreated();

        $session = LmsScheduledClass::withoutGlobalScope(TenantScope::class)->where('student_id', $amy->id)->sole();
        $this->assertSame('student', $session->booked_by);
        $this->assertSame((int) $this->teacher->id, (int) $session->teacher_id);
        $this->assertTrue($session->starts_at->equalTo($this->lagos('2026-10-07 10:00')));
        $this->assertTrue($session->ends_at->equalTo($this->lagos('2026-10-07 11:00')));
        $this->assertSame((int) $this->acme->id, (int) $session->tenant_id);

        $this->assertTrue(LmsTeacherNotification::withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $this->teacher->id)->where('type', 'one_on_one_booked')->exists());
        $this->assertTrue(LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $amy->id)->where('type', 'class_scheduled')->exists());

        // The booked time is gone from the list.
        $this->assertNotContains('2026-10-07 10:00', $this->slotTimes($amy));
    }

    public function test_a_taken_slot_cannot_be_booked_by_another_student(): void
    {
        $amy = $this->student('amy@x.test');
        $ben = $this->student('ben@x.test');

        $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->assertCreated();
        $this->book($this->token('student', $ben->id), '2026-10-07 10:00')
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'That time is no longer available. Please pick another.');

        $this->assertSame(1, LmsScheduledClass::withoutGlobalScope(TenantScope::class)->whereNotNull('student_id')->count());
    }

    public function test_times_that_are_not_open_slots_are_refused(): void
    {
        $token = $this->token('student', $this->student('amy@x.test')->id);

        $this->book($token, '2026-10-07 10:15')->assertStatus(422);   // not on the grid
        $this->book($token, '2026-10-08 10:00')->assertStatus(422);   // Thursday: not available
        $this->book($token, '2026-10-21 10:00')->assertStatus(422);   // beyond the horizon
    }

    public function test_the_module_must_be_from_the_students_course(): void
    {
        $other = $this->asTenant(function () {
            $course = LmsCourse::create(['title' => 'Other', 'price' => 1000, 'is_active' => true]);

            return LmsModule::create(['course_id' => $course->id, 'title' => 'Elsewhere', 'status' => 'published', 'sort_order' => 1]);
        });

        $this->as($this->token('student', $this->student('amy@x.test')->id))
            ->postJson('/api/frontend/lms/one-on-one/booking', [
                'starts_at' => $this->lagos('2026-10-07 10:00')->toIso8601String(),
                'module_id' => $other->id,
            ])->assertStatus(422)->assertJsonValidationErrors('module_id');
    }

    public function test_monthly_session_limit_counts_per_calendar_month(): void
    {
        $this->asTenant(fn () => $this->course->update(['billing_type' => 'monthly', 'one_on_one_session_limit' => 1]));
        // Availability on Saturdays too, so the horizon reaches November (31 Oct is a Saturday).
        Carbon::setTestNow($this->lagos('2026-10-26 06:00'));
        $this->asTenant(fn () => LmsTeacherAvailability::create(['teacher_id' => $this->teacher->id, 'weekday' => 6, 'start_time' => '09:00', 'end_time' => '10:00']));

        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);

        $this->book($token, '2026-10-28 09:00')->assertCreated();

        // October is used up; November slots stay open.
        $times = $this->slotTimes($amy);
        $this->assertEmpty(array_filter($times, fn ($t) => str_starts_with($t, '2026-10')));
        $this->assertNotEmpty(array_filter($times, fn ($t) => str_starts_with($t, '2026-11')));

        $this->book($token, '2026-10-31 09:00')
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', "You've used all 1 sessions for that month.");
        $this->book($token, '2026-11-04 09:00')->assertCreated();

        $this->as($token)->getJson('/api/frontend/lms/one-on-one/booking')
            ->assertJsonPath('allowance.limit', 1)
            ->assertJsonPath('allowance.used', 1)
            ->assertJsonPath('allowance.period', 'month');
    }

    public function test_one_time_course_limit_counts_every_session(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 2]));
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);

        $this->book($token, '2026-10-07 09:00')->assertCreated();
        $this->book($token, '2026-10-14 09:00')->assertCreated();

        $this->assertSame([], $this->slotTimes($amy));
        $this->book($token, '2026-10-14 10:00')->assertStatus(422);
    }

    public function test_the_teacher_can_switch_self_booking_off_for_a_student(): void
    {
        $amy = $this->student('amy@x.test');
        $staff = $this->token('staff', $this->teacher->id);

        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/{$amy->id}/self-booking", ['enabled' => false])
            ->assertOk()->assertJsonPath('self_booking', false);

        $token = $this->token('student', $amy->id);
        $this->as($token)->getJson('/api/frontend/lms/one-on-one/booking')
            ->assertOk()
            ->assertJsonPath('blocked_reason', 'Your teacher schedules your sessions for you.')
            ->assertJsonCount(0, 'slots');
        $this->book($token, '2026-10-07 10:00')->assertStatus(422);

        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/{$amy->id}/self-booking", ['enabled' => true])->assertOk();
        $this->book($token, '2026-10-07 10:00')->assertCreated();
    }

    public function test_students_without_a_teacher_cannot_book(): void
    {
        $this->asTenant(fn () => $this->track->update(['instructor_id' => null]));
        $amy = $this->student('amy@x.test');
        $this->assertNull($amy->one_on_one_teacher_id);

        $this->as($this->token('student', $amy->id))->getJson('/api/frontend/lms/one-on-one/booking')
            ->assertOk()
            ->assertJsonPath('blocked_reason', 'Your academy is still assigning your teacher. You can book once they have.');
    }

    // ── Cancelling ─────────────────────────────────────────────────────────

    public function test_a_student_can_cancel_outside_the_window_and_the_teacher_is_told(): void
    {
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);
        $id = $this->book($token, '2026-10-14 10:00')->assertCreated()->json('session.id');

        $this->as($token)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/cancel")->assertOk();

        $this->assertSame('cancelled', LmsScheduledClass::withoutGlobalScope(TenantScope::class)->find($id)->status);
        $this->assertTrue(LmsTeacherNotification::withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $this->teacher->id)->where('type', 'one_on_one_cancelled')->exists());
        $this->assertContains('2026-10-14 10:00', $this->slotTimes($amy));
    }

    public function test_a_student_cannot_cancel_inside_the_window(): void
    {
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);
        $id = $this->book($token, '2026-10-07 10:00')->assertCreated()->json('session.id');

        Carbon::setTestNow($this->lagos('2026-10-06 18:00')); // 16 hours before

        $this->as($token)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'Sessions can only be cancelled up to 24 hours before. Message your teacher instead.');
        $this->assertSame('scheduled', LmsScheduledClass::withoutGlobalScope(TenantScope::class)->find($id)->status);
    }

    public function test_a_student_cannot_cancel_someone_elses_session(): void
    {
        $amy = $this->student('amy@x.test');
        $ben = $this->student('ben@x.test');
        $id = $this->book($this->token('student', $amy->id), '2026-10-14 10:00')->json('session.id');

        $this->as($this->token('student', $ben->id))->postJson("/api/frontend/lms/one-on-one/booking/{$id}/cancel")->assertNotFound();
    }

    // ── Teacher side ───────────────────────────────────────────────────────

    public function test_the_teacher_saves_and_reads_back_their_availability(): void
    {
        $staff = $this->token('staff', $this->teacher->id);

        $this->as($staff)->putJson('/api/frontend/lms/staff/one-on-one/availability', ['windows' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '14:00', 'end_time' => '16:00'],
        ]])->assertOk()
            ->assertJsonCount(2, 'windows')
            ->assertJsonPath('windows.1.start_time', '14:00')
            ->assertJsonPath('timezone', self::TZ);

        // Replaces, not appends: the Wednesday window from setUp is gone.
        $this->assertSame(0, LmsTeacherAvailability::withoutGlobalScope(TenantScope::class)->where('weekday', 3)->count());

        $this->as($staff)->putJson('/api/frontend/lms/staff/one-on-one/availability', ['windows' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '13:00'],
        ]])->assertStatus(422);

        $this->as($staff)->postJson('/api/frontend/lms/staff/one-on-one/time-off', ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-13', 'note' => 'Travel'])
            ->assertCreated()->assertJsonPath('time_off.0.note', 'Travel');
    }

    public function test_the_teacher_cannot_double_book_themselves(): void
    {
        $amy = $this->student('amy@x.test');
        $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->assertCreated();

        $this->as($this->token('staff', $this->teacher->id))
            ->postJson("/api/frontend/lms/staff/one-on-one/{$amy->id}/sessions", [
                'module_id' => $this->module->id,
                'starts_at' => $this->lagos('2026-10-07 10:30')->toIso8601String(),
                'ends_at' => $this->lagos('2026-10-07 11:30')->toIso8601String(),
            ])->assertStatus(422)->assertJsonPath('message', 'You already have a class or session at that time.');
    }

    // ── Reminders ──────────────────────────────────────────────────────────

    public function test_both_sides_get_one_reminder_before_the_session(): void
    {
        $amy = $this->student('amy@x.test');
        $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->assertCreated();

        Carbon::setTestNow($this->lagos('2026-10-07 08:30')); // 90 min before: too early
        $this->artisan('lms:send-one-on-one-reminders')->assertSuccessful();
        $this->assertSame(0, LmsNotification::withoutGlobalScope(TenantScope::class)->where('type', 'class_reminder')->count());

        Carbon::setTestNow($this->lagos('2026-10-07 09:15'));
        $this->artisan('lms:send-one-on-one-reminders')->assertSuccessful();
        $this->artisan('lms:send-one-on-one-reminders')->assertSuccessful();

        $this->assertSame(1, LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $amy->id)->where('type', 'class_reminder')->count());
        $this->assertSame(1, LmsTeacherNotification::withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $this->teacher->id)->where('type', 'class_reminder')->count());
    }
}
