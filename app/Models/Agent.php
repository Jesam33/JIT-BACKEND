<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'home_address', 'qualification',
        'custom_answers', 'referral_code', 'status', 'password', 'approved_at',
        'avatar', 'bank_name', 'account_number', 'account_name',
    ];

    protected $casts = [
        'custom_answers' => 'array',
        'approved_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    protected $appends = ['profile_photo_url'];

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->avatar;
    }

    public function sessions()
    {
        return $this->hasMany(AgentSession::class);
    }

    public function notifications()
    {
        return $this->hasMany(AgentNotification::class);
    }

    public function commissions()
    {
        return $this->hasMany(AgentCommission::class);
    }
}
