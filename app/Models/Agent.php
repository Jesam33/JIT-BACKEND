<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'home_address', 'qualification',
        'custom_answers', 'referral_code', 'status', 'password', 'approved_at',
    ];

    protected $casts = [
        'custom_answers' => 'array',
        'approved_at' => 'datetime',
    ];

    protected $hidden = ['password'];
}
