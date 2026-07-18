<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LmsCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'requirements',
        'price',
        'max_students',
        'registered_count',
        'is_live_available',
        'is_prerecorded_available',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
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
                $course->slug = Str::slug($course->title);
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
}
