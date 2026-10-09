<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Payment;
use App\Services\OneOnOneBooking;
use App\Services\OneOnOneOutcomes;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The owner's view of all one-on-one teaching for a month: per teacher, per
 * student and per session, so they can check teachers deliver what students
 * paid for. The owner also decides outcomes here (their decision overrides the
 * teacher's), including students' "my teacher didn't come" reports.
 *
 * Session states (counted by start time, academy timezone):
 *   upcoming   not cancelled, not started yet
 *   held       started, not cancelled, outcome held or not marked
 *   student / teacher no-show   by outcome
 *   unmarked   started, not cancelled, no outcome recorded
 */
class OwnerOneOnOneController extends OwnerAdminController
{
    public function overview(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }
        [$tenant] = $context;

        // Basic and up. An academy that downgraded keeps seeing its existing
        // one-on-one students here; only one that never had any is sent to upgrade.
        if (! $tenant->planFeature('one_on_one') && ! LmsStudent::query()->where('learning_mode', LmsStudent::MODE_ONE_ON_ONE)->exists()) {
            PlanGate::ensureFeature($tenant, 'one_on_one');
        }

        $tz = OneOnOneBooking::tz();
        $month = (string) $request->query('month', '');
        $start = preg_match('/^\d{4}-\d{2}$/', $month)
            ? Carbon::createFromFormat('Y-m-d H:i', $month . '-01 00:00', $tz)
            : now()->setTimezone($tz)->startOfMonth();
        $end = $start->copy()->addMonth();

        $sessions = LmsScheduledClass::query()
            ->with(['module:id,title', 'teacher:id,name', 'student:id,first_name,last_name,email'])
            ->whereNotNull('student_id')
            ->where('starts_at', '>=', $start->copy()->utc())
            ->where('starts_at', '<', $end->copy()->utc())
            ->orderByDesc('starts_at')
            ->get();

        $students = LmsStudent::query()
            ->where('learning_mode', LmsStudent::MODE_ONE_ON_ONE)
            ->with(['oneOnOneTeacher:id,name', 'course:id,title,one_on_one_session_limit,billing_type'])
            ->orderBy('first_name')
            ->get();

        $teacherIds = $sessions->pluck('teacher_id')
            ->merge($students->pluck('one_on_one_teacher_id'))
            ->filter()->unique()->values();
        $teachers = LmsTeacher::query()->whereIn('id', $teacherIds)->orderBy('name')->get(['id', 'name']);

        $byTeacher = $sessions->groupBy('teacher_id');
        $byStudent = $sessions->groupBy('student_id');

        $extraRevenue = (float) Payment::query()
            ->where('kind', Payment::KIND_EXTRA_SESSIONS)
            ->where('status', 'success')
            ->where('updated_at', '>=', $start->copy()->utc())
            ->where('updated_at', '<', $end->copy()->utc())
            ->sum('amount');

        $midMonth = $start->copy()->addDays(14);

        return response()->json([
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'timezone' => $tz,
            'totals' => $this->counts($sessions) + [
                'reports_pending' => $sessions->filter(fn ($s) => OneOnOneOutcomes::reportPending($s))->count(),
                'students' => $students->count(),
                'extra_revenue' => $extraRevenue,
            ],
            'teachers' => $teachers->map(fn (LmsTeacher $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'students' => $students->where('one_on_one_teacher_id', $t->id)->count(),
            ] + $this->counts($byTeacher->get($t->id, collect())))->values(),
            'students' => $students->map(function (LmsStudent $s) use ($byStudent, $midMonth) {
                $course = $s->course;
                $limit = OneOnOneBooking::limitFor($s, $course, $midMonth);

                return [
                    'id' => $s->id,
                    'name' => trim($s->first_name . ' ' . $s->last_name) ?: $s->email,
                    'teacher_name' => $s->oneOnOneTeacher?->name,
                    'course_title' => $course?->title,
                    'limit' => $limit,
                    'extra' => OneOnOneBooking::extraSessions($s, $course, $midMonth),
                    'used' => OneOnOneBooking::usedInPeriod($s, $course, $midMonth),
                    'period' => $course?->isMonthly() ? 'month' : 'course',
                ] + $this->counts($byStudent->get($s->id, collect()));
            })->values(),
            'sessions' => $sessions->take(300)->map(fn (LmsScheduledClass $c) => [
                'id' => $c->id,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'student_name' => $c->student ? (trim($c->student->first_name . ' ' . $c->student->last_name) ?: $c->student->email) : null,
                'teacher_name' => $c->teacher?->name,
                'module_title' => $c->module?->title ?? $c->title,
                'status' => $c->status,
                'booked_by' => $c->booked_by ?? 'teacher',
                'outcome' => $c->outcome,
                'outcome_by' => $c->outcome_marked_by,
                'reported' => OneOnOneOutcomes::reportPending($c),
                'started' => $c->starts_at !== null && ! $c->starts_at->isFuture(),
            ])->values(),
        ]);
    }

    /** The owner records (or overrides) what happened at a session. */
    public function outcome(Request $request, int $sessionId): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'outcome' => ['required', 'in:' . implode(',', LmsScheduledClass::OUTCOMES)],
        ]);

        // Tenant-scoped: another academy's session 404s.
        $session = LmsScheduledClass::query()->whereNotNull('student_id')->findOrFail($sessionId);

        OneOnOneOutcomes::mark($session, $validated['outcome'], OneOnOneOutcomes::BY_OWNER);

        return response()->json(['ok' => true, 'outcome' => $session->fresh()->outcome]);
    }

    /** @return array{sessions: int, upcoming: int, held: int, cancelled: int, student_no_shows: int, teacher_no_shows: int, unmarked: int} */
    private function counts(Collection $sessions): array
    {
        $now = now();
        $live = $sessions->where('status', '!=', 'cancelled');
        $started = $live->filter(fn ($s) => $s->starts_at && $s->starts_at->lte($now));

        return [
            'sessions' => $live->count(),
            'upcoming' => $live->count() - $started->count(),
            'held' => $started->filter(fn ($s) => $s->outcome === null || $s->outcome === LmsScheduledClass::OUTCOME_HELD)->count(),
            'cancelled' => $sessions->count() - $live->count(),
            'student_no_shows' => $started->where('outcome', LmsScheduledClass::OUTCOME_STUDENT_NO_SHOW)->count(),
            'teacher_no_shows' => $started->where('outcome', LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW)->count(),
            'unmarked' => $started->whereNull('outcome')->count(),
        ];
    }
}
