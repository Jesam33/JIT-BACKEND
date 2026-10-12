<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsLiveQuizAnswer;
use App\Models\LmsLiveQuizRun;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Services\LiveQuizzes;
use App\Support\LearnerProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live quizzes, student side: the class screen polls current() to follow the
 * teacher's quiz (question with a countdown → reveal with the right answer
 * and the top 5 → final podium) and posts answers. Only students of that
 * class's course take part, and they never see the right answer before the
 * question closes.
 *
 * Never answers 402 (the student fetch helper treats 402 as "billing
 * paused"): on a plan without quizzes there is simply no quiz running.
 */
class StudentLiveQuizController extends BaseLmsController
{
    public function current(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $data = $request->validate([
            'class_type' => ['required', 'in:scheduled,classroom'],
            'class_id' => ['required', 'integer'],
        ]);

        // The class screen polls this anyway, so it also says whether the
        // teacher is recording the class (on every plan, quizzes or not).
        $recording = StaffClassRecordingController::isRecording($data['class_type'], (int) $data['class_id']);

        if (! $this->enabled($student)) {
            return response()->json(['run' => null, 'recording' => $recording]);
        }

        $run = LiveQuizzes::activeRun($data['class_type'], (int) $data['class_id']);
        // Show the podium for a little while after the last question.
        $run ??= LmsLiveQuizRun::query()
            ->where('class_type', $data['class_type'])->where('class_id', (int) $data['class_id'])
            ->where('phase', LmsLiveQuizRun::FINISHED)->where('finished_at', '>=', now()->subMinutes(2))
            ->latest('id')->first();

        if (! $run || (int) $run->course_id !== LearnerProof::courseId($student)) {
            return response()->json(['run' => null, 'recording' => $recording]);
        }

        $run = LiveQuizzes::settle($run);
        LiveQuizzes::markSeen($run, (int) $student->id);

        return response()->json(['run' => $this->state($run, $student), 'recording' => $recording]);
    }

    public function answer(Request $request, int $runId): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $data = $request->validate([
            'question_index' => ['required', 'integer', 'min:0'],
            'choice' => ['required', 'integer', 'min:0', 'max:3'],
        ]);

        $run = LmsLiveQuizRun::query()->findOrFail($runId);
        if (! $this->enabled($student) || (int) $run->course_id !== LearnerProof::courseId($student)) {
            abort(404);
        }

        $result = LiveQuizzes::answer($run, $student, (int) $data['question_index'], (int) $data['choice']);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 409);
        }

        // Only that it was received: right or wrong is shown at the reveal.
        return response()->json(['received' => true, 'choice' => $result['answer']->choice]);
    }

    /** The student's view: the right answer and points appear only after the question closes. */
    private function state(LmsLiveQuizRun $run, LmsStudent $student): array
    {
        $index = $run->current_index;
        $q = $run->currentQuestion();
        $mine = $q
            ? LmsLiveQuizAnswer::query()->where('run_id', $run->id)->where('student_id', $student->id)->where('question_index', $index)->first()
            : null;

        $state = [
            'id' => $run->id,
            'title' => $run->title,
            'phase' => $run->phase,
            'index' => $index,
            'total' => count((array) $run->questions),
            'server_now' => now()->toIso8601String(),
        ];

        if ($run->phase === LmsLiveQuizRun::QUESTION && $q) {
            $state['question'] = ['prompt' => $q['prompt'], 'options' => $q['options'], 'seconds' => (int) $q['seconds']];
            $state['deadline'] = LiveQuizzes::deadline($run)?->toIso8601String();
            $state['my_choice'] = $mine?->choice;
        }

        if ($run->phase === LmsLiveQuizRun::REVEAL && $q) {
            $state['question'] = ['prompt' => $q['prompt'], 'options' => $q['options'], 'seconds' => (int) $q['seconds']];
            $state['correct_index'] = (int) $q['correct_index'];
            $state['my_choice'] = $mine?->choice;
            $state['my_correct'] = $mine ? $mine->correct : null;
            $state['my_points'] = $mine?->points ?? 0;
            $state['distribution'] = LiveQuizzes::distribution($run, $index);
        }

        if (in_array($run->phase, [LmsLiveQuizRun::REVEAL, LmsLiveQuizRun::FINISHED], true)) {
            $standings = LiveQuizzes::standings($run, false, (int) $student->id);
            $state['top'] = array_slice(array_map(fn ($r) => array_diff_key($r, ['student_id' => 1]), $standings), 0, LiveQuizzes::TOP);
            $me = collect($standings)->firstWhere('you', true);
            $state['me'] = $me ? ['rank' => $me['rank'], 'points' => $me['points'], 'correct' => $me['correct'], 'of' => count($standings)] : null;
        }

        return $state;
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
