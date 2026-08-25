<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsMessageReaction extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'tenant_id',
        'message_id',
        'user_role',
        'user_id',
        'emoji',
    ];

    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsMessage::class, 'message_id');
    }
}
