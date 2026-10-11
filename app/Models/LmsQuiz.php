<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A module's quiz: 5 multiple-choice questions written by the teacher,
 * auto-marked, one attempt per student, with a scoreboard (App\Services\Quizzes).
 */
class LmsQuiz extends Model
{
    use TenantAware;

    protected $table = 'lms_quizzes';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** Every quiz has exactly this many questions. */
    public const QUESTIONS = 5;

    protected $fillable = [
        'course_id',
        'module_id',
        'title',
        'instructions',
        'time_limit_minutes',
        'closes_at',
        'status',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'time_limit_minutes' => 'integer',
        'closes_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(LmsModule::class, 'module_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuizQuestion::class, 'quiz_id')->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsQuizAttempt::class, 'quiz_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** Closed = past its close date (answers can be revealed). */
    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    /** Open for students to take right now. */
    public function isOpen(): bool
    {
        return $this->isPublished() && ! $this->isClosed();
    }
}
