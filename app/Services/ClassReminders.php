<?php

namespace App\Services;

use App\Models\LmsClassroom;
use App\Models\LmsEnrollment;
use App\Models\LmsNotification;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Your class starts in 20 minutes" and "… in 5 minutes", to every student of a
 * GROUP class (module scheduled classes and per-course classrooms; one-on-one
 * sessions have their own reminder in OneOnOneBooking). In the app, and by
 * email through the notification sweep.
 *
 * A reminder only goes out if the class already existed at that reminder's
 * moment: a class created at 10:48 for 10:50 gets no reminders (its students
 * already have the "new class" notice and email from a minute ago), one created
 * at 10:40 skips the 20-minute reminder but gets the 5-minute one. If both fall
 * due in the same run (the scheduler was late), only the 5-minute one is sent.
 * Every reminder is stamped once handled, sent or skipped, so nothing repeats.
 * Students who turned off class reminders in their profile aren't sent them.
 */
class ClassReminders
{
    /** Minutes before the start, latest first. */
    public const POINTS = [5, 20];

    public static function run(): int
    {
        $sent = 0;
        $horizon = now()->addMinutes(max(self::POINTS));

        $scheduled = LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
            ->with(['module' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)->select('id', 'course_id')])
            ->whereNull('student_id') // group classes only
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', $horizon)
            ->where(fn ($q) => $q->whereNull('reminder_20_sent_at')->orWhereNull('reminder_5_sent_at'))
            ->get();

        foreach ($scheduled as $class) {
            $sent += static::remind($class, (int) ($class->module?->course_id ?? 0), 'scheduled_class');
        }

        $classrooms = LmsClassroom::query()->withoutGlobalScope(TenantScope::class)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', $horizon)
            ->where(fn ($q) => $q->whereNull('reminder_20_sent_at')->orWhereNull('reminder_5_sent_at'))
            ->get();

        foreach ($classrooms as $class) {
            $sent += static::remind($class, (int) ($class->course_id ?? 0), 'classroom');
        }

        return $sent;
    }

    /** Handle whichever reminder is due for one class; returns notifications created. */
    private static function remind(Model $class, int $courseId, string $refType): int
    {
        /** @var Carbon $start */
        $start = $class->starts_at;
        $minutesLeft = now()->diffInSeconds($start, false) / 60;

        // The latest point we've reached (5 before 20): only that one is sent.
        $due = null;
        foreach (self::POINTS as $point) {
            if ($minutesLeft <= $point && $class->{"reminder_{$point}_sent_at"} === null) {
                $due = $point;
                break;
            }
        }
        if ($due === null) {
            return 0;
        }

        // Stamp this point and any earlier one we passed, before sending, so a
        // failure never turns into a reminder every minute.
        $stamps = [];
        foreach (self::POINTS as $point) {
            if ($point >= $due && $class->{"reminder_{$point}_sent_at"} === null) {
                $stamps["reminder_{$point}_sent_at"] = now();
            }
        }
        $class->forceFill($stamps)->saveQuietly();

        // Created after this reminder's moment: the "new class" notice covers it.
        $created = $class->created_at;
        if (! $created || $created->greaterThan($start->copy()->subMinutes($due))) {
            return 0;
        }

        $tenant = $class->tenant_id ? Tenant::find($class->tenant_id) : null;
        if (! $tenant || ! $courseId) {
            return 0;
        }

        $studentIds = static::students((int) $tenant->id, $courseId);
        if ($studentIds === []) {
            return 0;
        }

        $when = \App\Support\LocalTime::marker($start, 'time'); // each reader's own time
        $title = "Your class starts in {$due} minutes";
        $body = '"' . $class->title . '" starts at ' . $when . '. Join it from your Classroom page.';

        foreach ($studentIds as $studentId) {
            LmsNotification::createForTenant((int) $tenant->id, [
                'student_id' => $studentId,
                'type' => 'class_reminder',
                'title' => $title,
                'body' => $body,
                'reference_type' => $refType,
                'reference_id' => $class->id,
            ]);
        }

        return count($studentIds);
    }

    /**
     * The class's students: everyone in the course (chosen course or a cohort
     * placement), the same audience as the "new class" notice, minus one-on-one
     * students (a group class isn't theirs) and anyone who turned reminders off.
     *
     * @return int[]
     */
    private static function students(int $tenantId, int $courseId): array
    {
        $byCourse = LmsStudent::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)->where('selected_course_id', $courseId)->pluck('id');
        // Course ids are unique across academies, so the course pins the academy.
        $byEnrollment = LmsEnrollment::query()->withoutGlobalScope(TenantScope::class)
            ->whereHas('track', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('course_id', $courseId))
            ->pluck('student_id');

        $ids = $byCourse->merge($byEnrollment)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return LmsStudent::query()->withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('learning_mode')->orWhere('learning_mode', '!=', LmsStudent::MODE_ONE_ON_ONE))
            ->where(fn ($q) => $q->whereNull('notify_class_reminders')->orWhere('notify_class_reminders', true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
