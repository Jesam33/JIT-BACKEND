<?php

namespace App\Services;

use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Scopes\TenantScope;
use App\Support\Notify;
use Illuminate\Support\Carbon;

/**
 * One-on-one classes: a student takes their course with private live sessions
 * and their own teacher instead of the cohort's group classes.
 *
 *   - Teacher: lms_students.one_on_one_teacher_id. Assigned automatically to
 *     the instructor of the course's cohort when the student is enrolled
 *     (ensureTeacher); the owner can change it. None available → the owner
 *     is told the student "needs a teacher".
 *   - Sessions: lms_scheduled_classes rows with student_id set. Only that
 *     student and their teacher see them (LmsScheduledClass::scopeVisibleTo).
 *   - Teacher leaves: future sessions are cancelled, the student is told, the
 *     owner is told to assign someone new (teacherLeaving).
 */
class OneOnOne
{
    /**
     * Give a one-on-one student a teacher if they don't have one: the
     * instructor of their cohort, else of any cohort of their course. Returns
     * the teacher, or null when nobody is available (owner notified once when
     * $notifyOwnerIfNone).
     */
    public static function ensureTeacher(LmsStudent $student, bool $notifyOwnerIfNone = false): ?LmsTeacher
    {
        if (! $student->isOneOnOne()) {
            return null;
        }

        if ($student->one_on_one_teacher_id) {
            return LmsTeacher::query()->withoutGlobalScope(TenantScope::class)->find($student->one_on_one_teacher_id);
        }

        $teacherId = static::defaultTeacherId($student);

        if ($teacherId) {
            $teacher = LmsTeacher::query()->withoutGlobalScope(TenantScope::class)->find($teacherId);
            if ($teacher) {
                static::assignTeacher($student, $teacher);

                return $teacher;
            }
        }

        if ($notifyOwnerIfNone) {
            $name = static::name($student);
            Notify::owner(
                'one_on_one_needs_teacher',
                'New one-on-one student: ' . $name,
                $name . ' is taking ' . ($student->course?->title ?? 'a course')
                    . ' one-on-one and needs a teacher. Assign one on the Students page.',
                'student',
                $student->id,
                $student->tenant_id ? (int) $student->tenant_id : null
            );
        }

        return null;
    }

    /** Assign (or change) a one-on-one student's teacher and tell both of them. */
    public static function assignTeacher(LmsStudent $student, LmsTeacher $teacher): void
    {
        $changed = (int) $student->one_on_one_teacher_id !== (int) $teacher->id;

        $student->forceFill(['one_on_one_teacher_id' => $teacher->id])->saveQuietly();

        if (! $changed) {
            return;
        }

        $name = static::name($student);
        $course = $student->course?->title ?? 'their course';

        Notify::staff(
            $teacher->id,
            'one_on_one_assigned',
            'New one-on-one student: ' . $name,
            $name . ' is taking ' . $course . ' one-on-one with you. Schedule their first session from One-on-one students.',
            'student',
            $student->id,
            // The owner may teach one-on-one themselves; they still need the note.
            true
        );

        Notify::student(
            $student->id,
            'one_on_one_teacher',
            'Your one-on-one teacher',
            $teacher->name . ' is your teacher for ' . $course . '. They will schedule your sessions with you.',
            'course',
            $student->selected_course_id
        );
    }

    /**
     * The instructor a new one-on-one student is given by default: their own
     * cohort's, else the first cohort of their course that has one.
     */
    private static function defaultTeacherId(LmsStudent $student): ?int
    {
        $trackId = LmsEnrollment::query()->withoutGlobalScope(TenantScope::class)
            ->where('student_id', $student->id)->value('track_id');

        $fromTrack = $trackId
            ? LmsTrack::query()->withoutGlobalScope(TenantScope::class)->whereKey($trackId)->value('instructor_id')
            : null;

        if ($fromTrack) {
            return (int) $fromTrack;
        }

        if (! $student->selected_course_id) {
            return null;
        }

        $any = LmsTrack::query()->withoutGlobalScope(TenantScope::class)
            ->where('course_id', $student->selected_course_id)
            ->whereNotNull('instructor_id')
            ->orderBy('id')
            ->value('instructor_id');

        return $any ? (int) $any : null;
    }

    /**
     * Schedule a private session. The module must belong to the student's
     * course; the room is created on first join like any class.
     */
    public static function scheduleSession(LmsTeacher $teacher, LmsStudent $student, LmsModule $module, Carbon $startsAt, Carbon $endsAt, ?string $title = null, ?string $description = null): LmsScheduledClass
    {
        $session = LmsScheduledClass::query()->create([
            'module_id' => $module->id,
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'title' => $title ?: ('One-on-one: ' . $module->title),
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'scheduled',
            'booked_by' => 'teacher',
        ]);

        Notify::student(
            $student->id,
            'class_scheduled',
            'One-on-one session scheduled',
            $teacher->name . ' scheduled your one-on-one session "' . $session->title . '" for '
                . $startsAt->copy()->setTimezone(static::tz())->format('l j M, g:ia') . '.',
            'scheduled_class',
            $session->id
        );

        return $session;
    }

    /** Move a private session and tell the student. */
    public static function rescheduleSession(LmsScheduledClass $session, Carbon $startsAt, Carbon $endsAt): void
    {
        $session->update(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'scheduled']);

        Notify::student(
            (int) $session->student_id,
            'class_rescheduled',
            'One-on-one session moved',
            'Your session "' . $session->title . '" is now on '
                . $startsAt->copy()->setTimezone(static::tz())->format('l j M, g:ia') . '.',
            'scheduled_class',
            $session->id
        );
    }

    /** Cancel a private session and tell the student. */
    public static function cancelSession(LmsScheduledClass $session, string $reason = ''): void
    {
        $session->update(['status' => 'cancelled']);

        Notify::student(
            (int) $session->student_id,
            'class_cancelled',
            'One-on-one session cancelled',
            'Your session "' . $session->title . '" on '
                . $session->starts_at?->copy()->setTimezone(static::tz())->format('l j M, g:ia') . ' was cancelled.'
                . ($reason !== '' ? ' ' . $reason : ''),
            'scheduled_class',
            $session->id
        );
    }

    /**
     * A teacher's account is being removed: cancel their upcoming private
     * sessions (students told), unassign their students, tell the owner who
     * now needs a teacher. Called from LmsTeacher::purge().
     */
    public static function teacherLeaving(LmsTeacher $teacher): void
    {
        LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
            ->where('teacher_id', $teacher->id)
            ->whereNotNull('student_id')
            ->where('starts_at', '>', now())
            ->where('status', '!=', 'cancelled')
            ->get()
            ->each(fn (LmsScheduledClass $s) => static::cancelSession(
                $s,
                'Your teacher is no longer at the academy; your academy will assign you a new one.'
            ));

        LmsStudent::query()->withoutGlobalScope(TenantScope::class)
            ->where('one_on_one_teacher_id', $teacher->id)
            ->get()
            ->each(function (LmsStudent $student) {
                $student->forceFill(['one_on_one_teacher_id' => null])->saveQuietly();
                $name = static::name($student);
                Notify::owner(
                    'one_on_one_needs_teacher',
                    'One-on-one student needs a teacher: ' . $name,
                    $name . "'s teacher has left. Assign a new one on the Students page; their upcoming sessions were cancelled.",
                    'student',
                    $student->id,
                    $student->tenant_id ? (int) $student->tenant_id : null
                );
            });
    }

    /**
     * Who a student's private messages go to: their one-on-one teacher, else
     * their cohort's instructor (group students). Null = nobody yet.
     */
    public static function dmInstructorId(LmsStudent $student, ?LmsTrack $track): ?int
    {
        if ($student->isOneOnOne()) {
            return $student->one_on_one_teacher_id ? (int) $student->one_on_one_teacher_id : null;
        }

        return $track?->instructor_id ? (int) $track->instructor_id : null;
    }

    private static function name(LmsStudent $student): string
    {
        return trim($student->first_name . ' ' . $student->last_name) ?: (string) $student->email;
    }

    /** Session times in notifications are shown in the academy's local time. */
    private static function tz(): string
    {
        return (string) config('saas.monthly_reminder_timezone', 'Africa/Lagos');
    }
}
