<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsTrack extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'name',
        'instructor_id',
        'batch_id',
        'course_id',
    ];

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
