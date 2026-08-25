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
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

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
}
