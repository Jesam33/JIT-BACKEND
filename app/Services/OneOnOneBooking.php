<?php

namespace App\Services;

use App\Models\LmsCourse;
use App\Models\LmsModule;
use App\Models\LmsOneOnOneCredit;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherAvailability;
use App\Models\LmsTeacherTimeOff;
use App\Scopes\TenantScope;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-on-one self-booking: a student picks one of the open slots in their
 * teacher's week and books it (optionally the same time every week).
 *
 *   Slots   = the teacher's weekly windows (lms_teacher_availability), cut into
 *             session-length pieces, minus days off, minus anything the teacher
 *             already has (group classes AND private sessions), minus slots
 *             inside the minimum-notice period. Times are entered in the
 *             academy's timezone (tz()).
 *   Booking = re-checked inside a transaction that locks the teacher's row, so
 *             two students clicking the same slot at once can't both get it.
 *   Limit   = lms_courses.one_on_one_session_limit sessions per calendar month
 *             (monthly courses) or in total (one-time courses), plus any extra
 *             sessions the student bought for that period (OneOnOneExtras).
 *             Every live private session counts, including ones the teacher
 *             scheduled, EXCEPT ones the teacher missed (teacher_no_show). The
 *             limit only stops the STUDENT booking more, never the teacher.
 *
 * Settings: config('saas.one_on_one_booking').
 */
class OneOnOneBooking
{
    /**
     * Whether this student can book for themselves right now, and why not.
     * Null = they can.
     */
    public static function blockedReason(LmsStudent $student): ?string
    {
        if (! $student->isOneOnOne()) {
            return 'Booking is only for one-on-one students.';
        }
        if (! $student->one_on_one_teacher_id) {
            return 'Your academy is still assigning your teacher. You can book once they have.';
        }
        if ($student->one_on_one_self_booking === false) {
            return 'Your teacher schedules your sessions for you.';
        }

        return null;
    }

    /**
     * Open slots for this student over the booking horizon, earliest first.
     *
     * @return array<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    public static function openSlots(LmsStudent $student, ?Carbon $now = null): array
    {
        if (static::blockedReason($student) !== null) {
            return [];
        }

        $now ??= now();
        $course = static::course($student);
        $slots = static::teacherSlots((int) $student->one_on_one_teacher_id, static::sessionMinutes($course), $now);

        return static::withinAllowance($student, $course, $slots);
    }

    /**
     * A teacher's free slots of $minutes, before the student-specific filters.
     * Defaults to today through the booking horizon; pass $fromDay/$days for
     * another range (a repeat booking checks weeks beyond the horizon).
     *
     * @return array<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    public static function teacherSlots(int $teacherId, int $minutes, ?Carbon $now = null, ?Carbon $fromDay = null, ?int $days = null): array
    {
        $now ??= now();
        $tz = static::tz();
        $cfg = static::settings();

        $windows = LmsTeacherAvailability::query()->withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $teacherId)
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');

        if ($windows->isEmpty()) {
            return [];
        }

        $firstDay = ($fromDay ?? $now)->copy()->setTimezone($tz)->startOfDay();
        $lastDay = $firstDay->copy()->addDays(max(1, $days ?? $cfg['horizon_days']) - 1);
        $earliest = $now->copy()->addHours($cfg['min_notice_hours']);

        $timeOff = LmsTeacherTimeOff::query()->withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $teacherId)
            ->whereDate('ends_on', '>=', $firstDay->toDateString())
            ->whereDate('starts_on', '<=', $lastDay->toDateString())
            ->get(['starts_on', 'ends_on']);

        $busy = static::busyIntervals($teacherId, $firstDay->copy()->max($now), $lastDay->copy()->endOfDay());

        $slots = [];
        for ($day = $firstDay->copy(); $day->lte($lastDay); $day->addDay()) {
            $date = $day->toDateString();
            $off = $timeOff->contains(fn ($t) => $t->starts_on->toDateString() <= $date && $t->ends_on->toDateString() >= $date);
            if ($off) {
                continue;
            }

            foreach ($windows->get($day->dayOfWeek, collect()) as $window) {
                $cursor = Carbon::parse($date . ' ' . $window->start_time, $tz);
                $windowEnd = Carbon::parse($date . ' ' . $window->end_time, $tz);

                while ($cursor->copy()->addMinutes($minutes)->lte($windowEnd)) {
                    $start = $cursor->copy();
                    $end = $cursor->copy()->addMinutes($minutes);
                    $cursor->addMinutes($minutes);

                    if ($start->lt($earliest) || static::overlapsAny($busy, $start, $end)) {
                        continue;
                    }

                    $slots[] = [
                        'starts_at' => $start->copy()->setTimezone(config('app.timezone')),
                        'ends_at' => $end->copy()->setTimezone(config('app.timezone')),
                    ];
                }
            }
        }

        usort($slots, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /**
     * Book one open slot. Throws ValidationException (422) with a plain reason
     * when it isn't bookable (taken, too soon, over the limit, ...).
     */
    public static function book(LmsStudent $student, Carbon $startsAt, int $moduleId): LmsScheduledClass
    {
        return static::bookSeries($student, $startsAt, $moduleId, 1)['booked'][0];
    }

    /**
     * Book the same time every week for $weeks weeks (1 = just this one). The
     * first must be bookable or the whole request fails (422); later weeks
     * that aren't (taken, a day off, over the limit) are skipped and reported.
     *
     * @return array{booked: array<int, LmsScheduledClass>, skipped: array<int, array{starts_at: Carbon, reason: string}>}
     */
    public static function bookSeries(LmsStudent $student, Carbon $startsAt, int $moduleId, int $weeks = 1): array
    {
        if ($reason = static::blockedReason($student)) {
            throw ValidationException::withMessages(['starts_at' => $reason]);
        }

        $weeks = max(1, min($weeks, static::settings()['repeat_max_weeks']));
        $course = static::course($student);
        $module = LmsModule::query()->withoutGlobalScope(TenantScope::class)
            ->where('course_id', $student->selected_course_id)
            ->find($moduleId);
        if (! $module) {
            throw ValidationException::withMessages(['module_id' => 'Choose a module from your course.']);
        }

        $teacherId = (int) $student->one_on_one_teacher_id;
        $seriesId = $weeks > 1 ? (string) Str::uuid() : null;

        [$booked, $skipped, $teacher] = DB::transaction(function () use ($student, $course, $module, $startsAt, $teacherId, $weeks, $seriesId) {
            // Serialise bookings per teacher: the second of two simultaneous
            // requests waits here, then sees the first one's session as busy.
            $teacher = LmsTeacher::query()->withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->find($teacherId);
            if (! $teacher) {
                throw ValidationException::withMessages(['starts_at' => 'Your teacher is no longer available.']);
            }

            $booked = [];
            $skipped = [];
            $tz = static::tz();

            for ($week = 0; $week < $weeks; $week++) {
                // Same local wall-clock time each week.
                $at = $startsAt->copy()->setTimezone($tz)->addWeeks($week)->setTimezone(config('app.timezone'));
                $slot = static::slotAt($student, $course, $at, $week > 0);

                if (is_string($slot)) {
                    if ($week === 0) {
                        throw ValidationException::withMessages(['starts_at' => $slot]);
                    }
                    $skipped[] = ['starts_at' => $at, 'reason' => $slot];

                    continue;
                }

                $session = new LmsScheduledClass([
                    'module_id' => $module->id,
                    'teacher_id' => $teacher->id,
                    'student_id' => $student->id,
                    'title' => 'One-on-one: ' . $module->title,
                    'starts_at' => $slot['starts_at'],
                    'ends_at' => $slot['ends_at'],
                    'status' => 'scheduled',
                    'booked_by' => 'student',
                    'series_id' => $seriesId,
                ]);
                $session->forceFill(['tenant_id' => $student->tenant_id])->save();
                $booked[] = $session->setRelation('teacher', $teacher);
            }

            return [$booked, $skipped, $teacher];
        });

        static::notifyBooked($student, $teacher, $module, $booked);

        return ['booked' => $booked, 'skipped' => $skipped];
    }

    /**
     * The open slot starting at $at for this student, or the reason it isn't
     * one. $beyondHorizon lets a repeat week look past the normal horizon.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon}|string
     */
    private static function slotAt(LmsStudent $student, ?LmsCourse $course, Carbon $at, bool $beyondHorizon): array|string
    {
        $cfg = static::settings();
        $localDay = $at->copy()->setTimezone(static::tz())->startOfDay();

        if (! $beyondHorizon && $localDay->gt(now()->setTimezone(static::tz())->startOfDay()->addDays($cfg['horizon_days'] - 1))) {
            return 'That time is no longer available. Please pick another.';
        }

        $slot = collect(static::teacherSlots((int) $student->one_on_one_teacher_id, static::sessionMinutes($course), now(), $localDay, 1))
            ->first(fn (array $s) => $s['starts_at']->equalTo($at));

        if (! $slot) {
            return 'That time is no longer available. Please pick another.';
        }

        $limit = static::limitFor($student, $course, $at);
        if ($limit !== null && static::usedInPeriod($student, $course, $at) >= $limit) {
            return $course?->isMonthly()
                ? "You've used all {$limit} sessions for that month."
                : "You've used all {$limit} sessions included in your course.";
        }

        return $slot;
    }

    /** @param array<int, LmsScheduledClass> $booked */
    private static function notifyBooked(LmsStudent $student, LmsTeacher $teacher, LmsModule $module, array $booked): void
    {
        if (! $booked) {
            return;
        }

        $first = $booked[0];
        $name = static::name($student);
        $count = count($booked);
        $when = $count === 1
            ? static::local($first->starts_at)
            : 'every ' . $first->starts_at->copy()->setTimezone(static::tz())->format('l \a\t g:ia')
                . ' (' . $count . ' sessions, from ' . static::local($first->starts_at, 'j M') . ')';

        Notify::staff(
            (int) $teacher->id,
            $count === 1 ? 'one_on_one_booked' : 'one_on_one_series',
            'New booking: ' . $name,
            $name . ' booked one-on-one (' . $module->title . ') for ' . $when . '.',
            'scheduled_class',
            $first->id,
            true
        );

        Notify::student(
            $student->id,
            $count === 1 ? 'class_scheduled' : 'one_on_one_series',
            $count === 1 ? 'Session booked' : 'Weekly sessions booked',
            'Your one-on-one session' . ($count === 1 ? '' : 's') . ' with ' . $teacher->name . ' '
                . ($count === 1 ? 'is' : 'are') . ' booked for ' . $when . '.',
            'scheduled_class',
            $first->id
        );
    }

    /**
     * Whether the student may still cancel this session themselves. Null =
     * yes; otherwise the reason they can't.
     */
    public static function studentCancelBlockedReason(LmsScheduledClass $session): ?string
    {
        if ($session->status === 'cancelled') {
            return 'This session is already cancelled.';
        }
        if (! $session->starts_at || $session->starts_at->lte(now())) {
            return 'This session has already started.';
        }

        $hours = static::settings()['cancel_window_hours'];
        if ($session->starts_at->lt(now()->addHours($hours))) {
            return "Sessions can only be cancelled up to {$hours} hours before. Message your teacher instead.";
        }

        return null;
    }

    /** The student cancels their own session (inside the window); teacher told. */
    public static function cancelByStudent(LmsStudent $student, LmsScheduledClass $session): void
    {
        if ((int) $session->student_id !== (int) $student->id) {
            abort(404);
        }
        if ($reason = static::studentCancelBlockedReason($session)) {
            throw ValidationException::withMessages(['session' => $reason]);
        }

        $session->update(['status' => 'cancelled']);

        $name = static::name($student);
        Notify::staff(
            (int) $session->teacher_id,
            'one_on_one_cancelled',
            'Session cancelled by ' . $name,
            $name . ' cancelled their one-on-one session on ' . static::local($session->starts_at) . '. The time is open again.',
            'scheduled_class',
            $session->id,
            true
        );
    }

    /**
     * Does the teacher already have something between $start and $end?
     * Used for teacher-side scheduling too, so a teacher can't double-book
     * themselves by accident.
     */
    public static function teacherBusy(int $teacherId, Carbon $start, Carbon $end, ?int $exceptSessionId = null): bool
    {
        return static::overlapsAny(static::busyIntervals($teacherId, $start->copy()->subDay(), $end->copy()->addDay(), $exceptSessionId), $start, $end);
    }

    /**
     * Allowance summary for the student's page: limit (course + extras bought),
     * used and remaining in the current period (calendar month for monthly
     * courses; the whole course otherwise). limit null = unlimited.
     *
     * @return array{limit: ?int, included: ?int, extra: int, used: int, remaining: ?int, period: string, period_key: ?string}
     */
    public static function allowance(LmsStudent $student): array
    {
        $course = static::course($student);
        $limit = static::limitFor($student, $course, now());
        $used = static::usedInPeriod($student, $course, now());

        return [
            'limit' => $limit,
            'included' => $course?->one_on_one_session_limit ?: null,
            'extra' => static::extraSessions($student, $course, now()),
            'used' => $used,
            'remaining' => $limit !== null ? max(0, $limit - $used) : null,
            'period' => $course?->isMonthly() ? 'month' : 'course',
            'period_key' => static::periodKey($course, now()),
        ];
    }

    /**
     * The student's session limit for the period containing $at: the course's
     * allowance plus paid extras for that period. Null = unlimited.
     */
    public static function limitFor(LmsStudent $student, ?LmsCourse $course, Carbon $at): ?int
    {
        $included = $course?->one_on_one_session_limit;
        if (! $included) {
            return null;
        }

        return $included + static::extraSessions($student, $course, $at);
    }

    /** Paid extra sessions for the period containing $at. */
    public static function extraSessions(LmsStudent $student, ?LmsCourse $course, Carbon $at): int
    {
        return (int) LmsOneOnOneCredit::query()->withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->where('status', 'paid')
            ->where(fn ($q) => ($key = static::periodKey($course, $at)) === null
                ? $q->whereNull('period')
                : $q->where('period', $key))
            ->sum('sessions');
    }

    /** Send "starting soon" reminders to both sides, once per session. */
    public static function sendReminders(): int
    {
        $minutes = static::settings()['reminder_minutes'];
        $sent = 0;

        LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
            ->with(['teacher' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'student' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->whereNotNull('student_id')
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes($minutes))
            ->orderBy('id')
            ->each(function (LmsScheduledClass $session) use (&$sent) {
                // Stamp first: a mail failure must never turn into a reminder
                // sent every minute.
                $session->forceFill(['reminder_sent_at' => now()])->saveQuietly();

                $when = static::local($session->starts_at, 'g:ia');
                $studentName = $session->student ? static::name($session->student) : 'your student';

                Notify::student(
                    (int) $session->student_id,
                    'class_reminder',
                    'Your one-on-one session starts soon',
                    'Your session with ' . ($session->teacher?->name ?? 'your teacher') . ' starts at ' . $when . '. Join it from your Classroom.',
                    'scheduled_class',
                    $session->id
                );

                Notify::staff(
                    (int) $session->teacher_id,
                    'class_reminder',
                    'One-on-one with ' . $studentName . ' starts soon',
                    'Your session with ' . $studentName . ' starts at ' . $when . '. Start it from your Timetable.',
                    'scheduled_class',
                    $session->id,
                    true
                );

                $sent++;
            });

        return $sent;
    }

    /** @return array{min_notice_hours: int, cancel_window_hours: int, horizon_days: int, reminder_minutes: int, repeat_max_weeks: int, no_show_report_days: int} */
    public static function settings(): array
    {
        $cfg = (array) config('saas.one_on_one_booking', []);

        return [
            'min_notice_hours' => max(0, (int) ($cfg['min_notice_hours'] ?? 12)),
            'cancel_window_hours' => max(0, (int) ($cfg['cancel_window_hours'] ?? 24)),
            'horizon_days' => max(1, (int) ($cfg['horizon_days'] ?? 14)),
            'reminder_minutes' => max(1, (int) ($cfg['reminder_minutes'] ?? 60)),
            'repeat_max_weeks' => max(1, (int) ($cfg['repeat_max_weeks'] ?? 8)),
            'no_show_report_days' => max(1, (int) ($cfg['no_show_report_days'] ?? 7)),
        ];
    }

    public static function sessionMinutes(?LmsCourse $course): int
    {
        $minutes = (int) ($course?->one_on_one_session_minutes ?: 60);

        return max(15, min(240, $minutes));
    }

    public static function tz(): string
    {
        return (string) config('saas.monthly_reminder_timezone', 'Africa/Lagos');
    }

    /**
     * Private sessions that use up the allowance in $at's period: not
     * cancelled, and not ones the teacher missed.
     */
    public static function usedInPeriod(LmsStudent $student, ?LmsCourse $course, Carbon $at): int
    {
        $query = LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW));

        if ($course?->isMonthly()) {
            $local = $at->copy()->setTimezone(static::tz());
            $query->where('starts_at', '>=', $local->copy()->startOfMonth()->setTimezone(config('app.timezone')))
                ->where('starts_at', '<', $local->copy()->startOfMonth()->addMonth()->setTimezone(config('app.timezone')));
        }

        return $query->count();
    }

    /** 'Y-m' of $at for a monthly course; null (the whole course) otherwise. */
    public static function periodKey(?LmsCourse $course, Carbon $at): ?string
    {
        return $course?->isMonthly() ? $at->copy()->setTimezone(static::tz())->format('Y-m') : null;
    }

    public static function course(LmsStudent $student): ?LmsCourse
    {
        return $student->selected_course_id
            ? LmsCourse::query()->withoutGlobalScope(TenantScope::class)->find($student->selected_course_id)
            : null;
    }

    public static function local(?Carbon $at, string $format = 'l j M, g:ia'): string
    {
        return $at ? $at->copy()->setTimezone(static::tz())->format($format) : '';
    }

    /**
     * Drop slots in periods where the student has used their allowance.
     *
     * @param  array<int, array{starts_at: Carbon, ends_at: Carbon}>  $slots
     * @return array<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    private static function withinAllowance(LmsStudent $student, ?LmsCourse $course, array $slots): array
    {
        if (! $course?->one_on_one_session_limit) {
            return $slots;
        }

        $cache = [];

        return array_values(array_filter($slots, function (array $slot) use ($student, $course, &$cache) {
            $key = static::periodKey($course, $slot['starts_at']) ?? 'course';
            $cache[$key] ??= static::limitFor($student, $course, $slot['starts_at']) - static::usedInPeriod($student, $course, $slot['starts_at']);

            return $cache[$key] > 0;
        }));
    }

    /**
     * Everything already on the teacher's calendar in a range: their group
     * classes and private sessions (not cancelled).
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private static function busyIntervals(int $teacherId, Carbon $from, Carbon $to, ?int $exceptSessionId = null): array
    {
        return LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $teacherId)
            ->where('status', '!=', 'cancelled')
            ->when($exceptSessionId, fn ($q) => $q->whereKeyNot($exceptSessionId))
            ->where('starts_at', '<', $to)
            ->where(fn ($q) => $q->where('ends_at', '>', $from)->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>', $from->copy()->subHour())))
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($c) => [$c->starts_at, $c->ends_at ?? $c->starts_at->copy()->addHour()])
            ->all();
    }

    /** @param array<int, array{0: Carbon, 1: Carbon}> $intervals */
    private static function overlapsAny(array $intervals, Carbon $start, Carbon $end): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($s->lt($end) && $e->gt($start)) {
                return true;
            }
        }

        return false;
    }

    private static function name(LmsStudent $student): string
    {
        return trim($student->first_name . ' ' . $student->last_name) ?: (string) $student->email;
    }
}
