<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

/**
 * Wipe the database down to a clean primary-only slate.
 *
 * End state:
 *   - Every NON-primary tenant is removed entirely: all its rows in every
 *     tenant-scoped table (including its owner login), then its `tenants` row.
 *   - The PRIMARY tenant (Jorsas) is kept as an empty shell: all operational
 *     data is wiped (courses, staff, agents, students, enrolments, payments,
 *     chats, materials, certificates, invitations, audits, …) but its owner
 *     login (tenant_admins + tenant_users) and its `tenants` row, which carries
 *     branding/settings in the `settings` JSON, are preserved untouched.
 *
 * Safe by default: prints a plan and changes nothing unless --force is given.
 * Tenant-scoped tables are DISCOVERED at runtime (any table with a `tenant_id`
 * column), so this adapts to whatever schema the target database actually has.
 */
class ResetToPrimaryTenant extends Command
{
    protected $signature = 'tenants:reset-to-primary {--force : Actually perform the deletions. Without this flag the command is a dry run that only prints the plan.}';

    protected $description = 'Delete all non-primary tenants and wipe the primary tenant\'s operational data, keeping only the primary tenant row + its owner login. Dry run unless --force.';

    /**
     * Tables that hold the PRIMARY owner's login and must survive the wipe.
     * (For non-primary tenants these are still deleted, that tenant is removed
     * entirely.) The `tenants` row itself is handled separately and never
     * deleted for the primary, so branding/settings in its `settings` column
     * are preserved.
     */
    private array $keepForPrimary = ['tenant_admins', 'tenant_users'];

    public function handle(): int
    {
        $db = DB::getDatabaseName();
        $slug = config('saas.primary_slug', 'jorsas');

        // 1. Resolve the primary tenant. Abort loudly if we can't, proceeding
        //    without a confirmed primary could wipe everything.
        $primary = Tenant::where('slug', $slug)->first();
        if (! $primary) {
            $this->error("Primary tenant (slug='{$slug}') not found in database '{$db}'. Aborting, nothing deleted.");
            return self::FAILURE;
        }
        $primaryId = (int) $primary->id;
        if ($primaryId <= 0) {
            $this->error("Resolved a non-positive primary id ({$primaryId}). Aborting, nothing deleted.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<options=bold>Database:</> {$db}");
        $this->line("<options=bold>Primary (kept):</> #{$primaryId}  {$primary->slug}  \"{$primary->name}\"");
        $this->newLine();

        // 2. Discover every tenant-scoped table in THIS database.
        $tables = collect(DB::select(
            "SELECT table_name AS t FROM information_schema.columns
             WHERE table_schema = ? AND column_name = 'tenant_id'
             ORDER BY table_name",
            [$db]
        ))->pluck('t')->all();

        if (empty($tables)) {
            $this->error("No tenant-scoped tables (with a tenant_id column) found in '{$db}'. Aborting.");
            return self::FAILURE;
        }

        // 3. Non-primary tenants (deleted entirely).
        $others = DB::table('tenants')->where('id', '!=', $primaryId)->get(['id', 'slug', 'name', 'plan']);
        $otherIds = $others->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($others->isEmpty()) {
            $this->line('Non-primary tenants to delete: <fg=yellow>none</>');
        } else {
            $this->line('<options=bold>Non-primary tenants to delete entirely:</>');
            $this->table(
                ['id', 'slug', 'name', 'plan'],
                $others->map(fn ($t) => [$t->id, $t->slug, $t->name, $t->plan])->all()
            );
        }
        $this->newLine();

        // 4. Build the deletion plan (counts only, nothing deleted here).
        $plan = [];
        $totalNonPrimary = 0;
        $totalPrimaryWipe = 0;
        foreach ($tables as $t) {
            $kept = in_array($t, $this->keepForPrimary, true);

            $nonPrimary = empty($otherIds)
                ? 0
                : (int) DB::table($t)->whereIn('tenant_id', $otherIds)->count();

            $primaryRows = (int) DB::table($t)->where('tenant_id', $primaryId)->count();
            $primaryDelete = $kept ? 0 : $primaryRows;

            $totalNonPrimary += $nonPrimary;
            $totalPrimaryWipe += $primaryDelete;

            $plan[] = [
                'table'         => $t,
                'other_del'     => $nonPrimary,
                'primary_del'   => $kept ? "KEEP ({$primaryRows})" : (string) $primaryDelete,
            ];
        }

        $this->line('<options=bold>Per-table plan</> (other_del = rows removed from non-primary tenants; primary_del = rows removed from Jorsas):');
        $this->table(['table', 'other_del', 'primary_del'], $plan);

        $this->newLine();
        $this->line("Rows to delete from non-primary tenants: <fg=yellow>{$totalNonPrimary}</>");
        $this->line("Rows to delete from the primary (Jorsas) wipe: <fg=yellow>{$totalPrimaryWipe}</>");
        $this->line('Non-primary tenant rows to delete: <fg=yellow>' . count($otherIds) . '</>');
        $this->line('Primary login preserved: <fg=green>' . implode(', ', $this->keepForPrimary) . '</> + the tenants row (branding/settings).');
        $this->newLine();

        // 5. Dry run stops here.
        if (! $this->option('force')) {
            $this->warn('DRY RUN, nothing was deleted. Re-run with --force to execute:');
            $this->line('    php artisan tenants:reset-to-primary --force');
            return self::SUCCESS;
        }

        // 6. Execute. FK checks off so inter-table constraints don't block the
        //    deletes; DELETE (not TRUNCATE) keeps it all inside one transaction
        //    that rolls back cleanly on any error. FK checks are always restored.
        $this->warn('Executing deletions…');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($tables, $otherIds, $primaryId) {
                // 6a. Non-primary tenants: strip every scoped table, then the rows.
                if (! empty($otherIds)) {
                    foreach ($tables as $t) {
                        $n = DB::table($t)->whereIn('tenant_id', $otherIds)->delete();
                        if ($n > 0) {
                            $this->line("  non-primary  {$t}: {$n}");
                        }
                    }
                    $deletedTenants = DB::table('tenants')->whereIn('id', $otherIds)->delete();
                    $this->line("  tenants removed: {$deletedTenants}");
                }

                // 6b. Primary wipe: every scoped table except the login tables.
                foreach ($tables as $t) {
                    if (in_array($t, $this->keepForPrimary, true)) {
                        continue;
                    }
                    $n = DB::table($t)->where('tenant_id', $primaryId)->delete();
                    if ($n > 0) {
                        $this->line("  primary wipe {$t}: {$n}");
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error('Failed, transaction rolled back, nothing deleted: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // 7. After snapshot, prove the shell survived and the data is gone.
        $this->newLine();
        $this->info('Done. Post-reset state:');
        $remainingTenants = DB::table('tenants')->get(['id', 'slug', 'name', 'plan']);
        $this->table(
            ['id', 'slug', 'name', 'plan'],
            $remainingTenants->map(fn ($t) => [$t->id, $t->slug, $t->name, $t->plan])->all()
        );

        $probe = ['lms_courses', 'lms_teachers', 'agents', 'lms_students', 'payments', 'training_registrations'];
        $rows = [];
        foreach ($probe as $t) {
            if (in_array($t, $tables, true)) {
                $rows[] = [$t, (int) DB::table($t)->where('tenant_id', $primaryId)->count()];
            }
        }
        foreach ($this->keepForPrimary as $t) {
            $rows[] = [$t . ' (kept)', (int) DB::table($t)->where('tenant_id', $primaryId)->count()];
        }
        $this->line('Primary (Jorsas) row counts after reset:');
        $this->table(['table', 'rows'], $rows);

        return self::SUCCESS;
    }
}
