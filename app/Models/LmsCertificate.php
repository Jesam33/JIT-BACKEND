<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsCertificate extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'student_id',
        'course_id',
        'track_id',
        'title',
        'serial',
        'file_url',
        'start_date',
        'end_date',
        'issued_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'issued_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function track()
    {
        return $this->belongsTo(LmsTrack::class, 'track_id');
    }

    /**
     * The printed certificate number, e.g. "2026-000123". Derived from the row
     * id (globally unique, so the serial is too) with the issue year in front
     * for a natural reading. Old rows created before serials existed get one
     * lazily the first time it's read — cheaper than a backfill migration for
     * a table this size, and the printed number never changes once issued.
     */
    public function serialNumber(): string
    {
        if ($this->serial && $this->serial !== '') {
            return $this->serial;
        }

        $serial = sprintf('%s-%06d', $this->issued_at?->format('Y') ?? now()->format('Y'), $this->id);
        if ($this->exists) {
            $this->forceFill(['serial' => $serial])->save();
        }

        return $serial;
    }
}
