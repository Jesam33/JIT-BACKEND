<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsQuiz;
use App\Models\LmsQuizAttempt;
use App\Models\LmsQuizQuestion;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Services\Quizzes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's module quizzes: the list, taking one (one question per screen,
 * answers saved as they go, a server-side clock), the result, and the class
 * scoreboard. One attempt per quiz. The correct answers are only shown once
 * the quiz has closed, so they can't be passed to classmates still taking it.
 *
 * Never answers 402 (the student fetch helper treats 402 as "billing paused"):
 * on a plan without quizzes the list simply says they're unavailable.
 */
class StudentQuizController extends BaseLmsController
{
    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        if (! $this->enabled($student)) {
            return response()->json(['available' => false, 'quizzes' => []]);
        }

        $courseId = Quizzes::studentCourseId($student);
        $quizzes = $courseId
            ? LmsQuiz::query()
                ->with('module:id,title,sort_order')
                ->where('course_id', $courseId)
                ->where('status', LmsQuiz::STATUS_PUBLISHED)
                ->get()
                ->sortBy(fn (LmsQuiz $q) => $q->module?->sort_order ?? 0)
                ->values()
            : collect();

        $attempts = LmsQuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->get()
            ->keyBy('quiz_id');

        return response()->json([
            'available' => true,
            'quizzes' => $quizzes->map(fn (LmsQuiz $q) => $this->summary($q, $attempts->get($q->id)))->values(),
        ]);
    }

    /** One quiz: its details, my state, and (once closed) the answers. */
    public function show(Request $request, int $quizId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $quiz = $this->myQuiz($student, $quizId);
        $attempt = $this->attempt($quiz, $student);

        // Ran out of time without pressing Submit: mark what was saved.
        if ($attempt && ! $attempt->isSubmitted() && ! Quizzes::accepting($quiz, $attempt)) {
            $attempt = Quizzes::submit($quiz, $attempt);
        }

        $payload = $this->summary($quiz, $attempt) + [
            'instructions' => $quiz->instructions,
            'server_now' => now()->toIso8601String(),
        ];

        if ($attempt && ! $attempt->isSubmitted()) {
            $payload['attempt'] = $this->attemptPayload($quiz, $attempt);
        }

        if ($attempt && $attempt->isSubmitted() && $quiz->isClosed()) {
            $payload['review'] = $this->review($quiz, $attempt);
        }

        return response()->json($payload);
    }

    /** Start (or resume) my attempt: the questions, without the answers. */
    public function start(Request $request, int $quizId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $quiz = $this->myQuiz($student, $quizId);
        $attempt = $this->attempt($quiz, $student);

        if ($attempt && $attempt->isSubmitted()) {
            return response()->json(['message' => 'You have already taken this quiz.'] + $this->summary($quiz, $attempt), 409);
        }
        if (! $attempt && ! $quiz->isOpen()) {
            return response()->json(['message' => 'This quiz has closed.'], 422);
        }

        if (! $attempt) {
            // One attempt per student: the unique index settles a double tap.
            try {
                $attempt = LmsQuizAttempt::createForTenant((int) $quiz->tenant_id, [
                    'quiz_id' => $quiz->id,
                    'student_id' => $student->id,
                    'started_at' => now(),
                    'answers' => [],
                    'total' => $quiz->questions->count(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt = $this->attempt($quiz, $student);
            }
        }

        if (! Quizzes::accepting($quiz, $attempt)) {
            $attempt = Quizzes::submit($quiz, $attempt);

            return response()->json(['message' => 'Time is up for this quiz.'] + $this->summary($quiz, $attempt), 409);
        }

        return response()->json([
            'attempt' => $this->attemptPayload($quiz, $attempt),
            'server_now' => now()->toIso8601String(),
        ]);
    }

    /** Save answers as the student goes (so a dropped connection loses nothing). */
    public function answer(Request $request, int $quizId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $quiz = $this->myQuiz($student, $quizId);
        $attempt = $this->attempt($quiz, $student);

        $validated = $request->validate(['answers' => ['required', 'array']]);

        if (! $attempt || ! Quizzes::accepting($quiz, $attempt)) {
            return response()->json(['message' => 'This attempt can no longer be changed.'], 409);
        }

        $attempt->forceFill([
            'answers' => array_replace((array) ($attempt->answers ?? []), Quizzes::cleanAnswers($quiz, $validated['answers'])),
        ])->save();

        return response()->json(['saved' => count((array) $attempt->answers)]);
    }

    /** Submit: mark it and return my score. */
    public function submit(Request $request, int $quizId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $quiz = $this->myQuiz($student, $quizId);
        $attempt = $this->attempt($quiz, $student);

        if (! $attempt) {
            return response()->json(['message' => 'Start the quiz first.'], 422);
        }

        // Last answers ride along with Submit, unless the time is already up.
        if (! $attempt->isSubmitted() && Quizzes::accepting($quiz, $attempt) && is_array($request->input('answers'))) {
            $attempt->forceFill([
                'answers' => array_replace((array) ($attempt->answers ?? []), Quizzes::cleanAnswers($quiz, $request->input('answers'))),
            ])->save();
        }

        $attempt = Quizzes::submit($quiz, $attempt);

        return response()->json($this->summary($quiz, $attempt));
    }

    /** The class scoreboard (first name + last initial), with "you" marked. */
    public function scoreboard(Request $request, int $quizId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $quiz = $this->myQuiz($student, $quizId);

        return response()->json([
            'quiz' => ['id' => $quiz->id, 'title' => $quiz->title],
            'rows' => Quizzes::scoreboard($quiz, false, (int) $student->id),
            'top' => Quizzes::TOP,
        ]);
    }

    private function summary(LmsQuiz $quiz, ?LmsQuizAttempt $attempt): array
    {
        $state = match (true) {
            $attempt && $attempt->isSubmitted() => 'submitted',
            (bool) $attempt => 'in_progress',
            $quiz->isClosed() => 'closed',
            default => 'not_started',
        };

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'module_title' => $quiz->module?->title,
            'questions' => LmsQuiz::QUESTIONS,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'closes_at' => $quiz->closes_at?->toIso8601String(),
            'closed' => $quiz->isClosed(),
            'state' => $state,
            'score' => $attempt?->isSubmitted() ? (int) $attempt->score : null,
            'total' => $attempt?->isSubmitted() ? (int) $attempt->total : null,
            'duration_seconds' => $attempt?->isSubmitted() ? (int) $attempt->duration_seconds : null,
        ];
    }

    private function attemptPayload(LmsQuiz $quiz, LmsQuizAttempt $attempt): array
    {
        return [
            'started_at' => $attempt->started_at->toIso8601String(),
            'deadline' => Quizzes::deadline($quiz, $attempt)?->toIso8601String(),
            'answers' => (object) ($attempt->answers ?? []),
            // Questions WITHOUT the correct answer.
            'questions' => $quiz->questions->map(fn (LmsQuizQuestion $q) => [
                'id' => $q->id,
                'prompt' => $q->prompt,
                'options' => array_values((array) $q->options),
            ])->values(),
        ];
    }

    /** After the quiz closes: every question with my answer and the right one. */
    private function review(LmsQuiz $quiz, LmsQuizAttempt $attempt): array
    {
        $answers = (array) ($attempt->answers ?? []);

        return $quiz->questions->map(fn (LmsQuizQuestion $q) => [
            'id' => $q->id,
            'prompt' => $q->prompt,
            'options' => array_values((array) $q->options),
            'your_answer' => array_key_exists((string) $q->id, $answers) ? (int) $answers[(string) $q->id] : null,
            'correct_index' => (int) $q->correct_index,
            'explanation' => $q->explanation,
        ])->values()->all();
    }

    private function attempt(LmsQuiz $quiz, LmsStudent $student): ?LmsQuizAttempt
    {
        return LmsQuizAttempt::query()->where('quiz_id', $quiz->id)->where('student_id', $student->id)->first();
    }

    /** A published quiz of my own course, or 404. */
    private function myQuiz(LmsStudent $student, int $quizId): LmsQuiz
    {
        if (! $this->enabled($student)) {
            abort(404);
        }

        return LmsQuiz::query()
            ->with(['questions', 'module:id,title'])
            ->where('status', LmsQuiz::STATUS_PUBLISHED)
            ->where('course_id', Quizzes::studentCourseId($student) ?? 0)
            ->findOrFail($quizId);
    }

    private function enabled(LmsStudent $student): bool
    {
        $tenant = $student->tenant_id ? Tenant::find($student->tenant_id) : null;

        return ! $tenant || $tenant->planFeature('quizzes');
    }

    private function studentOrFail(Request $request): LmsStudent
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        $student = $session ? LmsStudent::query()->find($session->user_id) : null;
        if (! $student) {
            abort(401, 'Unauthorized');
        }

        return $student;
    }
}
