<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One playing of a live-quiz round inside one live class. `questions` is a
 * snapshot taken at start, so editing the saved round never changes a quiz
 * that's running or its results. The live state is `phase` (lobby → question
 * → reveal → … → finished), `current_index` and `phase_started_at`; the
 * App\Services\LiveQuizzes service moves it along.
 */
class LmsLiveQuizRun extends Model
{
    use TenantAware;

    public const LOBBY = 'lobby';

    public const QUESTION = 'question';

    public const REVEAL = 'reveal';

    public const FINISHED = 'finished';

    protected $fillable = [
        'round_id', 'course_id', 'class_type', 'class_id', 'title', 'questions',
        'phase', 'current_index', 'phase_started_at', 'auto_advance', 'started_by', 'finished_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'current_index' => 'integer',
        'phase_started_at' => 'datetime',
        'auto_advance' => 'boolean',
        'finished_at' => 'datetime',
    ];

    public function answers(): HasMany
    {
        return $this->hasMany(LmsLiveQuizAnswer::class, 'run_id');
    }

    public function isFinished(): bool
    {
        return $this->phase === self::FINISHED;
    }

    /** @return array{prompt: string, options: array<int, string>, correct_index: int, seconds: int}|null */
    public function currentQuestion(): ?array
    {
        return $this->questions[$this->current_index] ?? null;
    }
}
