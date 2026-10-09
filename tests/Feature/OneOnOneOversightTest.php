<?php

namespace Tests\Feature;

use App\Mail\LmsNotificationMail;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsOneOnOneCredit;
use App\Models\LmsOwnerNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherAvailability;
use App\Models\LmsTeacherTimeOff;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\OneOnOneBooking;
use App\Services\OneOnOneExtras;
use App\Services\PaystackService;
use App\Support\CalendarInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One-on-one, round 3: repeat weekly booking, session outcomes and no-show
 * reports, the owner overview, paid extra sessions, calendar invites.
 *
 * Clock: Monday 5 Oct 2026, 06:00 Lagos. The teacher is open Wednesdays
 * 09:00 to 12:00 Lagos (60-minute sessions).
 */
class OneOnOneOversightTest extends TestCase
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
            'saas.one_on_one_booking' => [
                'min_notice_hours' => 12, 'cancel_window_hours' => 24, 'horizon_days' => 14,
                'reminder_minutes' => 60, 'repeat_max_weeks' => 8, 'no_show_report_days' => 7,
            ],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 06:00', self::TZ));

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

    private function student(string $email): LmsStudent
    {
        return $this->asTenant(function () use ($email) {
            $registration = TrainingRegistration::create([
                'first_name' => 'Amy', 'last_name' => 'L', 'date_of_birth' => '2000-01-01',
                'qualification_level' => 'SSCE', 'phone_number' => '0800', 'email' => $email, 'whatsapp' => '0800',
                'course_id' => $this->course->id, 'course_name' => $this->course->title, 'learning_mode' => 'one_on_one',
                'course_price' => 90000, 'status' => 'approved',
            ]);
            $s = LmsStudent::create([
                'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'L', 'email' => $email, 'password' => 'x',
                'selected_course_id' => $this->course->id, 'learning_mode' => 'one_on_one',
                'training_registration_id' => $registration->id,
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

    private function ownerToken(): string
    {
        $user = User::create([
            'first_name' => 'Owner', 'last_name' => 'One', 'username' => 'owner@acme.test',
            'email' => 'owner@acme.test', 'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert(['tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $this->token('owner', $user->id);
    }

    private function as(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function lagos(string $at): Carbon
    {
        return Carbon::parse($at, self::TZ)->utc();
    }

    private function book(string $token, string $lagosTime, int $weeks = 1)
    {
        return $this->as($token)->postJson('/api/frontend/lms/one-on-one/booking', [
            'starts_at' => $this->lagos($lagosTime)->toIso8601String(),
            'module_id' => $this->module->id,
            'repeat_weeks' => $weeks,
        ]);
    }

    private function sessions(LmsStudent $student)
    {
        return LmsScheduledClass::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)->orderBy('starts_at')->get();
    }

    // ── Repeat weekly booking ──────────────────────────────────────────────

    public function test_a_student_books_the_same_time_every_week(): void
    {
        $amy = $this->student('amy@x.test');

        $response = $this->book($this->token('student', $amy->id), '2026-10-07 10:00', 4)->assertCreated();
        $this->assertCount(4, $response->json('booked'));
        $this->assertCount(0, $response->json('skipped'));

        $sessions = $this->sessions($amy);
        $this->assertSame(
            ['2026-10-07 10:00', '2026-10-14 10:00', '2026-10-21 10:00', '2026-10-28 10:00'],
            $sessions->map(fn ($s) => $s->starts_at->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'))->all()
        );
        $this->assertCount(1, $sessions->pluck('series_id')->unique()->filter());

        // One combined message each, not four.
        $this->assertSame(1, LmsNotification::withoutGlobalScope(TenantScope::class)->where('type', 'one_on_one_series')->count());
    }

    public function test_weeks_that_are_not_free_are_skipped_and_reported(): void
    {
        $this->asTenant(fn () => LmsTeacherTimeOff::create(['teacher_id' => $this->teacher->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14']));
        $amy = $this->student('amy@x.test');

        $response = $this->book($this->token('student', $amy->id), '2026-10-07 10:00', 3)->assertCreated();

        $this->assertCount(2, $response->json('booked'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertTrue(Carbon::parse($response->json('skipped.0.starts_at'))->equalTo($this->lagos('2026-10-14 10:00')));
    }

    public function test_repeat_booking_stops_at_the_session_limit(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 2]));
        $amy = $this->student('amy@x.test');

        $response = $this->book($this->token('student', $amy->id), '2026-10-07 10:00', 3)->assertCreated();

        $this->assertCount(2, $response->json('booked'));
        $this->assertSame("You've used all 2 sessions included in your course.", $response->json('skipped.0.reason'));
    }

    public function test_repeat_weeks_are_capped(): void
    {
        $amy = $this->student('amy@x.test');

        $this->book($this->token('student', $amy->id), '2026-10-07 10:00', 9)
            ->assertStatus(422)->assertJsonValidationErrors('repeat_weeks');
    }

    // ── Outcomes and no-shows ──────────────────────────────────────────────

    public function test_a_teacher_no_show_gives_the_session_back(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 1]));
        $amy = $this->student('amy@x.test');
        $id = $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->assertCreated()->json('session.id');
        $staff = $this->token('staff', $this->teacher->id);

        // Not before it starts.
        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}/outcome", ['outcome' => 'teacher_no_show'])->assertStatus(422);

        Carbon::setTestNow($this->lagos('2026-10-07 11:30'));
        $this->assertSame([], OneOnOneBooking::openSlots($amy->fresh()));

        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}/outcome", ['outcome' => 'teacher_no_show'])
            ->assertOk()->assertJsonPath('session.outcome', 'teacher_no_show');

        // The allowance is free again, the student and owner are told.
        $this->assertNotEmpty(OneOnOneBooking::openSlots($amy->fresh()));
        $this->assertSame(0, OneOnOneBooking::allowance($amy->fresh())['used']);
        $this->assertTrue(LmsNotification::withoutGlobalScope(TenantScope::class)->where('student_id', $amy->id)->where('type', 'one_on_one_outcome')->exists());
        $this->assertTrue(LmsOwnerNotification::withoutGlobalScope(TenantScope::class)->where('type', 'one_on_one_teacher_no_show')->exists());
    }

    public function test_a_student_no_show_still_uses_the_session(): void
    {
        $amy = $this->student('amy@x.test');
        $id = $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->json('session.id');

        Carbon::setTestNow($this->lagos('2026-10-07 11:30'));
        $this->as($this->token('staff', $this->teacher->id))
            ->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}/outcome", ['outcome' => 'student_no_show'])->assertOk();

        $this->assertSame(1, OneOnOneBooking::allowance($amy->fresh())['used']);
    }

    public function test_a_student_reports_a_missed_session_and_the_owner_decides(): void
    {
        $amy = $this->student('amy@x.test');
        $studentToken = $this->token('student', $amy->id);
        $id = $this->book($studentToken, '2026-10-07 10:00')->json('session.id');
        $owner = $this->ownerToken();
        $staff = $this->token('staff', $this->teacher->id);

        // Can't report before it starts.
        $this->as($studentToken)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/report-no-show")->assertStatus(422);

        Carbon::setTestNow($this->lagos('2026-10-07 12:00'));
        $this->as($studentToken)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/report-no-show")->assertOk();
        $this->as($studentToken)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/report-no-show")->assertStatus(422);

        $this->assertTrue(LmsOwnerNotification::withoutGlobalScope(TenantScope::class)->where('type', 'one_on_one_no_show_report')->exists());
        $this->as($owner)->getJson('/api/frontend/lms/owner/one-on-one?month=2026-10')
            ->assertOk()->assertJsonPath('totals.reports_pending', 1)->assertJsonPath('sessions.0.reported', true);

        $this->as($owner)->postJson("/api/frontend/lms/owner/one-on-one/sessions/{$id}/outcome", ['outcome' => 'teacher_no_show'])->assertOk();

        $this->as($owner)->getJson('/api/frontend/lms/owner/one-on-one?month=2026-10')
            ->assertJsonPath('totals.reports_pending', 0)
            ->assertJsonPath('totals.teacher_no_shows', 1);

        // The teacher can't overturn the academy's decision.
        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$id}/outcome", ['outcome' => 'held'])
            ->assertStatus(422)->assertJsonPath('errors.outcome.0', 'The academy has already decided this one.');
    }

    public function test_reports_close_after_the_report_window(): void
    {
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);
        $id = $this->book($token, '2026-10-07 10:00')->json('session.id');

        Carbon::setTestNow($this->lagos('2026-10-15 12:00'));
        $this->as($token)->postJson("/api/frontend/lms/one-on-one/booking/{$id}/report-no-show")
            ->assertStatus(422)->assertJsonPath('errors.session.0', 'Reports can be made up to 7 days after a session.');
    }

    // ── Owner overview ─────────────────────────────────────────────────────

    public function test_the_owner_sees_sessions_per_teacher_and_student(): void
    {
        $amy = $this->student('amy@x.test');
        $ben = $this->student('ben@x.test');
        $amyToken = $this->token('student', $amy->id);
        $a1 = $this->book($amyToken, '2026-10-07 09:00')->json('session.id');
        $a2 = $this->book($amyToken, '2026-10-07 10:00')->json('session.id');
        $b1 = $this->book($this->token('student', $ben->id), '2026-10-14 09:00')->json('session.id');

        Carbon::setTestNow($this->lagos('2026-10-07 12:00'));
        $staff = $this->token('staff', $this->teacher->id);
        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$a1}/outcome", ['outcome' => 'held'])->assertOk();
        $this->as($staff)->postJson("/api/frontend/lms/staff/one-on-one/sessions/{$a2}/outcome", ['outcome' => 'student_no_show'])->assertOk();

        $response = $this->as($this->ownerToken())->getJson('/api/frontend/lms/owner/one-on-one?month=2026-10')->assertOk();

        $response->assertJsonPath('month_label', 'October 2026')
            ->assertJsonPath('totals.sessions', 3)
            ->assertJsonPath('totals.held', 1)
            ->assertJsonPath('totals.student_no_shows', 1)
            ->assertJsonPath('totals.upcoming', 1)
            ->assertJsonPath('teachers.0.name', 'Mrs Ade')
            ->assertJsonPath('teachers.0.students', 2)
            ->assertJsonPath('teachers.0.sessions', 3);

        $amyRow = collect($response->json('students'))->firstWhere('id', $amy->id);
        $this->assertSame(2, $amyRow['sessions']);
        $this->assertSame(1, $amyRow['student_no_shows']);
        $this->assertSame(1, collect($response->json('students'))->firstWhere('id', $ben->id)['upcoming']);
        $this->assertNotNull($b1);
    }

    public function test_the_overview_needs_an_owner(): void
    {
        $amy = $this->student('amy@x.test');

        $this->as($this->token('student', $amy->id))->getJson('/api/frontend/lms/owner/one-on-one')->assertForbidden();
    }

    // ── Extra sessions ─────────────────────────────────────────────────────

    public function test_extras_are_offered_only_when_the_course_sells_them(): void
    {
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);

        $this->as($token)->getJson('/api/frontend/lms/one-on-one/booking')->assertJsonPath('extras', null);

        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 1, 'one_on_one_extra_session_price' => 15000]));
        $this->as($token)->getJson('/api/frontend/lms/one-on-one/booking')
            ->assertJsonPath('extras.price', 15000)
            ->assertJsonPath('extras.max', 10);
    }

    public function test_buying_extras_charges_the_academy_split_and_adds_sessions_once_paid(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 1, 'one_on_one_extra_session_price' => 15000]));
        $amy = $this->student('amy@x.test');
        $token = $this->token('student', $amy->id);
        $this->book($token, '2026-10-07 09:00')->assertCreated();
        $this->assertSame([], OneOnOneBooking::openSlots($amy->fresh()));

        $captured = [];
        $this->mock(PaystackService::class, function ($m) use (&$captured) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('initializeTransaction')->once()->andReturnUsing(function (...$args) use (&$captured) {
                $captured = $args;

                return ['data' => ['authorization_url' => 'https://checkout.test/abc']];
            });
            $m->shouldReceive('verifyTransaction')->andReturnUsing(fn ($ref) => ['data' => ['status' => 'success', 'amount' => 3000000, 'currency' => 'NGN', 'reference' => $ref]]);
        });

        $reference = $this->as($token)->postJson('/api/frontend/lms/one-on-one/extras', ['sessions' => 2])
            ->assertOk()->assertJsonPath('authorization_url', 'https://checkout.test/abc')->json('reference');

        // 2 x 15,000 to the academy's subaccount, with the service charge held back.
        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('reference', $reference)->sole();
        $this->assertSame(Payment::KIND_EXTRA_SESSIONS, $payment->kind);
        $this->assertEquals(30000.0, (float) $payment->amount);
        $this->assertSame('ACCT_acme', $captured[5]);
        $expectedFee = round(30000 * $this->acme->commissionPercent() / 100, 2);
        $this->assertEquals($expectedFee, (float) $captured[7]);
        $this->assertEquals($expectedFee, (float) $payment->platform_fee);

        // Pending: nothing added yet.
        $this->assertSame([], OneOnOneBooking::openSlots($amy->fresh()));

        $this->as($token)->getJson('/api/frontend/lms/one-on-one/extras/verify?reference=' . $reference)
            ->assertOk()->assertJsonPath('status', 'success');
        // A second confirmation (webhook) changes nothing.
        $this->assertSame('already', OneOnOneExtras::complete($payment->fresh(), ['amount' => 3000000, 'currency' => 'NGN']));

        $this->assertSame(2, (int) LmsOneOnOneCredit::withoutGlobalScope(TenantScope::class)->where('status', 'paid')->sum('sessions'));
        $this->assertSame(3, OneOnOneBooking::allowance($amy->fresh())['limit']);
        $this->assertSame(2, OneOnOneBooking::allowance($amy->fresh())['remaining']);
        $this->assertNotEmpty(OneOnOneBooking::openSlots($amy->fresh()));
        $this->assertTrue(LmsOwnerNotification::withoutGlobalScope(TenantScope::class)->where('title', 'like', 'Extra sessions bought%')->exists());
    }

    public function test_an_amount_mismatch_is_held_for_review_and_adds_nothing(): void
    {
        $this->asTenant(fn () => $this->course->update(['one_on_one_session_limit' => 1, 'one_on_one_extra_session_price' => 15000]));
        $amy = $this->student('amy@x.test');

        [$payment] = $this->asTenant(fn () => OneOnOneExtras::start($amy, 1));

        $this->assertSame('review', OneOnOneExtras::complete($payment, ['amount' => 100, 'currency' => 'NGN']));
        $this->assertSame(0, (int) LmsOneOnOneCredit::withoutGlobalScope(TenantScope::class)->where('status', 'paid')->sum('sessions'));
    }

    public function test_extras_for_a_monthly_course_count_for_that_month_only(): void
    {
        $this->asTenant(fn () => $this->course->update(['billing_type' => 'monthly', 'one_on_one_session_limit' => 1, 'one_on_one_extra_session_price' => 15000]));
        $amy = $this->student('amy@x.test');

        [$payment, , $credit] = $this->asTenant(fn () => OneOnOneExtras::start($amy, 1));
        $this->assertSame('2026-10', $credit->period);
        OneOnOneExtras::complete($payment);

        $course = $this->course->fresh();
        $this->assertSame(2, OneOnOneBooking::limitFor($amy, $course, $this->lagos('2026-10-20 10:00')));
        $this->assertSame(1, OneOnOneBooking::limitFor($amy, $course, $this->lagos('2026-11-04 10:00')));
    }

    // ── Calendar invites ───────────────────────────────────────────────────

    public function test_booking_emails_carry_a_calendar_invite(): void
    {
        config(['saas.notification_emails_enabled' => true]);
        $amy = $this->student('amy@x.test');
        $this->book($this->token('student', $amy->id), '2026-10-07 10:00')->assertCreated();

        $this->artisan('lms:send-notification-emails')->assertSuccessful();

        Mail::assertSent(LmsNotificationMail::class, function (LmsNotificationMail $mail) {
            return $mail->hasTo('amy@x.test')
                && $mail->calendarIcs !== null
                && str_contains($mail->calendarIcs, 'METHOD:REQUEST')
                && str_contains($mail->calendarIcs, 'DTSTART:20261007T090000Z')
                && count($mail->attachments()) === 1;
        });
    }

    public function test_invites_update_and_cancel_the_same_calendar_entry(): void
    {
        $amy = $this->student('amy@x.test');
        $id = $this->book($this->token('student', $amy->id), '2026-10-14 10:00')->json('session.id');

        $request = CalendarInvite::forNotification('class_scheduled', 'scheduled_class', $id, 'amy@x.test', 'Acme', 'https://acme.test/lms/app/classroom');
        $this->assertStringContainsString('UID:session-' . $id . '@', $request);
        $this->assertStringContainsString('STATUS:CONFIRMED', $request);

        $this->as($this->token('student', $amy->id))->postJson("/api/frontend/lms/one-on-one/booking/{$id}/cancel")->assertOk();

        $cancel = CalendarInvite::forNotification('one_on_one_cancelled', 'scheduled_class', $id, 'ade@acme.test', 'Acme', 'https://acme.test');
        $this->assertStringContainsString('METHOD:CANCEL', $cancel);
        $this->assertStringContainsString('UID:session-' . $id . '@', $cancel);

        // Every line is folded to at most 75 octets.
        foreach (explode("\r\n", $request) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_a_weekly_series_invite_lists_every_session_and_group_classes_get_none(): void
    {
        $amy = $this->student('amy@x.test');
        $first = $this->book($this->token('student', $amy->id), '2026-10-07 10:00', 3)->json('session.id');

        $ics = CalendarInvite::forNotification('one_on_one_series', 'scheduled_class', $first, 'amy@x.test', 'Acme', 'https://acme.test');
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));

        $group = $this->asTenant(fn () => LmsScheduledClass::create([
            'module_id' => $this->module->id, 'teacher_id' => $this->teacher->id, 'title' => 'Group',
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(), 'status' => 'scheduled',
        ]));
        $this->assertNull(CalendarInvite::forNotification('class_scheduled', 'scheduled_class', $group->id, 'amy@x.test', 'Acme', 'https://acme.test'));
    }
}
