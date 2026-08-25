<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class LmsAttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'student_id',
        'total_seconds',
        'joined_within_10min',
        'first_joined_at',
        'status',
        'calculated_at',
    ];

    protected $casts = [
        'first_joined_at' => 'datetime',
        'calculated_at' => 'datetime',
        'joined_within_10min' => 'boolean',
    ];

    // The staff Attendance endpoint eager-loads these (->with(['student','classroom']));
    // without them Eloquent throws RelationNotFoundException → 500 → the page shows
    // "No attendance records yet." even when records exist.
    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(LmsClassroom::class, 'classroom_id');
    }
}
