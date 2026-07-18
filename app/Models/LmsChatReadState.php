<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LmsChatReadState extends Model
{
    protected $fillable = [
        'student_id',
        'chat_type',
        'chat_id',
        'last_read_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];
}
