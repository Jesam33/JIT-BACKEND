<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

/**
 * A student's review of a course (1 to 5 stars, optional comment). One row per
 * (course, student), the unique index + updateOrCreate keep re-ratings from
 * inflating the count.
 *
 * Verified-learner reviews: the row carries the proof its author learned
 * (attended_classes of classes_held, activities = tasks + quizzes submitted,
 * finished = certificate issued), stamped by App\Support\LearnerProof when it
 * is written. Only `verified` rows feed the storefront ★ average + count and
 * the public review list. `reply` is the academy's one public answer.
 */
class LmsCourseReview extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'tenant_id',
        'course_id',
        'student_id',
        'rating',
        'comment',
        'verified',
        'attended_classes',
        'classes_held',
        'activities',
        'finished',
        'reply',
        'replied_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'verified' => 'boolean',
        'attended_classes' => 'integer',
        'classes_held' => 'integer',
        'activities' => 'integer',
        'finished' => 'boolean',
        'replied_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', true);
    }

    /** The proof line shown with the review, e.g. "Attended 18 of 20 classes · Finished the course". */
    public function proofText(): string
    {
        $parts = [];
        if ($this->classes_held > 0 && $this->attended_classes > 0) {
            $parts[] = "Attended {$this->attended_classes} of {$this->classes_held} classes";
        }
        if ($this->activities > 0) {
            $parts[] = $this->activities . ' ' . ($this->activities === 1 ? 'task or quiz' : 'tasks and quizzes') . ' completed';
        }
        if ($this->finished) {
            $parts[] = 'Finished the course';
        }

        return implode(' · ', $parts);
    }
}
