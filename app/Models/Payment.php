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
        'kind',
        'platform_fee',
        'academy_amount',
        'period_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'platform_fee' => 'decimal:2',
        'academy_amount' => 'decimal:2',
        'period_end' => 'datetime',
    ];

    public const KIND_INITIAL = 'initial';

    /** A monthly course's second-or-later payment (see App\Services\CourseBilling). */
    public const KIND_RENEWAL = 'renewal';

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TrainingRegistration::class, 'registration_id');
    }
}
