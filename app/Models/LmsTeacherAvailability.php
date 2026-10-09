<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly window a teacher is open for one-on-one bookings, e.g. "Monday
 * 09:00 to 12:00". Times are in the academy's timezone (OneOnOne::tz()).
 */
class LmsTeacherAvailability extends Model
{
    use TenantAware;

    protected $table = 'lms_teacher_availability';

    protected $fillable = [
        'teacher_id',
        'weekday',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'weekday' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(LmsTeacher::class, 'teacher_id');
    }
}
