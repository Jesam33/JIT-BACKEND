<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class LmsModuleContent extends Model
{
    use TenantAware;
    protected $fillable = [
        'module_id',
        'title',
        'type',
        'content_url',
        'content_body',
        'file_path',
        'sort_order',
        // Externally-hosted video (Bunny Stream) pointers, bytes never on our server.
        'provider',
        'external_id',
        'thumbnail_url',
        'duration_seconds',
        'status',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(LmsModule::class, 'module_id');
    }
}
