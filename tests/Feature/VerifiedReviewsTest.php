<?php

namespace Tests\Feature;

use App\Models\LmsAttendance;
use App\Models\LmsCertificate;
use App\Models\LmsCourse;
use App\Models\LmsCourseReview;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verified-learner reviews: only a student who has actually learned (finished,
 * or attended enough classes, or completed enough tasks/quizzes) can review;
 * the proof is shown with the review; only verified reviews count toward the
 * stars; the academy can reply but never edit or delete.
 */
class VerifiedReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private LmsTeacher $teacher;

    private LmsCourse $course;

    private LmsTrack $track;

    private LmsModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['saas.enforce_tenancy' => true]);

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic', 'status' => 'active',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);

        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create(['title' => 'Web Dev', 'slug' => 'web-dev', 'price' => 30000, 'is_active' => true]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id]);
            $this->module = LmsModule::create(['course_id' => $this->course->id, 'title' => 'HTML basics', 'status' => 'published', 'sort_order' => 1]);
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

    private function student(string $first = 'Ada', string $last = 'Okafor'): LmsStudent
    {
        return $this->asTenant(function () use ($first, $last) {
            $s = LmsStudent::create([
                'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first) . Str::random(4) . '@x.test', 'password' => 'x',
                'selected_course_id' => $this->course->id, 'learning_mode' => 'live',
            ]);
            LmsEnrollment::create(['student_id' => $s->id, 'track_id' => $this->track->id]);

            return $s->fresh();
        });
    }

    private function token(LmsStudent $student): string
    {
        $token = Str::random(80);
        $this->asTenant(fn () => LmsSession::create(['role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addYear()]));

        return $token;
    }

    /** $held past classes; the student attended the first $attended of them. */
    private function classes(LmsStudent $student, int $held, int $attended): void
    {
        $this->asTenant(function () use ($student, $held, $attended) {
            for ($i = 1; $i <= $held; $i++) {
                $class = LmsScheduledClass::create([
                    'module_id' => $this->module->id, 'teacher_id' => $this->teacher->id, 'title' => "Class $i",
                    'starts_at' => now()->subDays($i), 'ends_at' => now()->subDays($i)->addHour(), 'status' => 'scheduled',
                ]);
                if ($i <= $attended) {
                    LmsAttendance::create([
                        'student_id' => $student->id, 'class_type' => 'scheduled', 'scheduled_class_id' => $class->id,
                        'joined_at' => now()->subDays($i), 'status' => 'present', 'calculated_at' => now()->subDays($i),
                    ]);
                }
            }
        });
    }

    private function review(LmsStudent $student, int $stars = 5, string $comment = 'Loved it')
    {
        return $this->withToken($this->token($student))
            ->postJson("/api/frontend/lms/courses/{$this->course->id}/rate", ['rating' => $stars, 'comment' => $comment]);
    }

    public function test_a_student_who_has_not_learned_enough_cannot_review_yet(): void
    {
        $ada = $this->student();
        $this->classes($ada, 6, 1);

        $this->review($ada)->assertStatus(403)->assertJsonPath('not_yet_eligible', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Attend 2 more classes'));

        $this->assertDatabaseCount('lms_course_reviews', 0);
    }

    public function test_enough_attendance_makes_a_verified_review_with_its_proof(): void
    {
        $ada = $this->student();
        $this->classes($ada, 6, 4);

        $this->review($ada, 4)->assertOk()->assertJsonPath('rating_count', 1)->assertJsonPath('rating_average', 4);

        $review = $this->asTenant(fn () => LmsCourseReview::query()->sole());
        $this->assertTrue($review->verified);
        $this->assertSame('Attended 4 of 6 classes', $review->proofText());
    }

    public function test_finishing_the_course_is_enough_on_its_own(): void
    {
        $ada = $this->student();
        $this->asTenant(fn () => LmsCertificate::create([
            'student_id' => $ada->id, 'course_id' => $this->course->id, 'title' => 'Web Dev', 'serial' => 'C-1', 'issued_at' => now(),
        ]));

        $this->review($ada)->assertOk();
        $this->assertSame('Finished the course', $this->asTenant(fn () => LmsCourseReview::query()->sole()->proofText()));
    }

    public function test_only_verified_reviews_count_and_show_on_the_course_page(): void
    {
        $ada = $this->student('Ada', 'Okafor');
        $this->classes($ada, 4, 4);
        $this->review($ada, 5, 'Brilliant teacher')->assertOk();

        // An old, unverified review (written before verification existed).
        $old = $this->student('Bola', 'Ige');
        $this->asTenant(fn () => LmsCourseReview::create(['course_id' => $this->course->id, 'student_id' => $old->id, 'rating' => 1, 'comment' => 'Never attended']));

        $res = $this->getJson('/api/frontend/i/acme/courses/web-dev')->assertOk();
        $res->assertJsonPath('course.rating_count', 1)
            ->assertJsonPath('course.rating_average', 5)
            ->assertJsonPath('reviews.breakdown.5', 1)
            ->assertJsonPath('reviews.breakdown.1', 0)
            ->assertJsonCount(1, 'reviews.items')
            ->assertJsonPath('reviews.items.0.name', 'Ada O.')
            ->assertJsonPath('reviews.items.0.comment', 'Brilliant teacher')
            ->assertJsonPath('reviews.items.0.proof', 'Attended 4 of 4 classes');
    }

    public function test_the_dashboard_tells_the_student_what_is_left(): void
    {
        $ada = $this->student();
        $this->classes($ada, 4, 1);

        $this->withToken($this->token($ada))->getJson('/api/frontend/lms/dashboard')
            ->assertOk()
            ->assertJsonPath('rating.eligible', false)
            ->assertJsonPath('rating.proof.attended', 1)
            ->assertJsonPath('rating.proof.held', 4)
            ->assertJsonPath('rating.missing', fn ($m) => is_string($m) && str_contains($m, 'more class'));
    }

    public function test_the_owner_can_reply_but_another_academy_cannot_see_it(): void
    {
        $ada = $this->student();
        $this->classes($ada, 3, 3);
        $this->review($ada)->assertOk();
        $id = $this->asTenant(fn () => LmsCourseReview::query()->sole()->id);

        $owner = User::create(['first_name' => 'O', 'last_name' => 'W', 'username' => 'owner-acme', 'email' => 'o@acme.test', 'password' => Hash::make('x')]);
        DB::table('tenant_admins')->insert(['tenant_id' => $this->acme->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $token = Str::random(80);
        $this->asTenant(fn () => LmsSession::create(['role' => 'owner', 'user_id' => $owner->id, 'token' => $token, 'expires_at' => now()->addDay()]));

        $this->withToken($token)->getJson('/api/frontend/lms/owner/reviews')
            ->assertOk()->assertJsonPath('summary.count', 1)->assertJsonPath('reviews.0.student_name', 'Ada Okafor');
        $this->withToken($token)->postJson("/api/frontend/lms/owner/reviews/{$id}/reply", ['reply' => 'Thank you Ada!'])
            ->assertOk()->assertJsonPath('reply', 'Thank you Ada!');

        $this->getJson('/api/frontend/i/acme/courses/web-dev')->assertJsonPath('reviews.items.0.reply', 'Thank you Ada!');

        // Another academy's owner can't reach this review.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'plan' => 'basic', 'status' => 'active']);
        $u2 = User::create(['first_name' => 'X', 'last_name' => 'Y', 'username' => 'owner-other', 'email' => 'x@other.test', 'password' => Hash::make('x')]);
        DB::table('tenant_admins')->insert(['tenant_id' => $other->id, 'user_id' => $u2->id, 'role' => 'owner']);
        $t2 = Str::random(80);
        app()->instance('currentTenant', $other);
        LmsSession::create(['role' => 'owner', 'user_id' => $u2->id, 'token' => $t2, 'expires_at' => now()->addDay()]);
        app()->forgetInstance('currentTenant');

        $this->withToken($t2)->postJson("/api/frontend/lms/owner/reviews/{$id}/reply", ['reply' => 'hijack'])->assertNotFound();
        $this->withToken($t2)->getJson('/api/frontend/lms/owner/reviews')->assertOk()->assertJsonPath('summary.count', 0);
    }
}
