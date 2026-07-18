<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSession extends Model
{
    protected $fillable = ['agent_id', 'token', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
