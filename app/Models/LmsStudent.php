<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Scopes\TenantScope;
use App\Traits\TenantAware;

class LmsStudent extends Model
{
    use HasFactory;
    use TenantAware;

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

    protected static function booted(): void
    {
        // Every student needs a stable @handle for chat mentions. SaaS students
        // are provisioned via updateOrCreate() without one, which broke the
        // @mention dropdown, insertion and notification chain (all keyed on the
        // username). Fill it on create — this runs AFTER TenantAware has stamped
        // tenant_id, so the handle is only made unique WITHIN the institute.
        static::creating(function (self $student): void {
            if (empty($student->username)) {
                $student->username = static::generateUsername($student);
            }
        });
    }

    /**
     * Build a dotless, per-tenant-unique @handle from the student's name (falling
     * back to the email local-part). Dotless so it is a single \w+ token: the
     * mention parser, the inline renderer and the notification lookup all key on
     * \w+, so "@ada.bloom" would only ever match "ada" — "adabloom" is safe.
     */
    protected static function generateUsername(self $student): string
    {
        $first = preg_replace('/[^a-z0-9]/i', '', (string) $student->first_name);
        $last = preg_replace('/[^a-z0-9]/i', '', (string) $student->last_name);
        $base = strtolower($first . $last);

        if ($base === '') {
            $base = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok((string) $student->email, '@')));
        }
        if ($base === '') {
            $base = 'user';
        }

        $tenantId = $student->tenant_id;
        $candidate = $base;
        $n = 1;

        while (
            static::withoutGlobalScope(TenantScope::class)
                ->when(
                    $tenantId,
                    fn ($q) => $q->where('tenant_id', $tenantId),
                    fn ($q) => $q->whereNull('tenant_id')
                )
                ->where('username', $candidate)
                ->exists()
        ) {
            $candidate = $base . $n;
            $n++;
        }

        return $candidate;
    }

    /**
     * Display the photo as an absolute URL rebuilt against the CURRENT host, so a
     * host baked in at upload time can't break it (see App\Support\MediaUrl).
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return \App\Support\MediaUrl::url($this->attributes['profile_photo_url'] ?? null);
    }

    /** Store the photo as a relative disk path, never a host-frozen absolute URL. */
    public function setProfilePhotoUrlAttribute($value): void
    {
        $this->attributes['profile_photo_url'] = \App\Support\MediaUrl::toStorage($value);
    }

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
