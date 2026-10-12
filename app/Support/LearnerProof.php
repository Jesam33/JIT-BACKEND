<?php

namespace App\Support;

use App\Models\LmsAttendance;
use App\Models\LmsCertificate;
use App\Models\LmsClassroom;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsQuizAttempt;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTaskSubmission;
use App\Models\LmsTrack;

/**
 * What a student has actually done in their course, from the platform's own
 * records: classes attended out of classes held, tasks + quizzes completed,
 * and whether they finished (certificate issued). It is the proof behind a
 * verified-learner review and the student dashboard's attendance rate, so the
 * two always tell the same story.
 *
 * Eligibility to review (config saas.reviews) is any of:
 *   - finished the course;
 *   - attended >= min_attended classes AND >= min_attendance_percent of the
 *     classes held so far;
 *   - completed >= min_activities tasks and quizzes (self-paced learners).
 */
class LearnerProof
{
    /** The course a student is tied to: the one they picked, else their enrollment's track course. */
    public static function courseId(LmsStudent $student): ?int
    {
        if ($student->selected_course_id) {
            return (int) $student->selected_course_id;
        }
        $enrollment = LmsEnrollment::query()->where('student_id', $student->id)->first();
        $track = $enrollment ? LmsTrack::query()->find($enrollment->track_id) : null;

        return $track?->course_id ? (int) $track->course_id : null;
    }

    /**
     * Classes held so far for this student (both delivery types, started, not
     * cancelled; a one-on-one student counts only their own sessions) and how
     * many of them they attended (an instant join-and-leave doesn't count).
     *
     * @return array{held: int, attended: int}
     */
    public static function attendance(LmsStudent $student, LmsCourse $course): array
    {
        $oneOnOne = $student->isOneOnOne();

        $classroomIds = $oneOnOne ? collect() : LmsClassroom::query()
            ->where('course_id', $course->id)
            ->where('starts_at', '<=', now())
            ->pluck('id');

        $scheduledIds = LmsScheduledClass::query()
            ->whereHas('module', fn ($q) => $q->where('course_id', $course->id))
            ->visibleTo($student)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', now())
            ->pluck('id');

        $attended = LmsAttendance::query()
            ->where('student_id', $student->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhereIn('status', ['present', 'partial', 'late', 'made_up']))
            ->where(function ($q) use ($classroomIds, $scheduledIds) {
                $q->where(function ($w) use ($classroomIds) {
                    $w->where('class_type', 'classroom')->whereIn('classroom_id', $classroomIds);
                })->orWhere(function ($w) use ($scheduledIds) {
                    $w->where('class_type', 'scheduled')->whereIn('scheduled_class_id', $scheduledIds);
                });
            })
            ->count();

        return ['held' => $classroomIds->count() + $scheduledIds->count(), 'attended' => $attended];
    }

    /**
     * Everything that proves this student learned in $courseId, plus whether it
     * is enough to leave a verified review and, if not, what's still missing.
     *
     * @return array{course_id: int, attended: int, held: int, activities: int, finished: bool, eligible: bool, missing: ?string}
     */
    public static function for(LmsStudent $student, int $courseId): array
    {
        $course = LmsCourse::query()->find($courseId);
        ['held' => $held, 'attended' => $attended] = $course
            ? static::attendance($student, $course)
            : ['held' => 0, 'attended' => 0];

        $tasks = LmsTaskSubmission::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->whereHas('task', fn ($q) => $q->where('course_id', $courseId))
            ->count();

        $quizzes = LmsQuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->whereHas('quiz', fn ($q) => $q->where('course_id', $courseId))
            ->count();

        $finished = LmsCertificate::query()
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->exists();

        $minAttended = max(1, (int) config('saas.reviews.min_attended', 3));
        $minPercent = max(0, min(100, (int) config('saas.reviews.min_attendance_percent', 50)));
        $minActivities = max(1, (int) config('saas.reviews.min_activities', 3));

        $activities = $tasks + $quizzes;
        $byAttendance = $attended >= $minAttended && $held > 0 && ($attended / $held) * 100 >= $minPercent;
        $byActivities = $activities >= $minActivities;
        $eligible = $finished || $byAttendance || $byActivities;

        $missing = null;
        if (! $eligible) {
            if ($held > 0) {
                $needed = max($minAttended - $attended, (int) ceil($held * $minPercent / 100) - $attended, 1);
                $missing = "Attend {$needed} more " . ($needed === 1 ? 'class' : 'classes')
                    . ' (or complete ' . ($minActivities - $activities) . ' more tasks or quizzes) to leave a review.';
            } else {
                $left = $minActivities - $activities;
                $missing = "Complete {$left} more " . ($left === 1 ? 'task or quiz' : 'tasks or quizzes')
                    . ', or attend your first classes, to leave a review.';
            }
        }

        return [
            'course_id' => $courseId,
            'attended' => $attended,
            'held' => $held,
            'activities' => $activities,
            'finished' => $finished,
            'eligible' => $eligible,
            'missing' => $missing,
        ];
    }
}
