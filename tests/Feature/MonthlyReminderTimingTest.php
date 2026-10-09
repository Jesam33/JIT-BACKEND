<?php

namespace Tests\Feature;

use App\Mail\LmsNotificationMail;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsNotification;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\CourseBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Agreed timing for monthly students: a reminder EMAIL a week before the paid
 * month runs out, and a 2-day grace period after it before access pauses.
 */
class MonthlyReminderTimingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);
    }

    /** @return array{0: LmsStudent, 1: TrainingRegistration} */
    private function monthlyStudentPaidUntil(\DateTimeInterface $paidUntil, string $status = 'active'): array
    {
        app()->instance('currentTenant', $this->acme);
        try {
            $teacher = LmsTeacher::create(['name' => 'Teacher', 'email' => 'teach@acme.test', 'password' => 'x']);
            $course = LmsCourse::create(['title' => 'Coding Club', 'price' => 15000, 'billing_type' => 'monthly', 'is_active' => true]);
            $track = LmsTrack::create([
                'name' => 'Cohort', 'course_id' => $course->id, 'instructor_id' => $teacher->id,
                'end_date' => now()->addMonths(6)->toDateString(),
            ]);
            $registration = TrainingRegistration::create([
                'first_name' => 'Ada', 'last_name' => 'Learner', 'date_of_birth' => '2000-01-01',
                'qualification_level' => 'SSCE', 'phone_number' => '0800', 'email' => 'ada@learner.test', 'whatsapp' => '0800',
                'course_id' => $course->id, 'course_name' => $course->title, 'learning_mode' => 'live',
                'course_price' => 15000, 'status' => 'approved',
            ]);
            $registration->forceFill(['billing_status' => $status, 'paid_until' => $paidUntil])->save();
            $student = LmsStudent::create([
                'first_name' => 'Ada', 'last_name' => 'Learner', 'email' => 'ada@learner.test', 'password' => 'x',
                'selected_course_id' => $course->id, 'training_registration_id' => $registration->id,
            ]);
            LmsEnrollment::create(['student_id' => $student->id, 'track_id' => $track->id]);

            return [$student, $registration];
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    private function reminders(LmsStudent $student): int
    {
        return LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->where('type', 'billing_due_soon')
            ->count();
    }

    public function test_the_settings_are_a_week_and_two_days(): void
    {
        $this->assertSame(7, (int) config('saas.monthly_reminder_days'));
        $this->assertSame(2, CourseBilling::graceDays());
    }

    /** Freeze the clock at $hour:00 Lagos time on a fixed day. */
    private function at(int $hour, int $dayOffset = 0): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 1, $hour, 0, 0, 'Africa/Lagos')->addDays($dayOffset)->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_reminder_is_emailed_every_day_of_the_last_week(): void
    {
        Mail::fake();
        $this->at(10);
        [$student] = $this->monthlyStudentPaidUntil(now()->addDays(7)->subHour());

        Artisan::call('lms:process-course-renewals');
        Artisan::call('lms:process-course-renewals'); // hourly runs: once per day, not per run
        $this->assertSame(1, $this->reminders($student));

        // The notification email sweep turns it into an email to the student.
        Artisan::call('lms:send-notification-emails');
        Mail::assertSent(LmsNotificationMail::class, fn ($mail) => $mail->hasTo('ada@learner.test'));

        // One more every following day, right up to the due date.
        foreach (range(1, 6) as $day) {
            $this->at(10, $day);
            Artisan::call('lms:process-course-renewals');
        }
        $this->assertSame(7, $this->reminders($student));

        $latest = fn () => LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)->where('type', 'billing_due_soon')->latest('id')->first()->title;
        $this->assertStringContainsString('due tomorrow', $latest());

        // Due at 9am on day 7: the 8am run that morning still reminds ("today").
        $this->at(8, 7);
        Artisan::call('lms:process-course-renewals');
        $this->assertSame(8, $this->reminders($student));
        $this->assertStringContainsString('due today', $latest());
    }

    public function test_reminders_never_go_out_at_night(): void
    {
        $this->at(3); // 3am Lagos
        [$student] = $this->monthlyStudentPaidUntil(now()->addDays(5));

        Artisan::call('lms:process-course-renewals');
        $this->assertSame(0, $this->reminders($student));

        $this->at(8); // the first run at/after 8am sends it
        Artisan::call('lms:process-course-renewals');
        $this->assertSame(1, $this->reminders($student));
    }

    public function test_no_reminder_earlier_than_a_week_out(): void
    {
        $this->at(10);
        [$student] = $this->monthlyStudentPaidUntil(now()->addDays(8));

        Artisan::call('lms:process-course-renewals');

        $this->assertSame(0, $this->reminders($student));
    }

    public function test_access_stays_open_for_two_days_after_the_month_ends(): void
    {
        [, $withinGrace] = $this->monthlyStudentPaidUntil(now()->subDay(), 'past_due');
        $this->assertFalse(CourseBilling::isLocked($withinGrace));
        $this->assertTrue(
            CourseBilling::accessEndsAt($withinGrace)->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()),
            'Access should end exactly 2 days after the paid month.'
        );
    }

    public function test_access_pauses_once_the_two_days_are_up(): void
    {
        [, $pastGrace] = $this->monthlyStudentPaidUntil(now()->subDays(2)->subHour(), 'past_due');

        $this->assertTrue(CourseBilling::isLocked($pastGrace));
    }
}
