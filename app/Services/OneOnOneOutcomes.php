<?php

namespace App\Services;

use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Scopes\TenantScope;
use App\Support\Notify;
use Illuminate\Validation\ValidationException;

/**
 * What happened at a one-on-one session, once it has started:
 *
 *   held             it went ahead (also assumed when nobody marks it)
 *   student_no_show  the student missed it: it still uses up a session
 *   teacher_no_show  the teacher missed it: it does NOT use up a session
 *                    (OneOnOneBooking::usedInPeriod leaves it out)
 *
 * The teacher marks it. The student can report "my teacher didn't come"; the
 * owner sees the report on the One-on-one overview and has the final say
 * (an owner decision can't be changed by the teacher).
 */
class OneOnOneOutcomes
{
    public const BY_TEACHER = 'teacher';

    public const BY_OWNER = 'owner';

    /** Mark a session's outcome. $by = teacher | owner. */
    public static function mark(LmsScheduledClass $session, string $outcome, string $by): LmsScheduledClass
    {
        if (! in_array($outcome, LmsScheduledClass::OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose what happened.']);
        }
        if (! $session->isPrivate()) {
            throw ValidationException::withMessages(['outcome' => 'Only one-on-one sessions are marked this way.']);
        }
        if ($session->status === 'cancelled') {
            throw ValidationException::withMessages(['outcome' => 'This session was cancelled.']);
        }
        if (! $session->starts_at || $session->starts_at->isFuture()) {
            throw ValidationException::withMessages(['outcome' => "You can mark a session once it has started."]);
        }
        if ($by === self::BY_TEACHER && $session->outcome_marked_by === self::BY_OWNER) {
            throw ValidationException::withMessages(['outcome' => 'The academy has already decided this one.']);
        }

        $previous = $session->outcome;
        $resolvesReport = $session->no_show_reported_at !== null && $by === self::BY_OWNER;

        $session->forceFill([
            'outcome' => $outcome,
            'outcome_marked_by' => $by,
            'outcome_marked_at' => now(),
        ])->save();

        if ($previous === $outcome && ! $resolvesReport) {
            return $session;
        }

        $when = OneOnOneBooking::local($session->starts_at);
        $studentName = static::studentName($session);
        $teacherName = LmsTeacher::query()->withoutGlobalScope(TenantScope::class)->whereKey($session->teacher_id)->value('name') ?? 'Your teacher';

        $studentMessage = match ($outcome) {
            LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW => [
                'Session not counted',
                'Your session on ' . $when . ' did not go ahead because your teacher could not make it. It does not count toward your sessions, so you can book another.',
            ],
            LmsScheduledClass::OUTCOME_STUDENT_NO_SHOW => [
                'Missed session',
                'Your session on ' . $when . ' was marked as missed, so it counts as one of your sessions.',
            ],
            default => $resolvesReport ? [
                'About your report',
                'Your academy checked your session on ' . $when . ' and recorded it as held.',
            ] : null,
        };

        if ($studentMessage) {
            Notify::student((int) $session->student_id, 'one_on_one_outcome', $studentMessage[0], $studentMessage[1], 'scheduled_class', $session->id);
        }

        if ($by === self::BY_OWNER && $outcome !== $previous) {
            Notify::staff(
                (int) $session->teacher_id,
                'one_on_one_outcome',
                'Session with ' . $studentName . ' updated',
                'The academy recorded your session with ' . $studentName . ' on ' . $when . ' as: ' . static::label($outcome) . '.',
                'scheduled_class',
                $session->id
            );
        }

        if ($by === self::BY_TEACHER && $outcome === LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW) {
            Notify::owner(
                'one_on_one_teacher_no_show',
                $teacherName . ' missed a session',
                $teacherName . ' marked their one-on-one session with ' . $studentName . ' on ' . $when
                    . ' as missed by the teacher. It does not count toward the student\'s sessions.',
                'student',
                (int) $session->student_id,
                $session->tenant_id ? (int) $session->tenant_id : null
            );
        }

        return $session;
    }

    /** Why this student can't report a teacher no-show here, or null. */
    public static function reportBlockedReason(LmsScheduledClass $session): ?string
    {
        if ($session->status === 'cancelled') {
            return 'This session was cancelled.';
        }
        if (! $session->starts_at || $session->starts_at->isFuture()) {
            return "This session hasn't started yet.";
        }
        $days = OneOnOneBooking::settings()['no_show_report_days'];
        if ($session->starts_at->lt(now()->subDays($days))) {
            return "Reports can be made up to {$days} days after a session.";
        }
        if ($session->no_show_reported_at) {
            return 'You already reported this session.';
        }
        if ($session->outcome === LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW) {
            return 'This session is already recorded as missed by your teacher.';
        }

        return null;
    }

    /** The student reports their teacher didn't come. Owner and teacher told. */
    public static function report(LmsStudent $student, LmsScheduledClass $session): void
    {
        if ((int) $session->student_id !== (int) $student->id) {
            abort(404);
        }
        if ($reason = static::reportBlockedReason($session)) {
            throw ValidationException::withMessages(['session' => $reason]);
        }

        $session->forceFill(['no_show_reported_at' => now()])->save();

        $when = OneOnOneBooking::local($session->starts_at);
        $name = static::studentName($session);

        Notify::owner(
            'one_on_one_no_show_report',
            $name . ' reported a missed session',
            $name . ' says their teacher did not come to their one-on-one session on ' . $when
                . '. Check it on the One-on-one page and record what happened.',
            'student',
            $student->id,
            $student->tenant_id ? (int) $student->tenant_id : null
        );

        Notify::staff(
            (int) $session->teacher_id,
            'one_on_one_no_show_report',
            $name . ' reported a missed session',
            $name . ' reported that the session on ' . $when . ' did not happen. The academy will review it.',
            'scheduled_class',
            $session->id,
            true
        );
    }

    /** Whether a student's report still waits for the owner's decision. */
    public static function reportPending(LmsScheduledClass $session): bool
    {
        return $session->no_show_reported_at !== null && $session->outcome_marked_by !== self::BY_OWNER;
    }

    public static function label(?string $outcome): string
    {
        return match ($outcome) {
            LmsScheduledClass::OUTCOME_HELD => 'held',
            LmsScheduledClass::OUTCOME_STUDENT_NO_SHOW => 'student did not show',
            LmsScheduledClass::OUTCOME_TEACHER_NO_SHOW => 'teacher did not show',
            default => 'not marked',
        };
    }

    private static function studentName(LmsScheduledClass $session): string
    {
        $s = LmsStudent::query()->withoutGlobalScope(TenantScope::class)->find($session->student_id);

        return $s ? (trim($s->first_name . ' ' . $s->last_name) ?: (string) $s->email) : 'Your student';
    }
}
