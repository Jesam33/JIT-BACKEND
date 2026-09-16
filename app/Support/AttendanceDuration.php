<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * How long a class was scheduled to run, in minutes — the denominator behind both
 * the recorded attendance status (present once a student stays >= 70% of it) and
 * the "stayed / lasted" figure the student and staff portals display.
 *
 * It lives here, rather than inline at each call site, so the WRITE path
 * (StudentClassroomController::attendanceLeave) and the READ paths (the student
 * and staff attendance endpoints) can never disagree. If they drifted, a student
 * could be stored as 'partial' while their page showed them at 100% of the class.
 *
 * Both delivery types carry their own window — legacy course classrooms and
 * module scheduled classes each have starts_at / ends_at — and both fall back to
 * the same 60-minute default when a class has no usable window.
 */
class AttendanceDuration
{
    /** Assumed class length when the class carries no (or an unusable) window. */
    public const DEFAULT_MINUTES = 60;

    /** Fraction of the class a student must attend to count as 'present'. */
    public const PRESENT_THRESHOLD = 0.70;

    /**
     * Minutes the class was scheduled to last. Takes a LmsClassroom or a
     * LmsScheduledClass; anything without a usable window yields the default.
     */
    public static function minutesFor($class): int
    {
        if ($class && $class->starts_at && $class->ends_at) {
            // earlier->diffInMinutes(later): Carbon 3 returns SIGNED diffs, so the
            // order matters — the reverse would be negative. Guard > 0 so a class
            // with a zero/negative window falls through to the default instead of
            // producing a nonsensical 0-minute class.
            $minutes = (int) round(
                Carbon::parse($class->starts_at)->diffInMinutes(Carbon::parse($class->ends_at))
            );

            if ($minutes > 0) {
                return $minutes;
            }
        }

        return self::DEFAULT_MINUTES;
    }

    /** Minutes a student must stay in a class of this length to count as present. */
    public static function presentThreshold(int $classMinutes): int
    {
        return max(1, (int) round($classMinutes * self::PRESENT_THRESHOLD));
    }

    /** present | partial | absent, from how long the student actually stayed. */
    public static function statusFor(int $attendedMinutes, int $classMinutes): string
    {
        if ($attendedMinutes >= self::presentThreshold($classMinutes)) {
            return 'present';
        }

        return $attendedMinutes > 0 ? 'partial' : 'absent';
    }
}
