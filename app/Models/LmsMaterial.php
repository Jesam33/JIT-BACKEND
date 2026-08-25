<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsMaterial extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'file_url',
        'session_id',
    ];
}
