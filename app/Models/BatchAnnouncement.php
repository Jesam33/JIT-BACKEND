<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BatchAnnouncement extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'title',
        'body',
        'is_published',
    ];

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
}
