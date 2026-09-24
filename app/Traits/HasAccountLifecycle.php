<?php

namespace App\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deactivate / delete / reactivate for a PERSON account (a student or a staffer).
 *
 * Three states, derived from two columns so there is exactly one source of truth:
 *
 *   active          is_active = true,  purge_after = null, purged_at = null
 *   deactivated     is_active = false, purge_after = null, purged_at = null
 *   purge_scheduled is_active = false, purge_after = <date>
 *   purged          purged_at = <date>  (terminal)
 *
 * Deactivation is the reversible half: nothing is deleted, the row keeps its
 * enrolments/cohort/records, and reactivating restores access instantly. It is
 * modelled on `lms_teachers.is_active`, which already gated staff login.
 *
 * "Delete" is deliberately NOT a hard delete. It schedules a purge 30 days out
 * (`saas.purge_window_days`) and leaves the row completely intact until then, so
 * the whole window is cancellable. What the purge actually does is the one thing
 * the two account types disagree about, so it stays on the model:
 *
 *   - a STUDENT is anonymised (PII scrubbed, structure kept), because their
 *     enrolments, attendance and certificates are the academy's records and its
 *     revenue history. Deleting the row outright would change the academy's
 *     numbers retroactively with nothing on screen explaining why, and would take
 *     a certificate serial an employer may already have verified out of existence.
 *   - a STAFF member is removed, exactly as the owner's Remove button does today.
 *
 * @see \App\Models\LmsStudent::purge()
 * @see \App\Models\LmsTeacher::purge()
 */
trait HasAccountLifecycle
{
    /**
     * What the purge does to this account type. Implemented per model because a
     * student is anonymised and a staffer is removed.
     *
     * Returns true when the purge actually happened. A false return is not a
     * failure: it means the account is still entangled with something that must
     * not be destroyed (a staffer who now leads a cohort, say), so the row is left
     * scheduled and untouched for a human to sort out.
     */
    abstract public function purge(): bool;

    /** active | deactivated | purge_scheduled | purged */
    public function lifecycleState(): string
    {
        // Checked first: a purged row keeps `is_active = false` forever, and
        // reporting that as merely "deactivated" would tell an academy a deleted
        // student is one click away from coming back.
        if ($this->purged_at) {
            return 'purged';
        }

        if ($this->purge_after) {
            return 'purge_scheduled';
        }

        return $this->is_active ? 'active' : 'deactivated';
    }

    public function isDeactivated(): bool
    {
        return $this->lifecycleState() === 'deactivated';
    }

    public function isPurgeScheduled(): bool
    {
        return $this->lifecycleState() === 'purge_scheduled';
    }

    public function isPurged(): bool
    {
        return $this->lifecycleState() === 'purged';
    }

    /** Whether this account may sign in / use the portal right now. */
    public function canAccessAccount(): bool
    {
        return $this->lifecycleState() === 'active';
    }

    /**
     * Why this account may NOT be purge-scheduled right now, or null when it may.
     *
     * A hook rather than a check at each call site because there are two ways in —
     * an academy deleting a student from its roster, and the person deleting
     * themselves from their profile — and they must agree. A rule enforced on only
     * one of them is not a rule.
     *
     * The base allows everything. Overrides are per model, because what blocks a
     * purge is model-specific (a student with payments; see LmsStudent).
     */
    public function purgeBlockedReason(): ?string
    {
        return null;
    }

    /** Whether schedulePurge() would be refused for this account. */
    public function canBePurged(): bool
    {
        return $this->purgeBlockedReason() === null;
    }

    /**
     * Freeze access without touching a single record. Reversible via reactivate().
     * Clearing purge_after matters: deactivating an account whose deletion was
     * already scheduled means "actually, just pause me", and leaving the purge
     * armed would delete them anyway on the day.
     */
    public function deactivate(): void
    {
        $this->forceFill([
            'is_active' => false,
            'deactivated_at' => $this->deactivated_at ?: now(),
            'purge_after' => null,
            'purge_froze_access' => false,
        ])->save();
    }

    /** Undo either a deactivation or a scheduled deletion. */
    public function reactivate(): void
    {
        $this->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'purge_after' => null,
            // Cleared with the purge it describes, so a stale flag can never make
            // a LATER cancel restore access it did not grant.
            'purge_froze_access' => false,
        ])->save();
    }

    /**
     * Arm the purge window. Access is frozen immediately (the person asked to be
     * deleted, so they should not keep using the portal) while every record stays
     * exactly as it is until the window closes.
     */
    public function schedulePurge(?CarbonInterface $after = null): void
    {
        // Captured BEFORE the freeze below, because it is what decides whether
        // cancelPurge() hands access back or returns the account to the
        // deactivated state it was already in.
        $weAreFreezingIt = (bool) $this->is_active;

        $this->forceFill([
            'is_active' => false,
            'deactivated_at' => $this->deactivated_at ?: now(),
            'purge_after' => $after ?: now()->addDays(self::purgeWindowDays()),
            'purge_froze_access' => $weAreFreezingIt,
        ])->save();
    }

    /**
     * Undo a scheduled deletion, returning the account to exactly the state it
     * was in before the purge was armed.
     *
     * For the ordinary case — an active person asks to be deleted, then changes
     * their mind — that means full access back, which is what "restorable until
     * <date>" promises. For an account an academy had ALREADY deactivated before
     * the purge was scheduled, it means back to deactivated: restoring access
     * nobody asked for would quietly reopen an account the academy switched off.
     */
    public function cancelPurge(): void
    {
        if ($this->purge_froze_access) {
            $this->reactivate();

            return;
        }

        $this->forceFill(['purge_after' => null])->save();
    }

    /**
     * Record that the purge ran. Called by each model's purge() once it has done
     * its own work, so the terminal state is stamped identically for everyone.
     * saveQuietly() because this happens mid-destruction: model events that
     * notify, broadcast or re-stamp must not fire on a row being erased.
     */
    protected function markPurged(): void
    {
        $this->forceFill([
            'is_active' => false,
            'purge_after' => null,
            'purge_froze_access' => false,
            'purged_at' => now(),
        ])->saveQuietly();
    }

    /**
     * How long the undo window lasts. One config knob for people and academies
     * alike, so the promise made in the copy matches what the command does.
     */
    public static function purgeWindowDays(): int
    {
        return max(1, (int) config('saas.purge_window_days', 30));
    }

    /** Accounts that may still use the portal. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('purge_after');
    }

    /**
     * Accounts whose purge window has closed. Used only by the sweep, and never
     * when a tenant is bound, so it is safe for it to be tenant-agnostic.
     */
    public function scopePurgeDue(Builder $query): Builder
    {
        return $query->whereNotNull('purge_after')->where('purge_after', '<=', now());
    }
}
