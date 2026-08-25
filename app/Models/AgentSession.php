<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class AgentSession extends Model
{
    use TenantAware;
    protected $fillable = ['agent_id', 'token', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
