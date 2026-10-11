<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsNotification;
use App\Models\LmsQuiz;
use App\Models\LmsQuizAttempt;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module quizzes: one 5-question quiz per module, written by the teacher,
 * auto-marked on the server, one attempt each, and a ranked scoreboard with
 * the top 5 highlighted. Paid plans only.
 */
class ModuleQuizzesTest extends TestCase
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
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);

        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create(['title' => 'Web Dev', 'price' => 30000, 'is_active' => true]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id]);
            $this->module = LmsModule::create(['course_id' => $this->course->id, 'title' => 'HTML basics', 'status' => 'published', 'sort_order' => 1]);
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

    private function student(string $first, string $last = 'Okafor'): LmsStudent
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

    /** Five questions whose correct answers are option 1, 0, 2, 1, 0. */
    private function questions(): array
    {
        return collect([1, 0, 2, 1, 0])->map(fn ($correct, $i) => [
            'prompt' => 'Question ' . ($i + 1),
            'options' => ['A', 'B', 'C'],
            'correct_index' => $correct,
            'explanation' => 'Because ' . ($i + 1),
        ])->all();
    }

    private function publishedQuiz(array $extra = []): int
    {
        $staff = $this->token('staff', $this->teacher->id);
        $id = $this->as($staff)->putJson("/api/frontend/lms/staff/modules/{$this->module->id}/quiz", [
            'title' => 'HTML basics quiz', 'questions' => $this->questions(),
        ] + $extra)->assertOk()->json('quiz.id');
        $this->as($staff)->postJson("/api/frontend/lms/staff/quizzes/{$id}/publish")->assertOk();

        return $id;
    }

    /** Take the quiz with the given chosen options (in question order). */
    private function take(string $token, int $quizId, array $choices)
    {
        $questions = $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->assertOk()->json('attempt.questions');
        $answers = [];
        foreach ($questions as $i => $q) {
            if (array_key_exists($i, $choices)) {
                $answers[$q['id']] = $choices[$i];
            }
        }

        return $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/submit", ['answers' => $answers]);
    }

    // ── Teacher ────────────────────────────────────────────────────────────

    public function test_a_teacher_writes_and_publishes_a_quiz_and_students_are_told(): void
    {
        $amy = $this->student('Amy');
        $quizId = $this->publishedQuiz(['time_limit_minutes' => 10]);

        $quiz = LmsQuiz::withoutGlobalScope(TenantScope::class)->find($quizId);
        $this->assertSame('published', $quiz->status);
        $this->assertSame(5, $quiz->questions()->count());
        $this->assertTrue(LmsNotification::withoutGlobalScope(TenantScope::class)
            ->where('student_id', $amy->id)->where('type', 'quiz_published')->where('reference_id', $quizId)->exists());
    }

    public function test_a_quiz_needs_exactly_five_valid_questions(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $url = "/api/frontend/lms/staff/modules/{$this->module->id}/quiz";

        $this->as($staff)->putJson($url, ['title' => 'Q', 'questions' => array_slice($this->questions(), 0, 4)])
            ->assertStatus(422)->assertJsonValidationErrors('questions');

        $bad = $this->questions();
        $bad[2]['correct_index'] = 3; // only 3 options
        $this->as($staff)->putJson($url, ['title' => 'Q', 'questions' => $bad])
            ->assertStatus(422)->assertJsonPath('message', 'Question 3: pick one of its answer options as the correct one.');
    }

    public function test_quizzes_are_for_paid_plans(): void
    {
        $this->acme->update(['plan' => 'free']);
        $amy = $this->student('Amy');

        $this->as($this->token('staff', $this->teacher->id))->getJson('/api/frontend/lms/staff/quizzes')->assertStatus(402);
        $this->as($this->token('student', $amy->id))->getJson('/api/frontend/lms/quizzes')
            ->assertOk()->assertJsonPath('available', false);
    }

    public function test_another_teachers_module_is_off_limits(): void
    {
        $other = $this->asTenant(fn () => LmsTeacher::create(['name' => 'Mr Obi', 'email' => 'obi@acme.test', 'password' => 'x']));

        $this->as($this->token('staff', $other->id))
            ->putJson("/api/frontend/lms/staff/modules/{$this->module->id}/quiz", ['title' => 'Q', 'questions' => $this->questions()])
            ->assertNotFound();
    }

    // ── Student ────────────────────────────────────────────────────────────

    public function test_a_student_takes_the_quiz_and_is_marked_on_the_server(): void
    {
        $quizId = $this->publishedQuiz();
        $token = $this->token('student', $this->student('Amy')->id);

        $this->as($token)->getJson('/api/frontend/lms/quizzes')
            ->assertOk()->assertJsonPath('quizzes.0.state', 'not_started');

        $start = $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->assertOk();
        // The correct answers never reach the browser.
        $this->assertArrayNotHasKey('correct_index', $start->json('attempt.questions.0'));

        // Correct, correct, wrong, correct, unanswered → 3/5.
        $this->take($token, $quizId, [1, 0, 0, 1])
            ->assertOk()->assertJsonPath('state', 'submitted')->assertJsonPath('score', 3)->assertJsonPath('total', 5);

        // One attempt only.
        $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->assertStatus(409);
    }

    public function test_answers_are_saved_as_they_go(): void
    {
        $quizId = $this->publishedQuiz();
        $token = $this->token('student', $this->student('Amy')->id);
        $q = $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->json('attempt.questions');

        $this->as($token)->putJson("/api/frontend/lms/quizzes/{$quizId}/answers", ['answers' => [$q[0]['id'] => 1, $q[1]['id'] => 9]])
            ->assertOk()->assertJsonPath('saved', 1); // the out-of-range answer is ignored

        // Resuming shows the saved answer.
        $this->as($token)->getJson("/api/frontend/lms/quizzes/{$quizId}")
            ->assertOk()->assertJsonPath('state', 'in_progress')->assertJsonPath('attempt.answers.' . $q[0]['id'], 1);
    }

    public function test_the_time_limit_is_enforced_by_the_server(): void
    {
        $quizId = $this->publishedQuiz(['time_limit_minutes' => 5]);
        $token = $this->token('student', $this->student('Amy')->id);
        $q = $this->as($token)->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->json('attempt.questions');
        $this->as($token)->putJson("/api/frontend/lms/quizzes/{$quizId}/answers", ['answers' => [$q[0]['id'] => 1]])->assertOk();

        Carbon::setTestNow(now()->addMinutes(6));

        // Too late to change anything; the saved answer is what's marked.
        $this->as($token)->putJson("/api/frontend/lms/quizzes/{$quizId}/answers", ['answers' => [$q[1]['id'] => 0]])->assertStatus(409);
        $this->as($token)->getJson("/api/frontend/lms/quizzes/{$quizId}")
            ->assertOk()->assertJsonPath('state', 'submitted')->assertJsonPath('score', 1)->assertJsonPath('duration_seconds', 300);
    }

    public function test_correct_answers_are_revealed_only_after_the_quiz_closes(): void
    {
        $quizId = $this->publishedQuiz(['closes_at' => now()->addDay()->toIso8601String()]);
        $token = $this->token('student', $this->student('Amy')->id);
        $this->take($token, $quizId, [1, 1, 1, 1, 1])->assertOk();

        $this->assertNull($this->as($token)->getJson("/api/frontend/lms/quizzes/{$quizId}")->json('review'));

        Carbon::setTestNow(now()->addDays(2));
        $review = $this->as($token)->getJson("/api/frontend/lms/quizzes/{$quizId}")->json('review');
        $this->assertCount(5, $review);
        $this->assertSame(1, $review[0]['correct_index']);
        $this->assertSame(1, $review[1]['your_answer']);
        $this->assertSame('Because 1', $review[0]['explanation']);
    }

    public function test_a_student_of_another_course_cannot_see_the_quiz(): void
    {
        $quizId = $this->publishedQuiz();
        $outsider = $this->asTenant(function () {
            $course = LmsCourse::create(['title' => 'Design', 'price' => 1000, 'is_active' => true]);

            return LmsStudent::create(['first_name' => 'Zed', 'last_name' => 'Z', 'email' => 'zed@x.test', 'password' => 'x', 'selected_course_id' => $course->id]);
        });

        $this->as($this->token('student', $outsider->id))->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->assertNotFound();
    }

    // ── Scoreboard ─────────────────────────────────────────────────────────

    public function test_the_scoreboard_ranks_by_score_then_speed_and_highlights_the_top_five(): void
    {
        $quizId = $this->publishedQuiz();

        // name => [choices, seconds taken]
        $plan = [
            'Ada' => [[1, 0, 2, 1, 0], 100],   // 5/5
            'Ben' => [[1, 0, 2, 1, 1], 50],    // 4/5, faster
            'Cy' => [[1, 0, 2, 1, 1], 90],     // 4/5, slower
            'Di' => [[1, 0, 0, 0, 0], 30],     // 3/5
            'Ed' => [[1, 1, 1, 1, 1], 20],     // 2/5
            'Fay' => [[0, 1, 0, 0, 1], 10],    // 0/5
        ];

        $tokens = [];
        foreach ($plan as $name => [$choices, $seconds]) {
            $tokens[$name] = $this->token('student', $this->student($name)->id);
            $start = now();
            Carbon::setTestNow($start);
            $q = $this->as($tokens[$name])->postJson("/api/frontend/lms/quizzes/{$quizId}/start")->json('attempt.questions');
            Carbon::setTestNow($start->copy()->addSeconds($seconds));
            $answers = collect($q)->mapWithKeys(fn ($question, $i) => [$question['id'] => $choices[$i]])->all();
            $this->as($tokens[$name])->postJson("/api/frontend/lms/quizzes/{$quizId}/submit", ['answers' => $answers])->assertOk();
            Carbon::setTestNow($start->copy()->addMinutes(5));
        }

        $rows = $this->as($tokens['Cy'])->getJson("/api/frontend/lms/quizzes/{$quizId}/scoreboard")->assertOk()->json('rows');

        $this->assertSame(['Ada O.', 'Ben O.', 'Cy O.', 'Di O.', 'Ed O.', 'Fay O.'], array_column($rows, 'name'));
        $this->assertSame([5, 4, 4, 3, 2, 0], array_column($rows, 'score'));
        $this->assertSame([true, true, true, true, true, false], array_column($rows, 'top'));
        $this->assertTrue($rows[2]['you']);
        $this->assertFalse($rows[0]['you']);

        // The teacher sees full names.
        $this->as($this->token('staff', $this->teacher->id))->getJson("/api/frontend/lms/staff/quizzes/{$quizId}/results")
            ->assertOk()->assertJsonPath('scoreboard.0.name', 'Ada Okafor')->assertJsonPath('enrolled', 6);
    }

    // ── Editing after students have taken it ───────────────────────────────

    public function test_questions_are_locked_once_taken_but_details_can_change(): void
    {
        $quizId = $this->publishedQuiz();
        $this->take($this->token('student', $this->student('Amy')->id), $quizId, [1, 0, 2, 1, 0])->assertOk();
        $staff = $this->token('staff', $this->teacher->id);
        $url = "/api/frontend/lms/staff/modules/{$this->module->id}/quiz";

        $changed = $this->questions();
        $changed[0]['correct_index'] = 2;
        $this->as($staff)->putJson($url, ['title' => 'HTML basics quiz', 'questions' => $changed])->assertStatus(422);

        $this->as($staff)->putJson($url, ['title' => 'Renamed quiz', 'questions' => $this->questions()])
            ->assertOk()->assertJsonPath('quiz.title', 'Renamed quiz');

        $this->as($staff)->deleteJson("/api/frontend/lms/staff/quizzes/{$quizId}")->assertStatus(422);
        $this->assertSame(1, LmsQuizAttempt::withoutGlobalScope(TenantScope::class)->where('quiz_id', $quizId)->count());
    }
}
