<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live quizzes in a live class: the teacher saves a round, starts it in the
 * class, and drives it; students follow on their class screen, answer against
 * the server clock, and see the right answer, their points and the top 5 only
 * after each question closes. Plays a whole game with the clock moved forward.
 */
class LiveQuizTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private LmsTeacher $teacher;

    private LmsCourse $course;

    private LmsTrack $track;

    private LmsScheduledClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['saas.enforce_tenancy' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));

        $this->acme = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'plan' => 'basic', 'status' => 'active',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);

        $this->asTenant(function () {
            $this->teacher = LmsTeacher::create(['name' => 'Mrs Ade', 'email' => 'ade@acme.test', 'password' => 'x']);
            $this->course = LmsCourse::create(['title' => 'Web Dev', 'price' => 30000, 'is_active' => true]);
            $this->track = LmsTrack::create(['name' => 'Cohort A', 'course_id' => $this->course->id, 'instructor_id' => $this->teacher->id]);
            $module = LmsModule::create(['course_id' => $this->course->id, 'title' => 'HTML', 'status' => 'published', 'sort_order' => 1]);
            $this->class = LmsScheduledClass::create([
                'module_id' => $module->id, 'teacher_id' => $this->teacher->id, 'title' => 'Week 1 live class',
                'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(), 'status' => 'scheduled',
            ]);
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

    private function student(string $first, ?LmsCourse $course = null): LmsStudent
    {
        return $this->asTenant(function () use ($first, $course) {
            $course ??= $this->course;
            $s = LmsStudent::create([
                'first_name' => $first, 'last_name' => 'Okafor', 'email' => strtolower($first) . Str::random(4) . '@x.test', 'password' => 'x',
                'selected_course_id' => $course->id, 'learning_mode' => 'live',
            ]);
            if ($course->is($this->course)) {
                LmsEnrollment::create(['student_id' => $s->id, 'track_id' => $this->track->id]);
            }

            return $s->fresh();
        });
    }

    private function token(string $role, int $userId): string
    {
        $token = Str::random(80);
        $this->asTenant(fn () => LmsSession::create(['role' => $role, 'user_id' => $userId, 'token' => $token, 'expires_at' => now()->addYear()]));

        return $token;
    }

    private function cls(): string
    {
        return 'class_type=scheduled&class_id=' . $this->class->id;
    }

    /** Q1 answer B (1), Q2 answer A (0); 20 seconds each. */
    private function round(string $staff): int
    {
        return $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/rounds', [
            'course_id' => $this->course->id,
            'title' => 'Warm-up',
            'questions' => [
                ['prompt' => 'Which tag makes a link?', 'options' => ['<p>', '<a>', '<div>'], 'correct_index' => 1, 'seconds' => 20],
                ['prompt' => 'HTML stands for?', 'options' => ['HyperText Markup Language', 'Home Tool'], 'correct_index' => 0, 'seconds' => 20],
            ],
        ])->assertCreated()->json('round.id');
    }

    public function test_a_whole_live_quiz_round_plays_out(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $ada = $this->token('student', $this->student('Ada')->id);
        $bola = $this->token('student', $this->student('Bola')->id);
        $roundId = $this->round($staff);

        // Nothing running yet.
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertOk()->assertJsonPath('run', null);

        // Teacher starts it: everyone waits in the lobby.
        $runId = $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/runs?' . $this->cls(), ['round_id' => $roundId])
            ->assertCreated()->assertJsonPath('run.phase', 'lobby')->json('run.id');
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertJsonPath('run.phase', 'lobby');

        // Question 1 opens: students see it WITHOUT the right answer.
        $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next")->assertJsonPath('run.phase', 'question');
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())
            ->assertJsonPath('run.question.prompt', 'Which tag makes a link?')
            ->assertJsonMissingPath('run.correct_index');

        // Ada mis-taps a wrong answer at 1s and changes it to the right one at
        // 2s: the last tap counts, timed from when she made it. Bola answers
        // wrong after 10s.
        Carbon::setTestNow(now()->addSecond());
        $this->withToken($ada)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 0])->assertOk()->assertJsonPath('choice', 0);
        Carbon::setTestNow(now()->addSecond());
        $this->withToken($ada)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 1])->assertOk()->assertJsonPath('choice', 1);
        Carbon::setTestNow(now()->addSeconds(8));
        $this->withToken($bola)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 2])->assertOk();

        // The teacher sees the answers coming in, and who has joined.
        $this->withToken($staff)->getJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}")
            ->assertJsonPath('run.answered', 2)
            ->assertJsonPath('run.distribution', [0, 1, 1])
            ->assertJsonPath('run.joined', 2);

        // The timer runs out: the question reveals itself, late answers are refused.
        Carbon::setTestNow(now()->addSeconds(15));
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())
            ->assertJsonPath('run.phase', 'reveal')
            ->assertJsonPath('run.correct_index', 1)
            ->assertJsonPath('run.my_correct', true)
            ->assertJsonPath('run.my_points', 950) // 500 + 500 x (1 - 2/20)
            ->assertJsonPath('run.top.0.name', 'Ada O.')
            ->assertJsonPath('run.me.rank', 1);
        $this->withToken($bola)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())
            ->assertJsonPath('run.my_correct', false)->assertJsonPath('run.my_points', 0)->assertJsonPath('run.me.rank', 2);
        $this->withToken($bola)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 1])->assertStatus(409);

        // Question 2: Bola gets it right fast, Ada doesn't answer.
        $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next")->assertJsonPath('run.index', 1);
        Carbon::setTestNow(now()->addSeconds(1));
        $this->withToken($bola)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 1, 'choice' => 0])->assertOk();

        // The teacher reveals early, then moves past the last question: finished.
        $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/reveal")->assertJsonPath('run.phase', 'reveal');
        $final = $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next")->assertJsonPath('run.phase', 'finished');
        $final->assertJsonPath('run.standings.0.name', 'Bola Okafor') // 975 beats 950
            ->assertJsonPath('run.standings.0.points', 975)
            ->assertJsonPath('run.per_question.0.percent', 50)
            ->assertJsonPath('run.per_question.1.percent', 100);

        // Students see the final podium for a couple of minutes, then it's gone.
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())
            ->assertJsonPath('run.phase', 'finished')->assertJsonPath('run.top.0.name', 'Bola O.')->assertJsonPath('run.me.rank', 2);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertJsonPath('run', null);
    }

    public function test_auto_advance_moves_on_after_the_reveal(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $ada = $this->token('student', $this->student('Ada')->id);
        $runId = $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/runs?' . $this->cls(), ['round_id' => $this->round($staff), 'auto_advance' => true])->json('run.id');
        $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next");

        Carbon::setTestNow(now()->addSeconds(21)); // question 1 timed out
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertJsonPath('run.phase', 'reveal');
        Carbon::setTestNow(now()->addSeconds(9)); // reveal shown long enough
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())
            ->assertJsonPath('run.phase', 'question')->assertJsonPath('run.index', 1);
    }

    public function test_only_this_courses_students_take_part(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $other = $this->asTenant(fn () => LmsCourse::create(['title' => 'Design', 'price' => 1, 'is_active' => true]));
        $outsider = $this->token('student', $this->student('Zed', $other)->id);
        $runId = $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/runs?' . $this->cls(), ['round_id' => $this->round($staff)])->json('run.id');
        $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next");

        $this->withToken($outsider)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertJsonPath('run', null);
        $this->withToken($outsider)->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 1])->assertNotFound();
    }

    public function test_a_round_needs_valid_questions(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/rounds', [
            'course_id' => $this->course->id, 'title' => 'Bad',
            'questions' => [['prompt' => 'Q?', 'options' => ['Only one'], 'correct_index' => 0]],
        ])->assertStatus(422)->assertJsonPath('message', 'Question 1: give 2 to 4 answers.');
    }

    public function test_taking_part_in_live_quizzes_adds_a_bonus_to_overall_progress(): void
    {
        $staff = $this->token('staff', $this->teacher->id);
        $ada = $this->student('Ada');   // joins both quizzes
        $bola = $this->student('Bola'); // joins one
        $chi = $this->student('Chi');   // joins none
        $roundId = $this->round($staff);

        foreach ([0, 1] as $game) {
            $runId = $this->withToken($staff)->postJson('/api/frontend/lms/staff/live-quiz/runs?' . $this->cls(), ['round_id' => $roundId])->json('run.id');
            $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/next");
            // Wrong answers still count: it rewards taking part, not the score.
            $this->withToken($this->token('student', $ada->id))->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 0])->assertOk();
            if ($game === 0) {
                $this->withToken($this->token('student', $bola->id))->postJson("/api/frontend/lms/live-quiz/{$runId}/answer", ['question_index' => 0, 'choice' => 1])->assertOk();
            }
            $this->withToken($staff)->postJson("/api/frontend/lms/staff/live-quiz/runs/{$runId}/finish");
        }

        $summary = fn (LmsStudent $s) => $this->withToken($this->token('student', $s->id))->getJson('/api/frontend/lms/dashboard')->assertOk()->json('summary');

        $a = $summary($ada);
        $this->assertSame([2, 2, 5], [$a['live_quizzes_held'], $a['live_quizzes_joined'], $a['live_quiz_bonus']]);
        $b = $summary($bola);
        $this->assertSame([2, 1, 3], [$b['live_quizzes_held'], $b['live_quizzes_joined'], $b['live_quiz_bonus']]);
        $c = $summary($chi);
        $this->assertSame([2, 0, 0], [$c['live_quizzes_held'], $c['live_quizzes_joined'], $c['live_quiz_bonus']]);

        // The bonus is inside the overall number.
        $this->assertSame($c['overall_progress'] + 5, $a['overall_progress']);
    }

    public function test_free_plans_have_no_live_quiz(): void
    {
        $this->acme->update(['plan' => 'free']);
        $staff = $this->token('staff', $this->teacher->id);
        $ada = $this->token('student', $this->student('Ada')->id);

        $this->withToken($staff)->getJson('/api/frontend/lms/staff/live-quiz/class?' . $this->cls())->assertStatus(402);
        $this->withToken($ada)->getJson('/api/frontend/lms/live-quiz?' . $this->cls())->assertOk()->assertJsonPath('run', null);
    }
}
