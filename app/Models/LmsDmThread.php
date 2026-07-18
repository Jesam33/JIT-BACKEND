<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsDmThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'instructor_id',
        'track_id',
    ];

    public function student()
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function messages()
    {
        return $this->hasMany(LmsMessage::class, 'chat_id')
            ->where('chat_type', 'dm');
    }
}
