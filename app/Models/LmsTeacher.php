<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasAccountLifecycle;
use App\Traits\TenantAware;

class LmsTeacher extends Model
{
    use HasFactory;
    use HasAccountLifecycle;
    use TenantAware;

    protected $fillable = [
        'name',
        'username',
        'email',
        'role',
        'phone',
        'profile_photo_url',
        'password',
        'is_active',
        // The owner's stand-in row for this academy (see the migration). Never set
        // from request input — only ownerMirror() creates one.
        'is_academy_owner',
        // RBAC preset key: owner|admin|instructor|assistant. Assigned by the owner
        // from the Staff Accounts page; the sections it grants live in
        // App\Support\StaffPermissions, never here.
        'staff_role',
        'zoom_user_id',
        'group_chat_read_at',
        'dm_chat_read_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_academy_owner' => 'boolean',
        'group_chat_read_at' => 'datetime',
        'dm_chat_read_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
    ];

    /**
     * Remove this staff member, unless doing so would take a cohort with them.
     *
     * `lms_tracks.instructor_id` is NOT NULL with cascadeOnDelete, so deleting a
     * teacher who still leads a cohort silently destroys that cohort and, through
     * it, its enrolments, DM threads and group chat. The owner's Remove button
     * refuses in that case and tells them to reassign first; this refuses the same
     * way, but returns false instead of erroring, because the caller here is a
     * scheduled sweep with nobody to show a message to.
     *
     * Refusing is the whole point: the row stays scheduled and intact, so a human
     * reassigns the cohort and the next run completes the deletion. Cascade-
     * deleting a cohort because a deletion fell due would be unrecoverable.
     */
    public function purge(): bool
    {
        if (\App\Models\LmsTrack::query()->where('instructor_id', $this->id)->exists()) {
            return false;
        }

        \App\Models\LmsSession::query()
            ->withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->whereIn('role', ['staff', 'teacher'])
            ->where('user_id', $this->id)
            ->delete();

        // Their own DM threads and notifications cascade harmlessly; every other
        // reference (tasks, modules, classrooms, graded-by) is nullOnDelete.
        $this->delete();

        return true;
    }

    /**
     * Whether this row is an academy owner's stand-in teacher. Such an actor is
     * scoped to the WHOLE academy rather than one instructor's courses, and must
     * be hidden from staff lists and instructor pickers — see
     * BaseLmsController::actorCourseIds()/actorTrackIds() and self::staffOnly().
     */
    public function isAcademyOwner(): bool
    {
        return (bool) $this->is_academy_owner;
    }

    /**
     * The RBAC preset this staff member holds.
     *
     * The owner is resolved FIRST and never read from the column: there is exactly
     * one owner account per academy, they are identified by is_academy_owner, and
     * `staff_role` has no value that could express "the account holder". Reading
     * the column for an owner would let a stray value in it downgrade the person
     * who owns the academy.
     *
     * Anything unrecognised falls back to `instructor`, which is the column's own
     * default and a genuinely restricted role — so a bad value narrows access
     * rather than widening it.
     */
    public function staffRole(): string
    {
        if ($this->isAcademyOwner()) {
            return 'owner';
        }

        $role = (string) ($this->staff_role ?: 'instructor');

        return \App\Support\StaffPermissions::isValidRole($role) ? $role : 'instructor';
    }

    /** Whether this staff member may reach a staff-portal section. */
    public function allows(string $section): bool
    {
        return \App\Support\StaffPermissions::allows($this->staffRole(), $section);
    }

    /** The sections this staff member may reach — handed to the client by /staff/me. */
    public function allowedSections(): array
    {
        return \App\Support\StaffPermissions::sectionsFor($this->staffRole());
    }

    /** Query scope for REAL staff: excludes every academy-owner mirror row. */
    public function scopeStaffOnly($query)
    {
        return $query->where('is_academy_owner', false);
    }

    /**
     * The teacher row standing in for $tenant's owner, created on first use.
     *
     * Real row rather than a virtual actor because the whole staff portal keys on
     * a teacher id (cohort instructor, scheduled-class teacher, chat membership),
     * so an owner with no row could not host a class or open a chat. Exactly one
     * per academy: matched on the flag, so a rename or a second owner account
     * reuses it instead of forking scope.
     *
     * The account is deliberately unusable for login: a random password nobody
     * holds (the owner authenticates as an OWNER and the staff endpoints accept
     * that session — see BaseLmsController::staffActor), and no username, so it
     * never appears in a login form's resolution or a @mention roster. We bind the
     * tenant first so the TenantAware creating-guard is satisfied even on a path
     * that hasn't resolved one yet.
     */
    public static function ownerMirror(?Tenant $tenant): ?self
    {
        if (! $tenant) {
            return null;
        }

        app()->instance('currentTenant', $tenant);

        $existing = static::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_academy_owner', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $owner = \Illuminate\Support\Facades\DB::table('tenant_admins')
            ->join('users', 'users.id', '=', 'tenant_admins.user_id')
            ->where('tenant_admins.tenant_id', $tenant->id)
            ->orderByRaw("CASE WHEN tenant_admins.role = 'owner' THEN 0 ELSE 1 END")
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email')
            ->first();

        $name = $owner
            ? (trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')) ?: ($owner->email ?? null))
            : null;

        return static::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name ?: ($tenant->name ?: 'Academy Owner'),
            // Deterministic and unique per academy. The per-tenant unique index
            // covers (tenant_id, email), so this can never collide with a real
            // staffer's address.
            'email' => 'owner+' . $tenant->id . '@academy.internal',
            'username' => null,
            'role' => 'owner',
            'password' => bcrypt(\Illuminate\Support\Str::random(40)),
            'is_active' => true,
            'is_academy_owner' => true,
        ]);
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
}
