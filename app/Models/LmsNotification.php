<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsNotification extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'type',
        'title',
        'body',
        'reference_type',
        'reference_id',
        'is_read',
        'emailed_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'emailed_at' => 'datetime',
    ];
}
