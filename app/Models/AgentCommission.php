<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCommission extends Model
{
    protected $fillable = [
        'agent_id', 'enrollment_id', 'course_price',
        'commission_amount', 'status', 'type', 'notes',
    ];

    protected $casts = [
        'course_price' => 'decimal:2',
        'commission_amount' => 'decimal:2',
    ];
}
