<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsMessage extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'chat_type',
        'chat_id',
        'sender_role',
        'sender_id',
        'content',
        'attachment_url',
        'deleted_at',
        'reply_to_id',
        'from_role',
        'from_id',
        'to_role',
        'to_id',
        'body',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function teacher(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsTeacher::class, 'sender_id');
    }

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'sender_id');
    }

    /**
     * The message this one is replying to (nullable). Self-referential; the
     * quoted preview in the UI is built from this relation's content + sender.
     */
    public function replyTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsMessage::class, 'reply_to_id');
    }

    public function reactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsMessageReaction::class, 'message_id');
    }
}
