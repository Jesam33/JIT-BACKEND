<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsAttendanceEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'student_id',
        'participant_id',
        'participant_email',
        'event_type',
        'event_time',
    ];

    protected $casts = [
        'event_time' => 'datetime',
    ];
}
