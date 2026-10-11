<?php

namespace App\Services;

use App\Models\LmsEnrollment;
use App\Models\LmsQuiz;
use App\Models\LmsQuizAttempt;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Module quizzes: marking, the time limit, and the scoreboard.
 *
 *   Marking   happens here, on the server, against correct_index; the correct
 *             answers never reach a student's browser before the quiz closes.
 *   Time      the attempt's clock starts on the server (started_at). With a
 *             time limit (or a close date) answers after the deadline are
 *             ignored, so a refresh or a changed phone clock buys nothing.
 *   Ranking   score (high first), then time taken (fast first), then who
 *             submitted first. The top 5 are highlighted.
 */
class Quizzes
{
    /** Answers sent this soon after the deadline still count (network lag). */
    public const GRACE_SECONDS = 15;

    public const TOP = 5;

    /** When this attempt must be in by, or null when there's no limit. */
    public static function deadline(LmsQuiz $quiz, LmsQuizAttempt $attempt): ?Carbon
    {
        $deadline = $quiz->time_limit_minutes
            ? $attempt->started_at->copy()->addMinutes($quiz->time_limit_minutes)
            : null;

        if ($quiz->closes_at && (! $deadline || $quiz->closes_at->lt($deadline))) {
            $deadline = $quiz->closes_at->copy();
        }

        return $deadline;
    }

    /** Whether answers can still be saved for this attempt. */
    public static function accepting(LmsQuiz $quiz, LmsQuizAttempt $attempt): bool
    {
        if ($attempt->isSubmitted()) {
            return false;
        }
        $deadline = static::deadline($quiz, $attempt);

        return ! $deadline || now()->lte($deadline->copy()->addSeconds(self::GRACE_SECONDS));
    }

    /**
     * Keep only valid answers: {question_id: option_index} for this quiz's
     * questions, with an index inside that question's options.
     *
     * @return array<string, int>
     */
    public static function cleanAnswers(LmsQuiz $quiz, array $answers): array
    {
        $questions = $quiz->questions->keyBy('id');
        $clean = [];
        foreach ($answers as $questionId => $index) {
            $q = $questions->get((int) $questionId);
            if ($q && is_numeric($index) && (int) $index >= 0 && (int) $index < count((array) $q->options)) {
                $clean[(string) $q->id] = (int) $index;
            }
        }

        return $clean;
    }

    /** Mark and close an attempt (idempotent). */
    public static function submit(LmsQuiz $quiz, LmsQuizAttempt $attempt): LmsQuizAttempt
    {
        if ($attempt->isSubmitted()) {
            return $attempt;
        }

        $answers = (array) ($attempt->answers ?? []);
        $score = 0;
        foreach ($quiz->questions as $q) {
            if (array_key_exists((string) $q->id, $answers) && (int) $answers[(string) $q->id] === (int) $q->correct_index) {
                $score++;
            }
        }

        // Time taken stops at the deadline, so a late tab can't look slower
        // (or faster) than the rules allow.
        $end = now();
        $deadline = static::deadline($quiz, $attempt);
        if ($deadline && $end->gt($deadline)) {
            $end = $deadline;
        }

        $attempt->forceFill([
            'submitted_at' => now(),
            'score' => $score,
            'total' => $quiz->questions->count(),
            'duration_seconds' => max(0, (int) $attempt->started_at->diffInSeconds($end)),
        ])->save();

        return $attempt;
    }

    /**
     * The ranked scoreboard: everyone who has submitted.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function scoreboard(LmsQuiz $quiz, bool $fullNames = false, ?int $viewerStudentId = null): Collection
    {
        return LmsQuizAttempt::query()
            ->with('student:id,first_name,last_name,email')
            ->where('quiz_id', $quiz->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('score')
            ->orderBy('duration_seconds')
            ->orderBy('submitted_at')
            ->get()
            ->values()
            ->map(fn (LmsQuizAttempt $a, int $i) => [
                'rank' => $i + 1,
                'name' => $a->student
                    ? ($fullNames ? static::fullName($a->student) : static::shortName($a->student))
                    : 'Student',
                'score' => (int) $a->score,
                'total' => (int) $a->total,
                'percent' => $a->total ? (int) round($a->score / $a->total * 100) : 0,
                'duration_seconds' => (int) $a->duration_seconds,
                'top' => $i < self::TOP,
                'you' => $viewerStudentId !== null && (int) $a->student_id === $viewerStudentId,
            ]);
    }

    /** "Ada O." on the student scoreboard. */
    public static function shortName(LmsStudent $s): string
    {
        $first = trim((string) $s->first_name);
        $last = trim((string) $s->last_name);
        if ($first === '') {
            return 'Student';
        }

        return $last !== '' ? $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : $first;
    }

    public static function fullName(LmsStudent $s): string
    {
        return trim($s->first_name . ' ' . $s->last_name) ?: (string) $s->email;
    }

    /** The course a student takes (their chosen course, else their cohort's). */
    public static function studentCourseId(LmsStudent $student): ?int
    {
        if ($student->selected_course_id) {
            return (int) $student->selected_course_id;
        }
        $trackId = LmsEnrollment::query()->where('student_id', $student->id)->value('track_id');
        $courseId = $trackId ? LmsTrack::query()->whereKey($trackId)->value('course_id') : null;

        return $courseId ? (int) $courseId : null;
    }
}
