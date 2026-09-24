<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Scopes\TenantScope;
use App\Traits\HasAccountLifecycle;
use App\Traits\TenantAware;

class LmsStudent extends Model
{
    use HasFactory;
    use HasAccountLifecycle;
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
        // Lifecycle: set only through the trait's deactivate/reactivate/
        // schedulePurge methods, never from request input.
        'is_active',
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
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Every student needs a stable @handle for chat mentions. SaaS students
        // are provisioned via updateOrCreate() without one, which broke the
        // @mention dropdown, insertion and notification chain (all keyed on the
        // username). Fill it on create, this runs AFTER TenantAware has stamped
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
     * \w+, so "@ada.bloom" would only ever match "ada", "adabloom" is safe.
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

    /**
     * Whether this student has ever paid for a course — including a comped
     * enrolment.
     *
     * `payments` hangs off `training_registrations`, not off the student, so this
     * is a one-hop lookup through `training_registration_id`. Both payment origins
     * land here: a real Paystack charge, and the `FREE-*` row LmsIntakeController
     * writes with `amount => 0` when an academy comps or directly invites someone.
     *
     * Both count. A zero-amount row still records that the academy granted a
     * place, which is a financial decision the academy made and a thing a dispute
     * can be about, so the rule is "a successful payment row exists", not "money
     * moved".
     *
     * The tenant scope is dropped because the lookup keys on a globally-unique
     * registration id: leaving the scope on would make the answer depend on which
     * tenant happened to be bound at call time, and a wrong "no" here is the
     * failure that matters.
     */
    public function hasSuccessfulPayment(): bool
    {
        if (empty($this->training_registration_id)) {
            return false;
        }

        return Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('registration_id', $this->training_registration_id)
            ->where('status', 'success')
            ->exists();
    }

    /**
     * A student who has paid cannot be purged. Suspension stays available.
     *
     * The purge here is an anonymise, so the academy's ledger survives either way —
     * what it destroys is the link between that money and a person. That is exactly
     * the link a payment dispute, a refund claim or a tax question needs, and it is
     * the academy that would be destroying it, about its own customer.
     *
     * The student is not left without a route: erasure for a paid student goes to
     * the platform as a rights request, where the financial-records interest can be
     * weighed by someone who is not the counterparty. See RightsRequest.
     */
    public function purgeBlockedReason(): ?string
    {
        if (! $this->hasSuccessfulPayment()) {
            return null;
        }

        return 'This student has paid for a course, so their account cannot be deleted. You can suspend them instead, or they can ask Jorsas Tech to erase their data.';
    }

    /**
     * Anonymise this student, keeping every record the academy depends on.
     *
     * Deliberately NOT a row delete. `lms_students` is the parent of eight
     * cascadeOnDelete foreign keys — enrolments, attendance, chat, notifications,
     * graded submissions and CERTIFICATES. Removing the row would take a
     * certificate serial an employer may already have verified out of existence,
     * and would change the academy's enrolment and revenue figures retroactively
     * with nothing on screen to explain why.
     *
     * So the person is erased and the record stays: every identifying column is
     * cleared here, while `id`, `tenant_id`, `selected_course_id` and
     * `learning_mode` survive so the roster, the ledger and the certificates still
     * reconcile. `purged_at` is what marks the row as deleted (the trait's
     * lifecycleState() reads it), so the UI shows "Deleted" rather than
     * "Deactivated" and nobody offers to restore them.
     *
     * The email is replaced rather than nulled: the column is NOT NULL, and the
     * per-tenant unique index covers it. A sentinel under `.invalid` (reserved by
     * RFC 2606, so it can never be a real inbox) is unique per row and frees the
     * person's real address for reuse. The username IS nulled, which is what
     * takes them out of the @mention roster — a purged student still holds their
     * enrolment, so without this they would keep showing up in chat as
     * "Deleted Student" and be mentionable.
     */
    public function purge(): bool
    {
        // Any live bearer session dies with the account. The middleware gate would
        // already refuse it, but leaving a working token for a deleted person is
        // untidy and one refactor away from being a hole.
        \App\Models\LmsSession::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('role', 'student')
            ->where('user_id', $this->id)
            ->delete();

        $this->forceFill([
            'first_name' => 'Deleted',
            'last_name' => 'Student',
            'username' => null,
            'email' => 'deleted+' . $this->id . '@deleted.invalid',
            'phone' => null,
            'date_of_birth' => null,
            'gender' => null,
            'profile_photo_url' => null,
            // A shared row must not keep a usable credential. Nobody signs in as a
            // deleted student, but an empty password hash would be worse.
            'password' => bcrypt(\Illuminate\Support\Str::random(40)),
            'notify_class_reminders' => false,
            'notify_chat' => false,
            'notify_announcements' => false,
        ])->saveQuietly();

        $this->markPurged();

        return true;
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
