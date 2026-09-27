<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;

/**
 * A piece of feedback from someone using an academy's portal.
 *
 * Written through App\Http\Controllers\Lms\FeedbackController, which resolves the
 * author from their own session — the tenant, the role and the identity are never
 * taken from the request body, so nobody can file feedback as someone else or
 * against another academy.
 *
 * Read back ONLY by the host back office, which drops the TenantScope explicitly
 * (see AdminController::feedbackPage). No academy-side screen queries this table.
 *
 * `tenant_id` is deliberately absent from $fillable, per the TenantAware
 * contract: the controller resolves the tenant from the session and passes it
 * through createForTenant(), which is the only safe way to file a row under a
 * named academy. Listing it here would let a request body decide the academy.
 */
class Feedback extends Model
{
    use TenantAware;

    protected $table = 'feedback';

    /** What the sender is telling us. Keys are stored; values are the labels. */
    public const CATEGORIES = [
        'idea' => 'An idea for something new',
        'problem' => 'Something is not working',
        'confusing' => 'Something is confusing',
        'other' => 'Something else',
    ];

    /** new | planned | done | dismissed */
    public const STATUSES = ['new', 'planned', 'done', 'dismissed'];

    protected $fillable = [
        'author_role',
        'author_id',
        'author_name',
        'author_email',
        'page',
        'category',
        'message',
        'status',
        'resolution_note',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'author_id' => 'integer',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    /** The roles allowed to send feedback, and the label each is shown as. */
    public const ROLES = [
        'student' => 'Student',
        'staff' => 'Staff',
        'owner' => 'Academy owner',
        'agent' => 'Admission Marketer',
    ];

    /** Rows the host still has to look at. */
    public function scopeOpen($query)
    {
        return $query->where('status', 'new');
    }
}
