<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsTrack extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'name',
        'instructor_id',
        'batch_id',
        'course_id',
        'start_date',
        'end_date',
        'registration_deadline',
        'ended_notified_at',
        'certificates_issued_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'registration_deadline' => 'date',
        'ended_notified_at' => 'datetime',
        'certificates_issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A cohort of a monthly course must have an end date: that date is what
        // stops its students being billed (CourseBilling::endReason). Enforced on
        // every save, so the owner portal, the host back office and anything else
        // are all held to it. The course side of the same rule is in LmsCourse.
        static::saving(function (LmsTrack $track): void {
            if ($track->end_date || ! $track->course_id) {
                return;
            }

            $monthly = LmsCourse::query()
                ->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->whereKey($track->course_id)
                ->value('billing_type') === LmsCourse::BILLING_MONTHLY;

            if ($monthly) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'end_date' => 'This cohort belongs to a monthly course, so it needs an end date. Students are billed every month until it ends.',
                ]);
            }
        });
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollments()
    {
        return $this->hasMany(LmsEnrollment::class, 'track_id');
    }

    public function certificates()
    {
        return $this->hasMany(LmsCertificate::class, 'track_id');
    }

    /**
     * The moment registration for this cohort closes, or null when it never
     * closes by date. Cohort-level dates take precedence; the cutoff is
     * registration_deadline when set, else start_date (register until the
     * cohort begins). Legacy cohorts linked to a Batch fall back to its
     * registration window. All comparisons are end-of-day, so the deadline
     * date itself is still open.
     */
    public function registrationClosesAt(): ?Carbon
    {
        if ($this->registration_deadline) {
            return $this->registration_deadline->copy()->endOfDay();
        }
        if ($this->start_date) {
            return $this->start_date->copy()->endOfDay();
        }
        if ($this->batch && $this->batch->registration_ends_at) {
            return Carbon::parse($this->batch->registration_ends_at);
        }
        return null;
    }

    /**
     * Whether a student can still be placed into this cohort at $now (defaults
     * to now). Closed once the registration cutoff has passed OR the cohort's
     * end_date has (a finished cohort takes no new students). A cohort with no
     * dates at all stays open, preserving the pre-date behaviour of
     * owner-created cohorts.
     */
    public function registrationOpen(?Carbon $now = null): bool
    {
        $now = $now ?? now();

        if ($this->end_date && $now->gt($this->end_date->copy()->endOfDay())) {
            return false;
        }

        $closesAt = $this->registrationClosesAt();
        if ($closesAt && $now->gt($closesAt)) {
            return false;
        }

        // Legacy batch path: a window that hasn't opened yet also blocks.
        if ($this->batch && $this->batch->registration_starts_at && $now->lt($this->batch->registration_starts_at)) {
            return false;
        }

        return true;
    }
}
