<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsSession extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'role',
        'user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
    ];
}
