<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\TenantAware;
use App\Scopes\TenantScope;

class LmsCourse extends Model
{
    use HasFactory;
    use TenantAware;

    /**
     * Ceiling on the requirements text, enforced by every path that writes a
     * course (owner portal create/update, host back office create).
     *
     * Requirements is one prerequisite per line, so it grows faster than a normal
     * field; the ceiling keeps it a list rather than an essay on the public course
     * page. The owner form carries the same number (MAX_REQUIREMENTS in
     * app/lms/admin/courses/page.tsx) so the field can never let an owner write
     * something this rule rejects at save time, after they have written it.
     */
    public const MAX_REQUIREMENTS = 2000;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'requirements',
        'price',
        'original_price',
        'prerecorded_price',
        'billing_type',
        'cover_image_path',
        'max_students',
        'registered_count',
        'is_live_available',
        'is_prerecorded_available',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'prerecorded_price' => 'decimal:2',
        'max_students' => 'integer',
        'registered_count' => 'integer',
        'is_live_available' => 'boolean',
        'is_prerecorded_available' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (LmsCourse $course): void {
            if (! $course->slug) {
                // Slugs are unique PER INSTITUTE (the (tenant_id, slug) index), so
                // two institutes can each own a clean "aperture-class", and a
                // repeated title within one institute becomes "aperture-class-2",
                // "-3", … instead of crashing on the unique key. The TenantAware
                // creating hook runs first (traits boot before booted()), so
                // $course->tenant_id is already populated here. Mirrors the backfill
                // in the 2026_07_10 add-fields migration.
                $base = Str::slug((string) $course->title) ?: 'course';
                $slug = $base;
                $n = 2;
                while (
                    static::withoutGlobalScope(TenantScope::class)
                        ->where('tenant_id', $course->tenant_id)
                        ->where('slug', $slug)
                        ->exists()
                ) {
                    $slug = $base . '-' . $n++;
                }
                $course->slug = $slug;
            }
        });

        // Seat ceiling, on EVERY save by every path (owner form, setup wizard, host
        // back office, seeding): never more than the platform ceiling of 50 per
        // course, whatever the plan (so Pro and above top out at 50), and never
        // 0/"unlimited", the column's default: a blank capacity becomes the most
        // this academy's plan allows (Free 1, Basic 30, Pro and above 50). The
        // owner form additionally applies the plan tier to explicit numbers
        // (PlanGate::clampCourseSeats); the host back office may exceed a tier
        // but not the ceiling. Only runs for new rows or a changed capacity, so
        // unrelated edits never rewrite an old row.
        static::saving(function (LmsCourse $course): void {
            if ($course->exists && ! $course->isDirty('max_students')) {
                return;
            }

            // `saving` fires BEFORE TenantAware's `creating` stamps tenant_id on a
            // new row, so fall back to the bound academy, which is the one that
            // stamp is about to use.
            $tenant = $course->tenant_id
                ? Tenant::find($course->tenant_id)
                : (app()->bound('currentTenant') ? app('currentTenant') : null);

            $requested = (int) $course->max_students;
            $course->max_students = $requested <= 0
                ? \App\Support\PlanGate::courseSeatCap($tenant)
                : min($requested, \App\Support\PlanGate::maxStudentsPerCourse());
        });
    }

    /** Billed once for the whole course (every course before monthly billing). */
    public const BILLING_ONE_TIME = 'one_time';

    /** The price is per month; the student pays again each month to keep access. */
    public const BILLING_MONTHLY = 'monthly';

    public function isMonthly(): bool
    {
        return $this->billing_type === self::BILLING_MONTHLY;
    }

    public function slotsRemaining(): int
    {
        if ($this->max_students <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->max_students - $this->registered_count);
    }

    public function isFull(): bool
    {
        return $this->max_students > 0 && $this->registered_count >= $this->max_students;
    }

    public function students(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsStudent::class, 'selected_course_id');
    }

    public function tracks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsTrack::class, 'course_id');
    }

    /**
     * The cohort new registrations are placed into (the first one whose
     * registration window is open, lowest id first, matching
     * BaseLmsController::findActiveTrackForCourse). Null when the course has
     * no open cohort.
     */
    public function openCohort(): ?LmsTrack
    {
        return $this->tracks()
            ->orderBy('id')
            ->get()
            ->first(fn (LmsTrack $t) => $t->registrationOpen());
    }

    public function hasCohorts(): bool
    {
        return $this->tracks()->exists();
    }

    /**
     * Whether students can still register for this course. A course with no
     * cohorts stays open (the pre-cohort behaviour: students register and are
     * placed once a cohort exists). A course WITH cohorts is open only while
     * at least one cohort's registration window is open.
     */
    public function registrationOpen(): bool
    {
        if (! $this->hasCohorts()) {
            return true;
        }
        return $this->openCohort() !== null;
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsCourseReview::class, 'course_id');
    }

    public function materials(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsMaterial::class, 'course_id');
    }

    public function modules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsModule::class, 'course_id');
    }

    /**
     * Whether this course has any pre-recorded VIDEO lesson to actually deliver.
     *
     * The "Pre-recorded available" toggle is per-course and independent of the
     * content, so a course could be switched on before a single video was
     * uploaded: a visitor would pick the cheaper pre-recorded mode, pay, and land
     * on an empty materials page. Every path that offers or accepts the mode
     * must therefore ask this, not the toggle alone.
     *
     * Pre-recorded lessons live in TWO stores, and both gate their video type on
     * the same `pre_recorded_video` plan feature, so either one counts:
     *   - course materials   (`lms_materials`, what the student dashboard's
     *     next-lesson lookup and the materials page read)
     *   - module lessons     (`lms_module_contents`)
     *
     * `status` is deliberately NOT checked: it is stamped "processing" when a
     * Bunny upload starts and never updated afterwards (the status endpoint only
     * proxies Bunny), so filtering on it would hide every real video.
     */
    public function hasPrerecordedContent(): bool
    {
        // List endpoints batch these two counts once (scopeWithPrerecordedCounts)
        // instead of letting this run up to two queries per course.
        if (array_key_exists('video_material_count', $this->attributes)
            || array_key_exists('video_module_count', $this->attributes)) {
            return ((int) ($this->attributes['video_material_count'] ?? 0)) > 0
                || ((int) ($this->attributes['video_module_count'] ?? 0)) > 0;
        }

        return $this->materials()->where('type', 'video')->exists()
            || $this->modules()
                ->whereHas('contents', fn ($q) => $q->where('type', 'video'))
                ->exists();
    }

    /**
     * Eager-load the two counts hasPrerecordedContent() reads, as one subquery
     * each for the whole result set. Every LIST endpoint that serializes courses
     * must apply this, or the check degrades to two queries per course.
     */
    public function scopeWithPrerecordedCounts($query)
    {
        return $query->withCount([
            'materials as video_material_count' => fn ($q) => $q->where('type', 'video'),
            'modules as video_module_count' => fn ($q) => $q->whereHas('contents', fn ($c) => $c->where('type', 'video')),
        ]);
    }

    /**
     * Public URL for the owner-uploaded cover, or null when unset (the frontend
     * then renders a branded initial placeholder). Same idiom as tenant logos.
     */
    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image_path ? asset('storage/' . $this->cover_image_path) : null;
    }
}
