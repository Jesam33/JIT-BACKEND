<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsEnrollment extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'track_id',
    ];

    protected static function booted(): void
    {
        // Placing a one-on-one student into a cohort gives them that cohort's
        // instructor as their teacher if they don't have one yet.
        static::saved(function (LmsEnrollment $enrollment): void {
            try {
                $student = LmsStudent::query()->withoutGlobalScope(\App\Scopes\TenantScope::class)->find($enrollment->student_id);
                if ($student && $student->isOneOnOne() && ! $student->one_on_one_teacher_id) {
                    \App\Services\OneOnOne::ensureTeacher($student);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function track(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsTrack::class, 'track_id');
    }
}
