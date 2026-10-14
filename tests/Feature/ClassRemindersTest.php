<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\ClassReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Group class reminders: 20 and 5 minutes before the start, each sent once,
 * and only if the class already existed at that moment (a class created two
 * minutes before it starts only gets the "new class" notice).
 */
class ClassRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private LmsTeacher $teacher;

    private LmsCourse $course;

    private LmsTrack $track;

    private LmsModule $module;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['saas.enforce_tenancy' => true]);
        $this->start = Carbon::parse('2026-10-14 21:50:00');

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic', 'status' => 'active']);
        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create(['title' => 'Web Dev', 'price' => 1, 'is_active' => true]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id]);
            $this->module = LmsModule::create(['course_id' => $this->course->id, 'title' => 'HTML', 'status' => 'published', 'sort_order' => 1]);
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

    private function student(string $first, array $extra = []): LmsStudent
    {
        return $this->asTenant(function () use ($first, $extra) {
            // $extra first: in a PHP array union the left side wins.
            $s = LmsStudent::create($extra + [
                'first_name' => $first, 'last_name' => 'O', 'email' => strtolower($first) . Str::random(4) . '@x.test', 'password' => 'x',
                'selected_course_id' => $this->course->id, 'learning_mode' => 'live',
            ]);
            LmsEnrollment::create(['student_id' => $s->id, 'track_id' => $this->track->id]);

            return $s;
        });
    }

    /** A group class at $this->start, created at $createdAt. */
    private function classCreatedAt(Carbon $createdAt): LmsScheduledClass
    {
        Carbon::setTestNow($createdAt);

        return $this->asTenant(fn () => LmsScheduledClass::create([
            'module_id' => $this->module->id, 'teacher_id' => $this->teacher->id, 'title' => 'Week 1',
            'starts_at' => $this->start, 'ends_at' => $this->start->copy()->addHour(), 'status' => 'scheduled',
        ]));
    }

    private function runAt(Carbon $at): void
    {
        Carbon::setTestNow($at);
        ClassReminders::run();
    }

    /** @return string[] reminder titles a student has */
    private function reminders(LmsStudent $s): array
    {
        return LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $s->id)->where('type', 'class_reminder')->orderBy('id')->pluck('title')->all();
    }

    public function test_a_class_made_well_ahead_gets_both_reminders_once_each(): void
    {
        $ada = $this->student('Ada');
        $this->classCreatedAt($this->start->copy()->subHours(3));

        $this->runAt($this->start->copy()->subMinutes(30)); // too early
        $this->assertSame([], $this->reminders($ada));

        $this->runAt($this->start->copy()->subMinutes(20));
        $this->runAt($this->start->copy()->subMinutes(19)); // re-run: no repeat
        $this->assertSame(['Your class starts in 20 minutes'], $this->reminders($ada));

        $this->runAt($this->start->copy()->subMinutes(5));
        $this->runAt($this->start->copy()->subMinutes(4));
        $this->assertSame(['Your class starts in 20 minutes', 'Your class starts in 5 minutes'], $this->reminders($ada));
    }

    public function test_a_class_made_two_minutes_before_gets_no_reminders(): void
    {
        $ada = $this->student('Ada');
        $this->classCreatedAt($this->start->copy()->subMinutes(2)); // 10:48 for 10:50

        $this->runAt($this->start->copy()->subMinutes(2));
        $this->runAt($this->start->copy()->subMinute());
        $this->assertSame([], $this->reminders($ada));
    }

    public function test_a_class_made_ten_minutes_before_gets_only_the_five_minute_reminder(): void
    {
        $ada = $this->student('Ada');
        $this->classCreatedAt($this->start->copy()->subMinutes(10));

        $this->runAt($this->start->copy()->subMinutes(9));
        $this->runAt($this->start->copy()->subMinutes(5));
        $this->assertSame(['Your class starts in 5 minutes'], $this->reminders($ada));
    }

    public function test_a_late_run_sends_only_the_latest_reminder(): void
    {
        $ada = $this->student('Ada');
        $this->classCreatedAt($this->start->copy()->subDay());

        // The scheduler was down until 4 minutes before: one reminder, not two.
        $this->runAt($this->start->copy()->subMinutes(4));
        $this->assertSame(['Your class starts in 5 minutes'], $this->reminders($ada));
    }

    public function test_opted_out_and_one_on_one_students_are_not_reminded(): void
    {
        $ada = $this->student('Ada');
        $quiet = $this->student('Quiet', ['notify_class_reminders' => false]);
        $solo = $this->student('Solo', ['learning_mode' => LmsStudent::MODE_ONE_ON_ONE]);
        $this->classCreatedAt($this->start->copy()->subHour());

        $this->runAt($this->start->copy()->subMinutes(20));
        $this->assertCount(1, $this->reminders($ada));
        $this->assertSame([], $this->reminders($quiet));
        $this->assertSame([], $this->reminders($solo));
    }
}
