<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra one-on-one sessions a student bought on top of their course's
 * allowance. `period` is the month they're for ('Y-m', monthly courses) or
 * null (the whole course). Only `paid` rows count. See OneOnOneExtras.
 */
class LmsOneOnOneCredit extends Model
{
    use TenantAware;

    protected $table = 'lms_one_on_one_credits';

    protected $fillable = [
        'student_id',
        'payment_id',
        'sessions',
        'period',
        'status',
    ];

    protected $casts = [
        'sessions' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
