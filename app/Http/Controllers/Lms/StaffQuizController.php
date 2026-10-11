<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourse;
use App\Models\LmsModule;
use App\Models\LmsQuiz;
use App\Models\LmsQuizAttempt;
use App\Models\LmsQuizQuestion;
use App\Models\LmsTeacher;
use App\Services\Quizzes;
use App\Support\Notify;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Teachers write one quiz per module (5 multiple-choice questions), publish
 * it to the course's students, and see the results. Paid plans only
 * (plan feature `quizzes`). A teacher works on the courses they teach; the
 * academy owner on every course.
 */
class StaffQuizController extends BaseLmsController
{
    /** Every module of my courses, with its quiz (if any) and how it's going. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $courseIds = $this->actorCourseIds($actor);

        $modules = LmsModule::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('course_id')->orderBy('sort_order')
            ->get(['id', 'course_id', 'title']);

        $courses = LmsCourse::query()->whereIn('id', $courseIds)->pluck('title', 'id');
        $quizzes = LmsQuiz::query()->whereIn('module_id', $modules->pluck('id'))->get()->keyBy('module_id');

        $stats = LmsQuizAttempt::query()
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, COUNT(*) as taken, AVG(score) as avg_score')
            ->groupBy('quiz_id')
            ->get()
            ->keyBy('quiz_id');

        return response()->json([
            'modules' => $modules->map(function (LmsModule $m) use ($courses, $quizzes, $stats) {
                $quiz = $quizzes->get($m->id);
                $stat = $quiz ? $stats->get($quiz->id) : null;

                return [
                    'module_id' => $m->id,
                    'module_title' => $m->title,
                    'course_id' => $m->course_id,
                    'course_title' => $courses[$m->course_id] ?? null,
                    'quiz' => $quiz ? [
                        'id' => $quiz->id,
                        'title' => $quiz->title,
                        'status' => $quiz->status,
                        'closes_at' => $quiz->closes_at?->toIso8601String(),
                        'closed' => $quiz->isClosed(),
                        'taken' => (int) ($stat->taken ?? 0),
                        'average' => $stat ? round((float) $stat->avg_score, 1) : null,
                    ] : null,
                ];
            })->values(),
            'questions_per_quiz' => LmsQuiz::QUESTIONS,
        ]);
    }

    /** One module's quiz with its questions (including the answers), or null. */
    public function show(Request $request, int $moduleId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $module = $this->myModule($actor, $moduleId);
        $quiz = LmsQuiz::query()->with('questions')->where('module_id', $module->id)->first();

        return response()->json([
            'module' => ['id' => $module->id, 'title' => $module->title],
            'quiz' => $quiz ? $this->quizPayload($quiz) : null,
        ]);
    }

    /**
     * Create or update a module's quiz. Questions can't change once students
     * have taken it (their scores would no longer match); the title,
     * instructions and close date still can.
     */
    public function save(Request $request, int $moduleId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $module = $this->myModule($actor, $moduleId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
            'closes_at' => ['nullable', 'date'],
            'questions' => ['required', 'array', 'size:' . LmsQuiz::QUESTIONS],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:4'],
            'questions.*.options.*' => ['required', 'string', 'max:300'],
            'questions.*.correct_index' => ['required', 'integer', 'min:0', 'max:3'],
            'questions.*.explanation' => ['nullable', 'string', 'max:1000'],
        ], [
            'questions.size' => 'A quiz has exactly ' . LmsQuiz::QUESTIONS . ' questions.',
            'questions.*.prompt.required' => 'Every question needs its text.',
            'questions.*.options.min' => 'Every question needs at least 2 answer options.',
            'questions.*.options.*.required' => 'Fill in every answer option (or remove the empty one).',
            'questions.*.correct_index.required' => 'Pick the correct answer for every question.',
        ]);

        foreach ($validated['questions'] as $i => $q) {
            if ((int) $q['correct_index'] >= count($q['options'])) {
                return response()->json([
                    'message' => 'Question ' . ($i + 1) . ': pick one of its answer options as the correct one.',
                ], 422);
            }
        }

        $closesAt = ! empty($validated['closes_at'])
            ? Carbon::parse($validated['closes_at'])->setTimezone(config('app.timezone'))
            : null;

        $quiz = DB::transaction(function () use ($module, $actor, $validated, $closesAt) {
            $quiz = LmsQuiz::query()->with('questions')->where('module_id', $module->id)->lockForUpdate()->first();
            $taken = $quiz ? LmsQuizAttempt::query()->where('quiz_id', $quiz->id)->exists() : false;

            $attributes = [
                'title' => $validated['title'],
                'instructions' => $validated['instructions'] ?? null,
                'closes_at' => $closesAt,
            ];

            if (! $quiz) {
                $quiz = LmsQuiz::createForTenant((int) $module->tenant_id, $attributes + [
                    'course_id' => $module->course_id,
                    'module_id' => $module->id,
                    'time_limit_minutes' => $validated['time_limit_minutes'] ?? null,
                    'status' => LmsQuiz::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ]);
            } else {
                if ($taken && $this->questionsChanged($quiz, $validated['questions'], $validated['time_limit_minutes'] ?? null)) {
                    abort(response()->json([
                        'message' => 'Students have already taken this quiz, so its questions and time limit can\'t change. You can still edit the title, instructions and close date.',
                    ], 422));
                }
                $quiz->update($attributes + ($taken ? [] : ['time_limit_minutes' => $validated['time_limit_minutes'] ?? null]));
            }

            if (! $taken) {
                LmsQuizQuestion::query()->where('quiz_id', $quiz->id)->delete();
                foreach (array_values($validated['questions']) as $i => $q) {
                    LmsQuizQuestion::createForTenant((int) $quiz->tenant_id, [
                        'quiz_id' => $quiz->id,
                        'position' => $i + 1,
                        'prompt' => trim($q['prompt']),
                        'options' => array_values(array_map('trim', $q['options'])),
                        'correct_index' => (int) $q['correct_index'],
                        'explanation' => isset($q['explanation']) ? trim((string) $q['explanation']) ?: null : null,
                    ]);
                }
            }

            return $quiz;
        });

        return response()->json(['quiz' => $this->quizPayload($quiz->fresh('questions'))]);
    }

    /** Open the quiz to the course's students and tell them. */
    public function publish(Request $request, int $quizId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $quiz = $this->myQuiz($actor, $quizId);

        if ($quiz->isPublished()) {
            return response()->json(['message' => 'This quiz is already published.'], 422);
        }
        if ($quiz->questions()->count() !== LmsQuiz::QUESTIONS) {
            return response()->json(['message' => 'Add all ' . LmsQuiz::QUESTIONS . ' questions before publishing.'], 422);
        }
        if ($quiz->closes_at && $quiz->closes_at->isPast()) {
            return response()->json(['message' => 'The close date has already passed. Set a later one first.'], 422);
        }

        $quiz->update(['status' => LmsQuiz::STATUS_PUBLISHED, 'published_at' => now()]);

        $studentIds = $this->studentIdsInCourses([(int) $quiz->course_id]);
        if ($studentIds) {
            Notify::student(
                $studentIds,
                'quiz_published',
                'New quiz: ' . $quiz->title,
                LmsQuiz::QUESTIONS . ' questions on ' . ($quiz->module?->title ?? 'your module') . '.'
                    . ($quiz->time_limit_minutes ? ' You have ' . $quiz->time_limit_minutes . ' minutes once you start.' : '')
                    . ($quiz->closes_at ? ' Closes ' . $quiz->closes_at->copy()->setTimezone((string) config('saas.monthly_reminder_timezone', 'Africa/Lagos'))->format('l j M, g:ia') . '.' : '')
                    . ' Your score goes on the class scoreboard.',
                'quiz',
                $quiz->id
            );
        }

        return response()->json(['quiz' => $this->quizPayload($quiz->fresh('questions'))]);
    }

    /** Close it now (answers become visible to students). */
    public function close(Request $request, int $quizId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $quiz = $this->myQuiz($actor, $quizId);
        $quiz->update(['closes_at' => now()]);

        return response()->json(['quiz' => $this->quizPayload($quiz->fresh('questions'))]);
    }

    /** Delete a quiz nobody has taken yet. */
    public function destroy(Request $request, int $quizId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $quiz = $this->myQuiz($actor, $quizId);

        if (LmsQuizAttempt::query()->where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['message' => 'Students have already taken this quiz, so it can\'t be deleted. Close it instead.'], 422);
        }

        $quiz->delete();

        return response()->json(['ok' => true]);
    }

    /** The scoreboard with full names, plus who hasn't finished. */
    public function results(Request $request, int $quizId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $quiz = $this->myQuiz($actor, $quizId);

        $inProgress = LmsQuizAttempt::query()->where('quiz_id', $quiz->id)->whereNull('submitted_at')->count();
        $enrolled = count($this->studentIdsInCourses([(int) $quiz->course_id]));

        return response()->json([
            'quiz' => $this->quizPayload($quiz->load('questions')),
            'scoreboard' => Quizzes::scoreboard($quiz, true),
            'in_progress' => $inProgress,
            'enrolled' => $enrolled,
        ]);
    }

    private function actorOrFail(Request $request): LmsTeacher
    {
        $this->ensureLmsEnabled();
        $actor = $this->staffActor($request);
        if (! $actor) {
            abort(401, 'Unauthorized');
        }

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if ($tenant) {
            PlanGate::ensureFeature($tenant, 'quizzes');
        }

        return $actor;
    }

    private function myModule(LmsTeacher $actor, int $moduleId): LmsModule
    {
        return LmsModule::query()->whereIn('course_id', $this->actorCourseIds($actor))->findOrFail($moduleId);
    }

    private function myQuiz(LmsTeacher $actor, int $quizId): LmsQuiz
    {
        return LmsQuiz::query()->with('questions', 'module:id,title')->whereIn('course_id', $this->actorCourseIds($actor))->findOrFail($quizId);
    }

    /** @param array<int, array<string, mixed>> $questions */
    private function questionsChanged(LmsQuiz $quiz, array $questions, ?int $timeLimit): bool
    {
        if ((int) $quiz->time_limit_minutes !== (int) $timeLimit) {
            return true;
        }

        $current = $quiz->questions->map(fn ($q) => [trim($q->prompt), array_values($q->options), (int) $q->correct_index])->values()->all();
        $incoming = collect($questions)->values()->map(fn ($q) => [trim($q['prompt']), array_values(array_map('trim', $q['options'])), (int) $q['correct_index']])->all();

        return $current !== $incoming;
    }

    private function quizPayload(LmsQuiz $quiz): array
    {
        return [
            'id' => $quiz->id,
            'module_id' => $quiz->module_id,
            'course_id' => $quiz->course_id,
            'title' => $quiz->title,
            'instructions' => $quiz->instructions,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'closes_at' => $quiz->closes_at?->toIso8601String(),
            'closed' => $quiz->isClosed(),
            'status' => $quiz->status,
            'published_at' => $quiz->published_at?->toIso8601String(),
            'taken' => LmsQuizAttempt::query()->where('quiz_id', $quiz->id)->exists(),
            'questions' => $quiz->questions->map(fn (LmsQuizQuestion $q) => [
                'id' => $q->id,
                'prompt' => $q->prompt,
                'options' => array_values((array) $q->options),
                'correct_index' => (int) $q->correct_index,
                'explanation' => $q->explanation,
            ])->values(),
        ];
    }
}
