<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class Payment extends Model
{
    use TenantAware;

    protected $fillable = [
        'registration_id',
        'reference',
        'amount',
        'currency',
        'status',
        'gateway',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TrainingRegistration::class, 'registration_id');
    }
}
