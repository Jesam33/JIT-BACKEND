<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's one attempt at a quiz: answers saved as they go
 * ({question_id: option_index}), then the score and time taken on submit.
 */
class LmsQuizAttempt extends Model
{
    use TenantAware;

    protected $table = 'lms_quiz_attempts';

    protected $fillable = [
        'quiz_id',
        'student_id',
        'started_at',
        'submitted_at',
        'answers',
        'score',
        'total',
        'duration_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
        'score' => 'integer',
        'total' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(LmsQuiz::class, 'quiz_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
