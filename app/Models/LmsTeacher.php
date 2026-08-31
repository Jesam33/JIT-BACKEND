<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsTeacher extends Model
{
    use HasFactory;
    use TenantAware;

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

    /**
     * Display the photo as an absolute URL rebuilt against the CURRENT host, so a
     * host baked in at upload time can't break it (see App\Support\MediaUrl).
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return \App\Support\MediaUrl::url($this->attributes['profile_photo_url'] ?? null);
    }

    /** Store the photo as a relative disk path, never a host-frozen absolute URL. */
    public function setProfilePhotoUrlAttribute($value): void
    {
        $this->attributes['profile_photo_url'] = \App\Support\MediaUrl::toStorage($value);
    }
}
