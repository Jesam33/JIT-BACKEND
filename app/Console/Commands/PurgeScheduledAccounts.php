<?php

namespace App\Console\Commands;

use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the deletion window: accounts and academies whose 30 days are up are
 * purged, everything still scheduled is left alone.
 *
 * This exists as a scheduled sweep rather than work done inside the "delete"
 * request for one reason: the whole promise of the feature is that a deletion is
 * cancellable for 30 days. Anything that destroys data has to happen on a timer
 * with nobody waiting on it, so a failed purge can be seen and retried, and so
 * the request that armed it stays a single fast write.
 *
 * Console context is unscoped by TenantScope, so this crosses every tenant on
 * purpose. It is also why the scopes are spelled out explicitly below.
 *
 * What each kind of purge actually does is owned by the models, not by this
 * command — see HasAccountLifecycle::purge(), LmsStudent::purge(),
 * LmsTeacher::purge() and Tenant. The command's only jobs are to find what is
 * due, call it, and report honestly.
 *
 * Re-runnable: each `purge()` stamps its own terminal state and the query filters
 * on `purge_after <= now()`, so a second run finds nothing. `purge_after` is also
 * re-read at run time rather than trusted from an earlier query, so a deletion
 * cancelled a second ago is never purged by a run already in flight.
 */
class PurgeScheduledAccounts extends Command
{
    protected $signature = 'lms:purge-scheduled-accounts {--dry-run : List what would be purged without changing anything}';

    protected $description = 'Purge students, staff and academies whose 30-day deletion window has closed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $students = $this->studentsDue();
        $teachers = $this->teachersDue();
        $tenants = $this->tenantsDue();

        if ($students->isEmpty() && $teachers->isEmpty() && $tenants->isEmpty()) {
            $this->info('Nothing is due for purge.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['kind', 'id', 'label', 'purge_after'],
                collect()
                    ->merge($students->map(fn ($s) => ['student', $s->id, trim($s->first_name . ' ' . $s->last_name), (string) $s->purge_after]))
                    ->merge($teachers->map(fn ($t) => ['staff', $t->id, (string) $t->name, (string) $t->purge_after]))
                    ->merge($tenants->map(fn ($t) => ['academy', $t->id, (string) $t->name, (string) $t->purge_after]))
                    ->all()
            );

            return self::SUCCESS;
        }

        $purged = 0;
        $deferred = [];

        foreach ($students as $student) {
            // Re-read: the row may have been restored since the query above.
            if (! $this->stillDue($student)) {
                continue;
            }

            $student->purge() ? $purged++ : $deferred[] = "student #{$student->id}";
        }

        foreach ($teachers as $teacher) {
            if (! $this->stillDue($teacher)) {
                continue;
            }

            // A false return means the staffer still leads a cohort, and deleting
            // them would cascade that cohort and its students away with them. The
            // row stays scheduled for a human to reassign and the next run retries.
            $teacher->purge() ? $purged++ : $deferred[] = "staff #{$teacher->id}";
        }

        foreach ($tenants as $tenant) {
            if (! $this->stillDue($tenant)) {
                continue;
            }

            $this->purgeAcademy($tenant);
            $purged++;
        }

        $this->info("Purged {$purged} account(s).");

        if ($deferred) {
            // Logged rather than only printed, because this is the case nobody is
            // watching for: a deletion that fell due and could not complete.
            $list = implode(', ', $deferred);
            $this->warn("Left scheduled (still entangled, needs a human): {$list}");
            Log::warning('Account purge deferred: ' . $list);
        }

        return self::SUCCESS;
    }

    /**
     * Permanently close an academy.
     *
     * Deliberately NOT a row delete. Nothing in the schema cascades from
     * `tenants` except its owner links, so deleting the row would not remove the
     * academy's students, courses, enrolments or payment rows — it would strand
     * them at a dangling tenant_id, permanently unreachable behind TenantScope
     * and silently dropped from the platform's own revenue reporting. Closing the
     * academy instead leaves one intact, auditable archive: nobody can reach it,
     * nothing is orphaned, and the financial records a platform is expected to
     * keep are still there.
     */
    private function purgeAcademy(Tenant $tenant): void
    {
        $tenant->forceFill([
            'purged_at' => now(),
            'purge_after' => null,
            'purge_froze_access' => false,
            // Backstop: whatever state the academy was in, it is closed now. The
            // gate keys on `purged_at`, but stamping the pause too means any
            // surface that reads only `deactivated_at` also sees it as off.
            'deactivated_at' => $tenant->deactivated_at ?: now(),
        ])->save();

        Log::info("Academy purged: #{$tenant->id} ({$tenant->slug})");
    }

    /** A scheduled deletion is only due while its window is still closed. */
    private function stillDue($account): bool
    {
        return $account->purge_after !== null && $account->purge_after->isPast();
    }

    /**
     * Students past their window.
     *
     * `is_active` is deliberately not part of the filter: an account that was
     * deactivated before its purge was scheduled is just as due. `purged_at` is,
     * so an already-purged row is never picked up again.
     */
    private function studentsDue()
    {
        return LmsStudent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('id')
            ->get();
    }

    private function teachersDue()
    {
        return LmsTeacher::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            // The owner's stand-in row is an actor, not a person, and is never
            // scheduled for deletion — but excluding it here means a stray value
            // can never delete an academy's owner mirror out from under it.
            ->where('is_academy_owner', false)
            ->orderBy('id')
            ->get();
    }

    private function tenantsDue()
    {
        return Tenant::query()
            ->whereNull('purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('id')
            ->get();
    }
}
