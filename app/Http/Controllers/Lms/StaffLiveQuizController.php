<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsClassroom;
use App\Models\LmsCourse;
use App\Models\LmsLiveQuizRound;
use App\Models\LmsLiveQuizRun;
use App\Models\LmsScheduledClass;
use App\Models\LmsTeacher;
use App\Services\LiveQuizzes;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live quizzes, teacher side: save rounds of questions per course, start one
 * inside a live class, and drive it (next question, reveal, auto-advance,
 * end) from the control panel while students answer on their class screen.
 * Basic plan and above (feature `quizzes`). A teacher works on the courses
 * they teach; the academy owner on every course.
 */
class StaffLiveQuizController extends BaseLmsController
{
    /** The control panel for one class: the class, its course's saved rounds, the running quiz and past ones. */
    public function forClass(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        [$type, $id] = $this->classRef($request);
        $courseId = $this->myClassCourse($actor, $type, $id);

        $run = LiveQuizzes::activeRun($type, $id);

        return response()->json([
            'class' => [
                'class_type' => $type,
                'class_id' => $id,
                'title' => $this->classTitle($type, $id),
                'course_id' => $courseId,
                'course_title' => LmsCourse::query()->whereKey($courseId)->value('title'),
            ],
            'rounds' => $this->roundsFor($courseId),
            'run' => $run ? $this->state(LiveQuizzes::settle($run)) : null,
            'past_runs' => LmsLiveQuizRun::query()
                ->where('class_type', $type)->where('class_id', $id)->where('phase', LmsLiveQuizRun::FINISHED)
                ->latest('id')->limit(10)->get()
                ->map(fn (LmsLiveQuizRun $r) => [
                    'id' => $r->id, 'title' => $r->title, 'questions' => count((array) $r->questions),
                    'players' => $r->answers()->distinct('student_id')->count('student_id'),
                    'finished_at' => $r->finished_at?->toIso8601String(),
                ])->values(),
            'seconds_options' => LiveQuizzes::SECONDS,
        ]);
    }

    public function storeRound(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'questions' => ['required', 'array'],
        ]);
        abort_unless(in_array((int) $data['course_id'], array_map('intval', $this->actorCourseIds($actor)), true), 404);

        [$questions, $error] = LiveQuizzes::cleanQuestions($data['questions']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $round = LmsLiveQuizRound::createForTenant((int) ($tenant?->id ?? LmsCourse::query()->whereKey($data['course_id'])->value('tenant_id')), [
            'course_id' => (int) $data['course_id'],
            'title' => trim($data['title']),
            'questions' => $questions,
            'created_by' => $actor->id,
        ]);

        return response()->json(['round' => $this->roundPayload($round)], 201);
    }

    public function updateRound(Request $request, int $roundId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $round = $this->myRound($actor, $roundId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'questions' => ['required', 'array'],
        ]);

        [$questions, $error] = LiveQuizzes::cleanQuestions($data['questions']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }
        $round->forceFill(['title' => trim($data['title']), 'questions' => $questions])->save();

        return response()->json(['round' => $this->roundPayload($round)]);
    }

    public function destroyRound(Request $request, int $roundId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        // Past runs keep their own copy of the questions, so their results survive.
        $this->myRound($actor, $roundId)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Start a saved round in a live class. Any quiz still running there is ended first. */
    public function start(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        [$type, $id] = $this->classRef($request);
        $courseId = $this->myClassCourse($actor, $type, $id);
        $data = $request->validate([
            'round_id' => ['required', 'integer'],
            'auto_advance' => ['nullable', 'boolean'],
        ]);

        $round = $this->myRound($actor, (int) $data['round_id']);
        if ((int) $round->course_id !== $courseId) {
            return response()->json(['message' => 'That round belongs to a different course.'], 422);
        }

        LmsLiveQuizRun::query()
            ->where('class_type', $type)->where('class_id', $id)->where('phase', '!=', LmsLiveQuizRun::FINISHED)
            ->get()->each(fn (LmsLiveQuizRun $r) => LiveQuizzes::finish($r));

        $run = LmsLiveQuizRun::createForTenant((int) $round->tenant_id, [
            'round_id' => $round->id,
            'course_id' => $courseId,
            'class_type' => $type,
            'class_id' => $id,
            'title' => $round->title,
            'questions' => $round->questions,
            'phase' => LmsLiveQuizRun::LOBBY,
            'current_index' => -1,
            'phase_started_at' => now(),
            'auto_advance' => (bool) ($data['auto_advance'] ?? false),
            'started_by' => $actor->id,
        ]);

        return response()->json(['run' => $this->state($run)], 201);
    }

    public function show(Request $request, int $runId): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        return response()->json(['run' => $this->state(LiveQuizzes::settle($this->myRun($actor, $runId)))]);
    }

    public function next(Request $request, int $runId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $run = $this->myRun($actor, $runId);
        if ($run->isFinished()) {
            return response()->json(['message' => 'This quiz has ended.'], 409);
        }

        return response()->json(['run' => $this->state(LiveQuizzes::advance($run))]);
    }

    public function reveal(Request $request, int $runId): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        return response()->json(['run' => $this->state(LiveQuizzes::reveal($this->myRun($actor, $runId)))]);
    }

    public function finish(Request $request, int $runId): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        return response()->json(['run' => $this->state(LiveQuizzes::finish($this->myRun($actor, $runId)))]);
    }

    public function autoAdvance(Request $request, int $runId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $run = $this->myRun($actor, $runId);
        $run->forceFill(['auto_advance' => (bool) $data['on']])->save();

        return response()->json(['run' => $this->state(LiveQuizzes::settle($run))]);
    }

    /** The teacher's view of a run: everything, including the right answer and full names. */
    private function state(LmsLiveQuizRun $run): array
    {
        $total = count((array) $run->questions);
        $q = $run->currentQuestion();
        $deadline = LiveQuizzes::deadline($run);
        $index = $run->current_index;

        $state = [
            'id' => $run->id,
            'title' => $run->title,
            'phase' => $run->phase,
            'index' => $index,
            'total' => $total,
            'auto_advance' => $run->auto_advance,
            'server_now' => now()->toIso8601String(),
            'deadline' => $deadline?->toIso8601String(),
            'joined' => LiveQuizzes::joinedCount($run),
            'question' => $q && in_array($run->phase, [LmsLiveQuizRun::QUESTION, LmsLiveQuizRun::REVEAL], true) ? $q : null,
            'next_question' => $run->questions[$index + 1]['prompt'] ?? null,
            'distribution' => $q ? LiveQuizzes::distribution($run, $index) : [],
            'answered' => $q ? array_sum(LiveQuizzes::distribution($run, $index)) : 0,
            'standings' => LiveQuizzes::standings($run, true),
        ];

        if ($run->isFinished()) {
            // How the class did on each question, for "re-teach Q4".
            $state['per_question'] = collect((array) $run->questions)->map(function ($question, $i) use ($run) {
                $dist = LiveQuizzes::distribution($run, $i);
                $answered = array_sum($dist);
                $right = $dist[(int) $question['correct_index']] ?? 0;

                return [
                    'prompt' => $question['prompt'],
                    'answered' => $answered,
                    'correct' => $right,
                    'percent' => $answered > 0 ? (int) round($right / $answered * 100) : 0,
                ];
            })->values();
        }

        return $state;
    }

    private function roundsFor(int $courseId): array
    {
        return LmsLiveQuizRound::query()->where('course_id', $courseId)->latest('updated_at')->get()
            ->map(fn (LmsLiveQuizRound $r) => $this->roundPayload($r))->values()->all();
    }

    private function roundPayload(LmsLiveQuizRound $r): array
    {
        return ['id' => $r->id, 'course_id' => $r->course_id, 'title' => $r->title, 'questions' => $r->questions, 'updated_at' => $r->updated_at?->toIso8601String()];
    }

    /** @return array{0: string, 1: int} */
    private function classRef(Request $request): array
    {
        $data = $request->validate([
            'class_type' => ['required', 'in:scheduled,classroom'],
            'class_id' => ['required', 'integer'],
        ]);

        return [$data['class_type'], (int) $data['class_id']];
    }

    private function myClassCourse(LmsTeacher $actor, string $type, int $id): int
    {
        $courseId = LiveQuizzes::classCourseId($type, $id);
        abort_unless($courseId && in_array($courseId, array_map('intval', $this->actorCourseIds($actor)), true), 404, 'Class not found.');

        return $courseId;
    }

    private function classTitle(string $type, int $id): ?string
    {
        return $type === 'scheduled'
            ? LmsScheduledClass::query()->whereKey($id)->value('title')
            : LmsClassroom::query()->whereKey($id)->value('title');
    }

    private function myRound(LmsTeacher $actor, int $id): LmsLiveQuizRound
    {
        return LmsLiveQuizRound::query()->whereIn('course_id', $this->actorCourseIds($actor))->findOrFail($id);
    }

    private function myRun(LmsTeacher $actor, int $id): LmsLiveQuizRun
    {
        return LmsLiveQuizRun::query()->whereIn('course_id', $this->actorCourseIds($actor))->findOrFail($id);
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
}
