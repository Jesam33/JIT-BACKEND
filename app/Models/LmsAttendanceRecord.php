<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
