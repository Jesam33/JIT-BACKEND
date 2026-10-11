<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quiz question: a prompt, 2-4 answer options, and which one is correct.
 * correct_index is NEVER sent to a student before the quiz closes.
 */
class LmsQuizQuestion extends Model
{
    use TenantAware;

    protected $table = 'lms_quiz_questions';

    protected $fillable = [
        'quiz_id',
        'position',
        'prompt',
        'options',
        'correct_index',
        'explanation',
    ];

    protected $casts = [
        'options' => 'array',
        'position' => 'integer',
        'correct_index' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(LmsQuiz::class, 'quiz_id');
    }
}
