<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Whole days a teacher is away: no one-on-one slots are offered on them. */
class LmsTeacherTimeOff extends Model
{
    use TenantAware;

    protected $table = 'lms_teacher_time_off';

    protected $fillable = [
        'teacher_id',
        'starts_on',
        'ends_on',
        'note',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(LmsTeacher::class, 'teacher_id');
    }
}
