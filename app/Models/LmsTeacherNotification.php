<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class LmsTeacherNotification extends Model
{
    use TenantAware;
    protected $table = 'lms_teacher_notifications';

    protected $fillable = [
        'teacher_id',
        'type',
        'title',
        'body',
        'reference_type',
        'reference_id',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(LmsTeacher::class);
    }
}
