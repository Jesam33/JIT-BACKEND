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
        // Local uploaded file (PDF/document from a staffer's PC), on the public
        // disk; kept so deleting the material also deletes the file. Videos
        // never set this (their bytes live on Bunny Stream).
        'file_path',
        'session_id',
        // Externally-hosted video (Bunny Stream) pointers, bytes never on our server.
        'provider',
        'external_id',
        'thumbnail_url',
        'duration_seconds',
        'status',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
    ];
}
