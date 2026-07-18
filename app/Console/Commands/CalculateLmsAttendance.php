<?php

namespace App\Console\Commands;

use App\Models\LmsAttendanceEvent;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
use App\Models\LmsStudent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateLmsAttendance extends Command
{
    protected $signature = 'lms:calculate-attendance {--classroom_id=}';
    protected $description = 'Calculate attendance for ended LMS classrooms based on join/leave events.';

    public function handle(): int
    {
        $classroomId = $this->option('classroom_id');

        $query = LmsClassroom::query()->whereNotNull('ends_at')->where('ends_at', '<=', now());

        if ($classroomId) {
            $query->where('id', $classroomId);
        }

        $classrooms = $query->get();

        foreach ($classrooms as $classroom) {
            $this->info('Processing classroom ' . $classroom->id . ' - ' . $classroom->title);

            $events = LmsAttendanceEvent::query()
                ->where('classroom_id', $classroom->id)
                ->orderBy('event_time')
                ->get()
                ->groupBy('student_id');

            // Also consider events where student_id is null but participant_email exists
            $eventsByEmail = LmsAttendanceEvent::query()
                ->where('classroom_id', $classroom->id)
                ->whereNull('student_id')
                ->whereNotNull('participant_email')
                ->orderBy('event_time')
                ->get()
                ->groupBy('participant_email');

            // Build a map of student_id => events collection
            $studentEventMap = [];

            foreach ($events as $studentId => $group) {
                $studentEventMap[$studentId] = $group->values();
            }

            foreach ($eventsByEmail as $email => $group) {
                $student = LmsStudent::query()->where('email', $email)->first();
                if ($student) {
                    $existing = $studentEventMap[$student->id] ?? collect();
                    $studentEventMap[$student->id] = $existing->concat($group->values())->sortBy('event_time')->values();
                }
            }

            foreach ($studentEventMap as $studentId => $eventsCollection) {
                $eventsList = $eventsCollection->values();

                $intervals = [];
                $openJoin = null;
                foreach ($eventsList as $evt) {
                    if ($evt->event_type === 'joined') {
                        if ($openJoin === null) {
                            $openJoin = Carbon::parse($evt->event_time);
                        }
                    } elseif ($evt->event_type === 'left') {
                        if ($openJoin !== null) {
                            $leave = Carbon::parse($evt->event_time);
                            if ($leave->lt($openJoin)) {
                                // ignore bad ordering
                                continue;
                            }
                            $intervals[] = [$openJoin, $leave];
                            $openJoin = null;
                        }
                    }
                }

                // if still open, close at classroom end
                if ($openJoin !== null) {
                    $intervals[] = [$openJoin, Carbon::parse($classroom->ends_at)];
                }

                $totalSeconds = 0;
                $firstJoinedAt = null;
                foreach ($intervals as [$s, $e]) {
                    $firstJoinedAt = $firstJoinedAt ?? $s;
                    $totalSeconds += max(0, $e->diffInSeconds($s));
                }

                $durationSeconds = Carbon::parse($classroom->starts_at)->diffInSeconds(Carbon::parse($classroom->ends_at ?: $classroom->starts_at));

                $joinedWithin10 = false;
                if ($firstJoinedAt) {
                    $joinedWithin10 = $firstJoinedAt->lte(Carbon::parse($classroom->starts_at)->addMinutes(10));
                }

                $ratio = $durationSeconds > 0 ? ($totalSeconds / max(1, $durationSeconds)) : 0;

                // Status logic per spec
                if ($joinedWithin10 && $ratio >= 0.6) {
                    $status = 'present';
                } elseif ($joinedWithin10 && $ratio < 0.6) {
                    $status = 'partial';
                } elseif (! $joinedWithin10 && $ratio >= 0.6) {
                    $status = 'late';
                } else {
                    $status = 'absent';
                }

                LmsAttendanceRecord::query()->updateOrCreate([
                    'classroom_id' => $classroom->id,
                    'student_id' => $studentId,
                ], [
                    'total_seconds' => $totalSeconds,
                    'joined_within_10min' => $joinedWithin10,
                    'first_joined_at' => $firstJoinedAt,
                    'status' => $status,
                    'calculated_at' => now(),
                ]);
            }
        }

        $this->info('Attendance calculation completed.');

        return 0;
    }
}
