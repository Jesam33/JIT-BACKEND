<?php

namespace App\Services;

use App\Models\LmsClassroom;
use App\Models\LmsLiveQuizAnswer;
use App\Models\LmsLiveQuizRun;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The live-quiz game engine. The server owns the state, so every screen (the
 * teacher's control panel and each student's overlay) shows the same thing:
 *
 *   lobby ──next──► question 1 ──(time up / reveal)──► reveal 1 ──next──► question 2 … ──► finished
 *
 * Clients poll. Time-based moves (a question's timer running out, the
 * auto-advance after a reveal) are applied lazily by settle() on whatever
 * request arrives first, under a row lock so two readers can't both move it.
 *
 * Scoring (Kahoot-style): a right answer is worth 500 points plus up to 500
 * more for speed; a wrong or missing answer is 0. Ties on points go to the
 * lower total answer time.
 */
class LiveQuizzes
{
    public const MAX_POINTS = 1000;

    /** Answers that arrive just after the buzzer still count (network lag). */
    public const GRACE_MS = 1500;

    /** With auto-advance on, how long the reveal shows before the next question. */
    public const AUTO_REVEAL_SECONDS = 8;

    public const SECONDS = [10, 15, 20, 30, 45, 60];

    public const TOP = 5;

    /** The course a live class belongs to, or null when the class doesn't exist. */
    public static function classCourseId(string $type, int $id): ?int
    {
        if ($type === 'scheduled') {
            $class = LmsScheduledClass::query()->with('module:id,course_id')->find($id);

            return $class?->module?->course_id ? (int) $class->module->course_id : null;
        }
        if ($type === 'classroom') {
            $course = LmsClassroom::query()->whereKey($id)->value('course_id');

            return $course ? (int) $course : null;
        }

        return null;
    }

    /** The quiz currently running in a class (started within the last 12 hours), if any. */
    public static function activeRun(string $type, int $id): ?LmsLiveQuizRun
    {
        return LmsLiveQuizRun::query()
            ->where('class_type', $type)
            ->where('class_id', $id)
            ->where('phase', '!=', LmsLiveQuizRun::FINISHED)
            ->where('created_at', '>=', now()->subHours(12))
            ->latest('id')
            ->first();
    }

    /** When the current question closes. */
    public static function deadline(LmsLiveQuizRun $run): ?Carbon
    {
        $q = $run->currentQuestion();
        if ($run->phase !== LmsLiveQuizRun::QUESTION || ! $q || ! $run->phase_started_at) {
            return null;
        }

        return $run->phase_started_at->copy()->addSeconds((int) $q['seconds']);
    }

    /** Apply any time-based move that is due (timer ran out, auto-advance). */
    public static function settle(LmsLiveQuizRun $run): LmsLiveQuizRun
    {
        if (! static::dueMove($run)) {
            return $run;
        }

        return DB::transaction(function () use ($run) {
            $fresh = LmsLiveQuizRun::query()->whereKey($run->id)->lockForUpdate()->first();
            $move = $fresh ? static::dueMove($fresh) : null;
            if ($move === 'reveal') {
                static::revealLocked($fresh, static::deadline($fresh));
            } elseif ($move === 'advance') {
                static::advanceLocked($fresh);
            }

            return $fresh ?? $run;
        });
    }

    private static function dueMove(LmsLiveQuizRun $run): ?string
    {
        if ($run->phase === LmsLiveQuizRun::QUESTION) {
            $deadline = static::deadline($run);

            return $deadline && now()->greaterThanOrEqualTo($deadline) ? 'reveal' : null;
        }
        if ($run->phase === LmsLiveQuizRun::REVEAL && $run->auto_advance && $run->phase_started_at
            && now()->greaterThanOrEqualTo($run->phase_started_at->copy()->addSeconds(self::AUTO_REVEAL_SECONDS))) {
            return 'advance';
        }

        return null;
    }

    /** Teacher: go to the next question (from the lobby or a reveal); after the last, finish. */
    public static function advance(LmsLiveQuizRun $run): LmsLiveQuizRun
    {
        return DB::transaction(function () use ($run) {
            $fresh = LmsLiveQuizRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($fresh->phase === LmsLiveQuizRun::QUESTION) {
                // Skipping ahead mid-question closes it first.
                static::revealLocked($fresh, now());
            }
            static::advanceLocked($fresh);

            return $fresh;
        });
    }

    /** Teacher: close the current question now and show the answer. */
    public static function reveal(LmsLiveQuizRun $run): LmsLiveQuizRun
    {
        return DB::transaction(function () use ($run) {
            $fresh = LmsLiveQuizRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($fresh->phase === LmsLiveQuizRun::QUESTION) {
                static::revealLocked($fresh, now());
            }

            return $fresh;
        });
    }

    public static function finish(LmsLiveQuizRun $run): LmsLiveQuizRun
    {
        $run->forceFill(['phase' => LmsLiveQuizRun::FINISHED, 'phase_started_at' => now(), 'finished_at' => now()])->save();

        return $run;
    }

    private static function revealLocked(LmsLiveQuizRun $run, ?Carbon $at): void
    {
        $run->forceFill(['phase' => LmsLiveQuizRun::REVEAL, 'phase_started_at' => $at ?? now()])->save();
    }

    private static function advanceLocked(LmsLiveQuizRun $run): void
    {
        if ($run->phase === LmsLiveQuizRun::FINISHED) {
            return;
        }
        $next = $run->current_index + 1;
        if ($next >= count((array) $run->questions)) {
            $run->forceFill(['phase' => LmsLiveQuizRun::FINISHED, 'phase_started_at' => now(), 'finished_at' => now()])->save();

            return;
        }
        $run->forceFill(['phase' => LmsLiveQuizRun::QUESTION, 'current_index' => $next, 'phase_started_at' => now()])->save();
    }

    /**
     * A student's answer to the open question. One answer per question, which
     * they can change until it closes (the last one counts, timed from that
     * tap); refused once the question has closed.
     *
     * @return array{ok: bool, message?: string, answer?: LmsLiveQuizAnswer}
     */
    public static function answer(LmsLiveQuizRun $run, LmsStudent $student, int $index, int $choice): array
    {
        $run = static::settle($run);
        static::markSeen($run, (int) $student->id); // answering means they're here
        $q = $run->currentQuestion();
        $deadline = static::deadline($run);

        if ($run->phase !== LmsLiveQuizRun::QUESTION || $run->current_index !== $index || ! $q || ! $deadline) {
            return ['ok' => false, 'message' => 'This question has closed.'];
        }
        if (now()->greaterThan($deadline->copy()->addMilliseconds(self::GRACE_MS))) {
            return ['ok' => false, 'message' => 'Time is up for this question.'];
        }
        if ($choice < 0 || $choice >= count((array) $q['options'])) {
            return ['ok' => false, 'message' => 'Pick one of the answers.'];
        }

        $limitMs = max(1, (int) $q['seconds'] * 1000);
        $ms = (int) min($limitMs, max(0, $run->phase_started_at->diffInMilliseconds(now())));
        $correct = $choice === (int) $q['correct_index'];
        $points = $correct ? (int) round(self::MAX_POINTS / 2 + (self::MAX_POINTS / 2) * (1 - $ms / $limitMs)) : 0;

        $fields = ['choice' => $choice, 'correct' => $correct, 'response_ms' => $ms, 'points' => $points];

        // Changing their mind before the buzzer: the new answer replaces the
        // old, timed from the new tap, so a switch costs some speed bonus but
        // a mis-tap is never final. Nobody sees answers before the reveal, so
        // switching can't be used to copy.
        $existing = LmsLiveQuizAnswer::query()
            ->where('run_id', $run->id)->where('student_id', $student->id)->where('question_index', $index)->first();
        if ($existing) {
            if ((int) $existing->choice !== $choice) {
                $existing->forceFill($fields)->save();
            }

            return ['ok' => true, 'answer' => $existing];
        }

        try {
            $answer = LmsLiveQuizAnswer::createForTenant((int) $run->tenant_id, [
                'run_id' => $run->id,
                'student_id' => $student->id,
                'question_index' => $index,
                'choice' => $choice,
                'correct' => $correct,
                'response_ms' => $ms,
                'points' => $points,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two quick taps raced to the unique index: the later tap wins.
            $answer = LmsLiveQuizAnswer::query()
                ->where('run_id', $run->id)->where('student_id', $student->id)->where('question_index', $index)->firstOrFail();
            $answer->forceFill($fields)->save();
        }

        return ['ok' => true, 'answer' => $answer];
    }

    /** How many students picked each option of a question. @return array<int, int> */
    public static function distribution(LmsLiveQuizRun $run, int $index): array
    {
        $q = $run->questions[$index] ?? null;
        $counts = array_fill(0, $q ? count((array) $q['options']) : 0, 0);
        LmsLiveQuizAnswer::query()
            ->where('run_id', $run->id)->where('question_index', $index)
            ->selectRaw('choice, COUNT(*) AS n')->groupBy('choice')->get()
            ->each(function ($row) use (&$counts): void {
                if (array_key_exists((int) $row->choice, $counts)) {
                    $counts[(int) $row->choice] = (int) $row->n;
                }
            });

        return $counts;
    }

    /**
     * Ranked totals: points desc, then total answer time asc.
     *
     * @return array<int, array{rank: int, student_id: int, name: string, points: int, correct: int, answered: int, you: bool}>
     */
    public static function standings(LmsLiveQuizRun $run, bool $fullNames, ?int $viewerId = null): array
    {
        $rows = LmsLiveQuizAnswer::query()
            ->where('run_id', $run->id)
            ->selectRaw('student_id, SUM(points) AS pts, SUM(correct) AS right_count, COUNT(*) AS answered, SUM(response_ms) AS ms')
            ->groupBy('student_id')
            ->orderByDesc('pts')->orderBy('ms')
            ->get();

        $students = LmsStudent::query()->whereIn('id', $rows->pluck('student_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $rows->values()->map(function ($r, $i) use ($students, $fullNames, $viewerId) {
            $s = $students->get($r->student_id);
            $first = trim((string) ($s->first_name ?? 'Student'));
            $last = trim((string) ($s->last_name ?? ''));

            return [
                'rank' => $i + 1,
                'student_id' => (int) $r->student_id,
                'name' => $fullNames ? trim("$first $last") : trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '')),
                'points' => (int) $r->pts,
                'correct' => (int) $r->right_count,
                'answered' => (int) $r->answered,
                'you' => $viewerId !== null && (int) $r->student_id === $viewerId,
            ];
        })->all();
    }

    /**
     * A student's live-quiz participation in their course, and the bonus it
     * earns on their overall progress (config saas.live_quiz_bonus_max, in
     * percentage points). Counted: quizzes actually played (finished, at least
     * one answer from anyone) in classes this student could attend; joined =
     * the ones they answered at least one question in.
     *
     * @return array{held: int, joined: int, bonus: int}
     */
    public static function participation(LmsStudent $student, int $courseId): array
    {
        $max = max(0.0, (float) config('saas.live_quiz_bonus_max', 5));

        // The classes this student could be in: their visible module classes,
        // plus the course's legacy classrooms unless they're one-on-one.
        $scheduledIds = LmsScheduledClass::query()
            ->whereHas('module', fn ($q) => $q->where('course_id', $courseId))
            ->visibleTo($student)
            ->pluck('id');
        $classroomIds = $student->isOneOnOne()
            ? collect()
            : LmsClassroom::query()->where('course_id', $courseId)->pluck('id');

        $runIds = LmsLiveQuizRun::query()
            ->where('course_id', $courseId)
            ->where('phase', LmsLiveQuizRun::FINISHED)
            ->where(function ($q) use ($scheduledIds, $classroomIds) {
                $q->where(fn ($w) => $w->where('class_type', 'scheduled')->whereIn('class_id', $scheduledIds))
                    ->orWhere(fn ($w) => $w->where('class_type', 'classroom')->whereIn('class_id', $classroomIds));
            })
            ->whereHas('answers')
            ->pluck('id');

        $held = $runIds->count();
        $joined = $held > 0
            ? LmsLiveQuizAnswer::query()->whereIn('run_id', $runIds)->where('student_id', $student->id)->distinct()->count('run_id')
            : 0;

        return [
            'held' => $held,
            'joined' => $joined,
            'bonus' => $held > 0 ? (int) round($max * $joined / $held) : 0,
        ];
    }

    /** Remember that a student's screen is following this run (for the teacher's "joined" count). */
    public static function markSeen(LmsLiveQuizRun $run, int $studentId): void
    {
        $key = "live-quiz:{$run->id}:seen";
        $seen = (array) Cache::get($key, []);
        $now = now()->getTimestamp();
        if (($seen[$studentId] ?? 0) > $now - 10) {
            return; // seen recently: skip the write
        }
        $seen[$studentId] = $now;
        Cache::put($key, $seen, now()->addHours(3));
    }

    /** Students whose screen followed the run in the last 30 seconds. */
    public static function joinedCount(LmsLiveQuizRun $run): int
    {
        $cutoff = now()->getTimestamp() - 30;

        return count(array_filter((array) Cache::get("live-quiz:{$run->id}:seen", []), fn ($t) => $t >= $cutoff));
    }

    /**
     * Validate and normalize a list of questions from the teacher.
     *
     * @return array{0: array<int, array{prompt: string, options: array<int, string>, correct_index: int, seconds: int}>, 1: ?string}
     */
    public static function cleanQuestions(mixed $input): array
    {
        if (! is_array($input) || count($input) < 1) {
            return [[], 'Add at least one question.'];
        }
        if (count($input) > 30) {
            return [[], 'A round can have up to 30 questions.'];
        }

        $out = [];
        foreach (array_values($input) as $i => $q) {
            $n = $i + 1;
            $prompt = trim((string) ($q['prompt'] ?? ''));
            // Not filtered: dropping a blank answer would shift correct_index.
            $options = array_values(array_map(fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])));
            $correct = $q['correct_index'] ?? null;
            $seconds = (int) ($q['seconds'] ?? 20);

            if ($prompt === '' || mb_strlen($prompt) > 300) {
                return [[], "Question {$n}: write the question (up to 300 characters)."];
            }
            if (count($options) < 2 || count($options) > 4) {
                return [[], "Question {$n}: give 2 to 4 answers."];
            }
            foreach ($options as $o) {
                if ($o === '' || mb_strlen($o) > 120) {
                    return [[], "Question {$n}: each answer needs text (up to 120 characters)."];
                }
            }
            if (! is_numeric($correct) || (int) $correct < 0 || (int) $correct >= count($options)) {
                return [[], "Question {$n}: pick the correct answer."];
            }

            $out[] = [
                'prompt' => $prompt,
                'options' => $options,
                'correct_index' => (int) $correct,
                'seconds' => in_array($seconds, self::SECONDS, true) ? $seconds : 20,
            ];
        }

        return [$out, null];
    }
}
