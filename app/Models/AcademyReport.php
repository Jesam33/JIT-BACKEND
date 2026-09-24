<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

/**
 * A student's report about their academy.
 *
 * Written from the student portal, read only by the platform (the host Blade
 * queue at /admin/lms/reports). No academy-side surface reads this table: the
 * academy being reported is `tenant_id`, so an owner-scoped query would show an
 * owner exactly what was said about them, which is not the design.
 *
 * `student_id` carries no foreign key. Anonymising or purging the student must
 * not erase the fact that the report was made, and a FK with cascade would do
 * precisely that.
 */
class AcademyReport extends Model
{
    use HasFactory;
    use TenantAware;

    /** What the student picked. Validated in PHP — see the controller. */
    public const CATEGORIES = [
        'payment' => 'I paid but cannot access the course',
        'access' => 'I cannot get into my account or my course',
        'quality' => 'The course is not what was described',
        'conduct' => 'A staff member behaved inappropriately',
        'other' => 'Something else',
    ];

    public const STATUSES = ['open', 'reviewing', 'resolved', 'dismissed'];

    protected $fillable = [
        'tenant_id',
        'student_id',
        'category',
        'details',
        'status',
        'resolution_note',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LmsStudent::class, 'student_id');
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Still awaiting a decision — the host queue's default view. */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'reviewing']);
    }
}
