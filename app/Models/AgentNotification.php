<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentNotification extends Model
{
    protected $fillable = [
        'agent_id', 'type', 'title', 'body',
        'reference_type', 'reference_id', 'is_read', 'emailed_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'emailed_at' => 'datetime',
    ];
}
