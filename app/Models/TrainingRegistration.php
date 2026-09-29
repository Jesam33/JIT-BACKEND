<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class TrainingRegistration extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'qualification_level',
        'phone_number',
        'email',
        'whatsapp',
        'course_id',
        'course_name',
        'learning_mode',
        'course_price',
        'charge_currency',
        'status',
        'approved_by',
        'approved_at',
        'invite_token',
        'referred_by_agent_id',
        'registered_by_agent_id',
        'billing_type',
        'paid_until',
        'billing_status',
        'paystack_authorization',
        'billing_cancelled_at',
        'billing_reminded_for',
        'billing_last_attempt_at',
        'billing_lock_notified_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'course_price' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_until' => 'datetime',
        // A reusable Paystack card authorization can be charged without the
        // student present, so it is never stored in the clear.
        'paystack_authorization' => 'encrypted',
        'billing_cancelled_at' => 'datetime',
        'billing_reminded_for' => 'datetime',
        'billing_last_attempt_at' => 'datetime',
        'billing_lock_notified_at' => 'datetime',
    ];

    /**
     * Never serialize the card authorization, whatever endpoint returns this row.
     */
    protected $hidden = ['paystack_authorization'];

    protected static function booted(): void
    {
        // Freeze the billing type from the course at the moment of registration,
        // on EVERY path that creates one (public storefront, agent, owner invite),
        // so no caller can forget it. A registration that costs nothing (a comped
        // invite, a free course) is never billed monthly: there is nothing to
        // renew. An explicit billing_type from the caller wins.
        static::creating(function (TrainingRegistration $registration): void {
            if ($registration->getAttribute('billing_type')) {
                return;
            }

            $monthly = false;
            if ((float) $registration->course_price > 0 && $registration->course_id) {
                $monthly = LmsCourse::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->whereKey($registration->course_id)
                    ->value('billing_type') === LmsCourse::BILLING_MONTHLY;
            }

            $registration->billing_type = $monthly ? LmsCourse::BILLING_MONTHLY : LmsCourse::BILLING_ONE_TIME;
        });
    }

    public function isMonthly(): bool
    {
        return $this->billing_type === LmsCourse::BILLING_MONTHLY;
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'registration_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
