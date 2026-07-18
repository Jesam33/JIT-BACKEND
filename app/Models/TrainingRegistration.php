<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingRegistration extends Model
{
    use HasFactory;

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
        'status',
        'approved_by',
        'approved_at',
        'invite_token',
        'referred_by_agent_id',
        'registered_by_agent_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'course_price' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
