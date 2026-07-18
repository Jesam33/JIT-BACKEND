<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsModuleContent extends Model
{
    protected $fillable = [
        'module_id',
        'title',
        'type',
        'content_url',
        'content_body',
        'file_path',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(LmsModule::class, 'module_id');
    }
}
