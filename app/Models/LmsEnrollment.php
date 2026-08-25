<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsEnrollment extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'track_id',
    ];

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function track(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsTrack::class, 'track_id');
    }
}
