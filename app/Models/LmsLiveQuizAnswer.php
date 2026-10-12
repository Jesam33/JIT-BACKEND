<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's answer to one question of a live quiz run, with its points. */
class LmsLiveQuizAnswer extends Model
{
    use TenantAware;

    protected $fillable = ['run_id', 'student_id', 'question_index', 'choice', 'correct', 'response_ms', 'points'];

    protected $casts = [
        'question_index' => 'integer',
        'choice' => 'integer',
        'correct' => 'boolean',
        'response_ms' => 'integer',
        'points' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }
}
