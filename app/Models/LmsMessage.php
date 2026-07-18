<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_type',
        'chat_id',
        'sender_role',
        'sender_id',
        'content',
        'attachment_url',
        'deleted_at',
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
}
