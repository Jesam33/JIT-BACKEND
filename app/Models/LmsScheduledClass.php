<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class LmsScheduledClass extends Model
{
    use TenantAware;
    protected $fillable = [
        'module_id',
        'teacher_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'meeting_url',
        'meeting_id',
        'meeting_password',
        'location',
        'status',
        // Set = a private one-on-one session for this one student; null = a
        // group class for everyone in the course. See scopeVisibleTo().
        'student_id',
        // 'teacher' | 'student' (self-booked one-on-one session).
        'booked_by',
        'reminder_sent_at',
        // What happened (one-on-one): see OUTCOME_* and OneOnOneOutcomes.
        'outcome',
        'outcome_marked_by',
        'outcome_marked_at',
        'no_show_reported_at',
        'series_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'outcome_marked_at' => 'datetime',
        'no_show_reported_at' => 'datetime',
    ];

    public const OUTCOME_HELD = 'held';

    /** Counts toward the student's allowance (they missed it). */
    public const OUTCOME_STUDENT_NO_SHOW = 'student_no_show';

    /** Does NOT count toward the student's allowance (the teacher missed it). */
    public const OUTCOME_TEACHER_NO_SHOW = 'teacher_no_show';

    public const OUTCOMES = [self::OUTCOME_HELD, self::OUTCOME_STUDENT_NO_SHOW, self::OUTCOME_TEACHER_NO_SHOW];

    public function module(): BelongsTo
    {
        return $this->belongsTo(LmsModule::class, 'module_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(LmsTeacher::class, 'teacher_id');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('starts_at', '>=', now())->where('status', '!=', 'cancelled');
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->whereHas('module', fn($q) => $q->where('course_id', $courseId));
    }

    /** The student a private one-on-one session is for. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function isPrivate(): bool
    {
        return $this->student_id !== null;
    }

    /**
     * The classes THIS student may see and join. The one rule every
     * student-facing read goes through, so a private session can never leak:
     *
     *   one-on-one student → only their own private sessions
     *   everyone else      → only group classes (never anyone's private session)
     */
    public function scopeVisibleTo($query, LmsStudent $student)
    {
        return $student->isOneOnOne()
            ? $query->where('student_id', $student->id)
            : $query->whereNull('student_id');
    }

    /** Whether this student may see / join this class (single-row form of visibleTo). */
    public function isVisibleTo(LmsStudent $student): bool
    {
        return $student->isOneOnOne()
            ? (int) $this->student_id === (int) $student->id
            : $this->student_id === null;
    }
}
