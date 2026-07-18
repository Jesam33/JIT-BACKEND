<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'module_id',
        'teacher_id',
        'title',
        'description',
        'instructions',
        'due_at',
        'submission_type',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public function submissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsTaskSubmission::class, 'task_id');
    }

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function module(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsModule::class, 'module_id');
    }
}
