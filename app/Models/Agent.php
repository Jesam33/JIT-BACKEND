<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class Agent extends Model
{
    use TenantAware;
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

    /**
     * Display the avatar as an absolute URL rebuilt against the CURRENT host, so a
     * host baked in at upload time can't break it (see App\Support\MediaUrl).
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return \App\Support\MediaUrl::url($this->attributes['avatar'] ?? null);
    }

    /** Store the avatar as a relative disk path, never a host-frozen absolute URL. */
    public function setAvatarAttribute($value): void
    {
        $this->attributes['avatar'] = \App\Support\MediaUrl::toStorage($value);
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
