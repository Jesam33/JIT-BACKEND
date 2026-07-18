<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'title',
        'file_url',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];
}
