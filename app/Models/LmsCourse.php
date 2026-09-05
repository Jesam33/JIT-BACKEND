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

    protected $fillable = [
        'title',
        'slug',
        'description',
        'requirements',
        'price',
        'original_price',
        'prerecorded_price',
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

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LmsCourseReview::class, 'course_id');
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
