<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class LmsModule extends Model
{
    use TenantAware;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'objectives',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(LmsModuleContent::class, 'module_id')->orderBy('sort_order');
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(LmsScheduledClass::class, 'module_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LmsTask::class, 'module_id');
    }
}
