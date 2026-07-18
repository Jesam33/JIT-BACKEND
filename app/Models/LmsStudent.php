<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LmsStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'training_registration_id',
        'first_name',
        'last_name',
        'username',
        'date_of_birth',
        'phone',
        'gender',
        'email',
        'password',
        'selected_course_id',
        'learning_mode',
        'profile_photo_url',
        'notify_class_reminders',
        'notify_chat',
        'notify_announcements',
        'onboarding_completed',
        'referred_by_agent_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'notify_class_reminders' => 'boolean',
        'notify_chat' => 'boolean',
        'notify_announcements' => 'boolean',
        'onboarding_completed' => 'boolean',
    ];

    public function taskSubmissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsTaskSubmission::class, 'student_id');
    }

    public function enrollments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsEnrollment::class, 'student_id');
    }

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'selected_course_id');
    }
}
