<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsAttendance extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'classroom_id',
        'class_type',
        'scheduled_class_id',
        'joined_at',
        'first_joined_at',
        'last_left_at',
        'total_seconds',
        'status',
        'calculated_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'first_joined_at' => 'datetime',
        'last_left_at' => 'datetime',
        'calculated_at' => 'datetime',
    ];

    public function classroom()
    {
        return $this->belongsTo(LmsClassroom::class);
    }

    // Module-based delivery events (LmsScheduledClass) also track attendance
    // since 2026-09-11; exactly one of classroom/scheduledClass is set,
    // discriminated by class_type.
    public function scheduledClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsScheduledClass::class, 'scheduled_class_id');
    }

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }
}
