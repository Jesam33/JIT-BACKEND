<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;

/**
 * A teacher's saved live-quiz round for a course: a list of questions
 * ({prompt, options[2-4], correct_index, seconds}) they can run in any class.
 */
class LmsLiveQuizRound extends Model
{
    use TenantAware;

    protected $fillable = ['course_id', 'title', 'questions', 'created_by'];

    protected $casts = ['questions' => 'array'];
}
