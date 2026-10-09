<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsModule;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherAvailability;
use App\Models\LmsTeacherTimeOff;
use App\Services\CourseBilling;
use App\Services\OneOnOne;
use App\Services\OneOnOneBooking;
use App\Services\OneOnOneOutcomes;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A teacher's one-on-one students and their private sessions: list them,
 * schedule a session, move it, cancel it. A teacher works only with the
 * students assigned to them; the academy owner (staff mirror) with all of the
 * academy's one-on-one students. Students are told about every change.
 */
class StaffOneOnOneController extends BaseLmsController
{
    /** My one-on-one students, with their next session, progress and billing. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        $students = $this->myStudentsQuery($actor)
            ->with(['course:id,title', 'oneOnOneTeacher:id,name'])
            ->orderBy('first_name')
            ->get();

        $ids = $students->pluck('id')->all();

        $nextSessions = LmsScheduledClass::query()
            ->whereIn('student_id', $ids)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->first());

        $held = LmsScheduledClass::query()
            ->whereIn('student_id', $ids)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', now())
            // Unmarked counts as held; either kind of no-show doesn't.
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', LmsScheduledClass::OUTCOME_HELD))
            ->selectRaw('student_id, COUNT(*) as n')
            ->groupBy('student_id')
            ->pluck('n', 'student_id');

        // Modules of each course, for the "schedule a session" picker.
        $modules = LmsModule::query()
            ->whereIn('course_id', $students->pluck('selected_course_id')->filter()->unique()->all())
            ->orderBy('sort_order')
            ->get(['id', 'course_id', 'title'])
            ->groupBy('course_id')
            ->map(fn ($rows) => $rows->map(fn ($m) => ['id' => $m->id, 'title' => $m->title])->values());

        return response()->json([
            'students' => $students->map(function (LmsStudent $s) use ($nextSessions, $held) {
                $next = $nextSessions->get($s->id);
                $billing = CourseBilling::summary(CourseBilling::currentRegistration($s));

                return [
                    'id' => $s->id,
                    'name' => trim($s->first_name . ' ' . $s->last_name) ?: $s->email,
                    'email' => $s->email,
                    'course_id' => $s->selected_course_id,
                    'course_title' => $s->course?->title,
                    'teacher_name' => $s->oneOnOneTeacher?->name,
                    'next_session' => $next ? [
                        'id' => $next->id,
                        'title' => $next->title,
                        'starts_at' => $next->starts_at?->toIso8601String(),
                    ] : null,
                    'sessions_held' => (int) ($held[$s->id] ?? 0),
                    'self_booking' => (bool) ($s->one_on_one_self_booking ?? true),
                    // Monthly students: paid / overdue / paused (null on one-time).
                    'billing' => $billing['monthly'] ? [
                        'status' => $billing['status'],
                        'locked' => $billing['locked'],
                        'paid_until' => $billing['paid_until'],
                    ] : null,
                ];
            })->values(),
            'modules' => $modules,
        ]);
    }

    /** One student's sessions, upcoming first then past. */
    public function sessions(Request $request, int $studentId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $student = $this->myStudentsQuery($actor)->findOrFail($studentId);

        $sessions = LmsScheduledClass::query()
            ->with('module:id,title')
            ->where('student_id', $student->id)
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (LmsScheduledClass $c) => $this->sessionPayload($c));

        return response()->json(['sessions' => $sessions]);
    }

    /** Schedule a private session with one of my students. */
    public function store(Request $request, int $studentId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $student = $this->myStudentsQuery($actor)->findOrFail($studentId);

        $validated = $request->validate([
            'module_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'starts_at.after' => 'Choose a time in the future.',
            'ends_at.after' => 'The session has to end after it starts.',
        ]);

        // The module must be part of the student's own course.
        $module = LmsModule::query()
            ->where('course_id', $student->selected_course_id)
            ->find($validated['module_id']);
        if (! $module) {
            return response()->json(['message' => "That module isn't part of this student's course."], 422);
        }

        // The session is taught by the student's own teacher; the owner can
        // schedule on their behalf.
        $teacher = $student->one_on_one_teacher_id
            ? (LmsTeacher::query()->find($student->one_on_one_teacher_id) ?? $actor)
            : $actor;

        $startsAt = $this->appTime($validated['starts_at']);
        $endsAt = $this->appTime($validated['ends_at']);

        if (OneOnOneBooking::teacherBusy((int) $teacher->id, $startsAt, $endsAt)) {
            return response()->json(['message' => $this->busyMessage($actor, $teacher)], 422);
        }

        $session = OneOnOne::scheduleSession(
            $teacher,
            $student,
            $module,
            $startsAt,
            $endsAt,
            $validated['title'] ?? null,
            $validated['description'] ?? null,
        );

        return response()->json(['session' => $this->sessionPayload($session->load('module:id,title'))], 201);
    }

    /** Move a session to a new time. */
    public function reschedule(Request $request, int $sessionId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $session = $this->mySession($actor, $sessionId);

        $validated = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ], [
            'starts_at.after' => 'Choose a time in the future.',
            'ends_at.after' => 'The session has to end after it starts.',
        ]);

        $startsAt = $this->appTime($validated['starts_at']);
        $endsAt = $this->appTime($validated['ends_at']);

        if (OneOnOneBooking::teacherBusy((int) $session->teacher_id, $startsAt, $endsAt, $session->id)) {
            $teacher = LmsTeacher::query()->find($session->teacher_id) ?? $actor;

            return response()->json(['message' => $this->busyMessage($actor, $teacher)], 422);
        }

        OneOnOne::rescheduleSession($session, $startsAt, $endsAt);

        return response()->json(['session' => $this->sessionPayload($session->fresh()->load('module:id,title'))]);
    }

    /** Cancel a session. */
    public function cancel(Request $request, int $sessionId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $session = $this->mySession($actor, $sessionId);

        if ($session->status === 'cancelled') {
            return response()->json(['message' => 'This session is already cancelled.'], 422);
        }

        OneOnOne::cancelSession($session);

        return response()->json(['session' => $this->sessionPayload($session->fresh()->load('module:id,title'))]);
    }

    /** Record what happened at a session: held / student no-show / teacher no-show. */
    public function outcome(Request $request, int $sessionId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $session = $this->mySession($actor, $sessionId);

        $validated = $request->validate([
            'outcome' => ['required', 'in:' . implode(',', LmsScheduledClass::OUTCOMES)],
        ]);

        // The academy owner teaching through the staff portal decides as the owner.
        OneOnOneOutcomes::mark(
            $session,
            $validated['outcome'],
            $this->actorIsOwner($actor) ? OneOnOneOutcomes::BY_OWNER : OneOnOneOutcomes::BY_TEACHER
        );

        return response()->json(['session' => $this->sessionPayload($session->fresh()->load('module:id,title'))]);
    }

    /**
     * Turn self-booking on or off for one student. Off = the teacher schedules
     * all their sessions; sessions already booked stay.
     */
    public function setSelfBooking(Request $request, int $studentId): JsonResponse
    {
        $actor = $this->actorOrFail($request);
        $student = $this->myStudentsQuery($actor)->findOrFail($studentId);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        if ((bool) ($student->one_on_one_self_booking ?? true) !== $enabled) {
            $student->forceFill(['one_on_one_self_booking' => $enabled])->saveQuietly();

            Notify::student(
                $student->id,
                'one_on_one_booking',
                $enabled ? 'You can now book your own sessions' : 'Your teacher will schedule your sessions',
                $enabled
                    ? 'Pick a time that suits you from Book a session.'
                    : 'Your teacher will schedule your one-on-one sessions with you from now on. Sessions you already booked are unchanged.',
                'course',
                $student->selected_course_id
            );
        }

        return response()->json(['self_booking' => $enabled]);
    }

    /** My weekly availability, days off, and the academy's booking rules. */
    public function availability(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        return response()->json($this->availabilityPayload($actor));
    }

    /**
     * Replace my weekly availability. Each window is a weekday (0 = Sunday)
     * with a start and end time in the academy's timezone; windows on the
     * same day may not overlap.
     */
    public function saveAvailability(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        $validated = $request->validate([
            'windows' => ['present', 'array', 'max:50'],
            'windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $windows = collect($validated['windows'])->map(fn ($w) => [
            'weekday' => (int) $w['weekday'],
            'start_time' => $w['start_time'],
            'end_time' => $w['end_time'],
        ]);

        foreach ($windows as $w) {
            if ($w['end_time'] <= $w['start_time']) {
                return response()->json(['message' => 'Each time window has to end after it starts.'], 422);
            }
        }

        foreach ($windows->groupBy('weekday') as $day) {
            $sorted = $day->sortBy('start_time')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['start_time'] < $sorted[$i - 1]['end_time']) {
                    return response()->json(['message' => 'Two of your time windows on the same day overlap.'], 422);
                }
            }
        }

        DB::transaction(function () use ($actor, $windows) {
            LmsTeacherAvailability::query()->where('teacher_id', $actor->id)->delete();
            foreach ($windows as $w) {
                LmsTeacherAvailability::createForTenant((int) $actor->tenant_id, $w + ['teacher_id' => $actor->id]);
            }
        });

        return response()->json($this->availabilityPayload($actor));
    }

    /** Block out days I'm away (no slots are offered on them). */
    public function addTimeOff(Request $request): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        $validated = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:120'],
        ], [
            'ends_on.after_or_equal' => 'The last day off has to be on or after the first.',
        ]);

        LmsTeacherTimeOff::createForTenant((int) $actor->tenant_id, $validated + ['teacher_id' => $actor->id]);

        return response()->json($this->availabilityPayload($actor), 201);
    }

    public function deleteTimeOff(Request $request, int $id): JsonResponse
    {
        $actor = $this->actorOrFail($request);

        LmsTeacherTimeOff::query()->where('teacher_id', $actor->id)->findOrFail($id)->delete();

        return response()->json($this->availabilityPayload($actor));
    }

    private function availabilityPayload(LmsTeacher $actor): array
    {
        $settings = OneOnOneBooking::settings();

        return [
            'windows' => LmsTeacherAvailability::query()
                ->where('teacher_id', $actor->id)
                ->orderBy('weekday')->orderBy('start_time')
                ->get()
                ->map(fn ($w) => [
                    'weekday' => $w->weekday,
                    'start_time' => substr((string) $w->start_time, 0, 5),
                    'end_time' => substr((string) $w->end_time, 0, 5),
                ])->values(),
            'time_off' => LmsTeacherTimeOff::query()
                ->where('teacher_id', $actor->id)
                ->whereDate('ends_on', '>=', now()->setTimezone(OneOnOneBooking::tz())->toDateString())
                ->orderBy('starts_on')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'starts_on' => $t->starts_on->toDateString(),
                    'ends_on' => $t->ends_on->toDateString(),
                    'note' => $t->note,
                ])->values(),
            'timezone' => OneOnOneBooking::tz(),
            'rules' => [
                'min_notice_hours' => $settings['min_notice_hours'],
                'cancel_window_hours' => $settings['cancel_window_hours'],
                'horizon_days' => $settings['horizon_days'],
            ],
        ];
    }

    /**
     * Parse a client time into the app timezone. Eloquent stores a Carbon's
     * wall-clock time without converting, so "10:00+01:00" would otherwise be
     * saved as 10:00 UTC (an hour late).
     */
    private function appTime(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    private function busyMessage(LmsTeacher $actor, LmsTeacher $teacher): string
    {
        return (int) $actor->id === (int) $teacher->id
            ? 'You already have a class or session at that time.'
            : $teacher->name . ' already has a class or session at that time.';
    }

    private function actorOrFail(Request $request): LmsTeacher
    {
        $this->ensureLmsEnabled();
        $actor = $this->staffActor($request);
        if (! $actor) {
            abort(401, 'Unauthorized');
        }

        return $actor;
    }

    /** One-on-one students this actor works with (owner: the whole academy's). */
    private function myStudentsQuery(LmsTeacher $actor)
    {
        $query = LmsStudent::query()->where('learning_mode', LmsStudent::MODE_ONE_ON_ONE);

        return $this->actorIsOwner($actor) ? $query : $query->where('one_on_one_teacher_id', $actor->id);
    }

    /** A private session of one of this actor's students, or 404. */
    private function mySession(LmsTeacher $actor, int $sessionId): LmsScheduledClass
    {
        return LmsScheduledClass::query()
            ->whereNotNull('student_id')
            ->whereIn('student_id', $this->myStudentsQuery($actor)->select('id'))
            ->findOrFail($sessionId);
    }

    private function sessionPayload(LmsScheduledClass $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'module_id' => $c->module_id,
            'module_title' => $c->module?->title,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'ends_at' => $c->ends_at?->toIso8601String(),
            'status' => $c->status,
            'booked_by' => $c->booked_by ?? 'teacher',
            'weekly' => $c->series_id !== null,
            'outcome' => $c->outcome,
            'outcome_by_owner' => $c->outcome_marked_by === OneOnOneOutcomes::BY_OWNER,
            'reported' => OneOnOneOutcomes::reportPending($c),
        ];
    }
}
