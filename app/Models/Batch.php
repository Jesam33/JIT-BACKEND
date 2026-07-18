<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'registration_starts_at',
        'registration_ends_at',
    ];

    protected $casts = [
        'registration_starts_at' => 'datetime',
        'registration_ends_at' => 'datetime',
    ];

    public function tracks()
    {
        return $this->hasMany(LmsTrack::class);
    }
}
