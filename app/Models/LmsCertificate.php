<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsCertificate extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'course_id',
        'title',
        'file_url',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
