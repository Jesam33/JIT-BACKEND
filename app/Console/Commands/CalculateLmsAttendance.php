<?php

namespace App\Console\Commands;

use App\Models\LmsAttendance;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
use App\Models\LmsScheduledClass;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Server-side attendance safety net, scheduled every 5 minutes.
 *
 * Live-class attendance is closed out by the STUDENT'S BROWSER when the
 * embedded room tears down (StudentClassroomController::attendanceLeave) —
 * tab close, refresh, navigation and phone app-switches all silently skip
 * that close-out, so a joined student would otherwise be left with no record
 * at all (or stuck on a bogus early-leave "absent"). This sweep is the
 * backstop: once a class is past its scheduled end, any join row that was
 * never closed out (calculated_at NULL) gets finalized from the class
 * schedule — the same status math as the client close-out.
 *
 * Replaces the original Zoom-era version, which computed durations from
 * LmsAttendanceEvent join/left webhook rows. Nothing has written those events
 * since the Jitsi cutover, so that logic was dead weight — and, worse, its
 * updateOrCreate keys were missing class_type, so it would have clobbered
 * client-written records under different thresholds had events existed.
 *
 * The leave time is approximated as the class's scheduled end: the server
 * has no signal for when an unclosed student actually dropped, and erring
 * generous (joined = stayed until the end) is the correct bias — the client
 * close-out handles real early leaves with exact times, and only rows the
 * client never managed to close fall through to here.
 */
class CalculateLmsAttendance extends Command
{
    protected $signature = 'lms:calculate-attendance {--classroom_id=}';

    protected $description = 'Close out attendance for ended LMS classes where the client leave close-out never fired.';

    public function handle(): int
    {
        $classroomId = $this->option('classroom_id');

        // Legacy course classrooms.
        $classrooms = LmsClassroom::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->when($classroomId, fn ($q) => $q->where('id', $classroomId))
            ->get();

        foreach ($classrooms as $classroom) {
            LmsAttendance::query()
                ->where('class_type', 'classroom')
                ->where('classroom_id', $classroom->id)
                ->whereNull('calculated_at')
                ->get()
                ->each(fn ($a) => $this->closeOut(
                    $a,
                    'classroom',
                    Carbon::parse($classroom->starts_at),
                    Carbon::parse($classroom->ends_at),
                ));
        }

        // Module-based scheduled classes.
        $classes = LmsScheduledClass::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($classes as $class) {
            LmsAttendance::query()
                ->where('class_type', 'scheduled')
                ->where('scheduled_class_id', $class->id)
                ->whereNull('calculated_at')
                ->get()
                ->each(fn ($a) => $this->closeOut(
                    $a,
                    'scheduled',
                    Carbon::parse($class->starts_at),
                    Carbon::parse($class->ends_at),
                ));
        }

        return 0;
    }

    /**
     * Finalize one unclosed join row. Mirrors the client close-out
     * (StudentClassroomController::attendanceLeave) exactly: same 70%
     * present threshold, same partial/absent split, same record-row shape —
     * so a swept row is indistinguishable from a properly closed one.
     */
    private function closeOut(LmsAttendance $attendance, string $classType, Carbon $startsAt, Carbon $endsAt): void
    {
        $firstJoined = $attendance->first_joined_at ?? $attendance->joined_at;

        if (! $firstJoined) {
            return; // nothing to compute a duration from
        }

        $leftAt = $endsAt->lt($firstJoined) ? $firstJoined : $endsAt;
        // earlier->diffInSeconds(later): Carbon 3 returns SIGNED diffs, so
        // the order matters — later->diffInSeconds(earlier) is negative and
        // the max(0, ...) clamp would silently zero out every stay.
        $totalSeconds = max(0, Carbon::parse($firstJoined)->diffInSeconds($leftAt));
        $durationMinutes = (int) round($totalSeconds / 60);

        $classDurationMinutes = (int) round($startsAt->diffInMinutes($endsAt));
        // Same 70% present rule as the client close-out (and the pre-join note
        // students see), so swept rows are indistinguishable from closed ones.
        $threshold = max(1, (int) round($classDurationMinutes * 0.70));

        $status = $durationMinutes >= $threshold
            ? 'present'
            : ($durationMinutes > 0 ? 'partial' : 'absent');

        $attendance->update([
            'first_joined_at' => $firstJoined,
            'last_left_at' => $leftAt,
            'total_seconds' => $totalSeconds,
            'status' => $status,
            'calculated_at' => now(),
        ]);

        LmsAttendanceRecord::query()->updateOrCreate(
            $classType === 'scheduled'
                ? [
                    'class_type' => 'scheduled',
                    'scheduled_class_id' => $attendance->scheduled_class_id,
                    'classroom_id' => null,
                    'student_id' => $attendance->student_id,
                ]
                : [
                    'class_type' => 'classroom',
                    'classroom_id' => $attendance->classroom_id,
                    'student_id' => $attendance->student_id,
                ],
            [
                'total_seconds' => $totalSeconds,
                'first_joined_at' => $firstJoined,
                'status' => $status,
                'calculated_at' => now(),
            ]
        );

        $this->info("Closed out {$classType} attendance for student {$attendance->student_id}: {$status} ({$durationMinutes} min).");
    }
}
