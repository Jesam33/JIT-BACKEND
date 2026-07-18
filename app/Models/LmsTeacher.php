<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsTeacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'username',
        'email',
        'role',
        'phone',
        'profile_photo_url',
        'password',
        'is_active',
        'zoom_user_id',
        'group_chat_read_at',
        'dm_chat_read_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'group_chat_read_at' => 'datetime',
        'dm_chat_read_at' => 'datetime',
    ];
}
