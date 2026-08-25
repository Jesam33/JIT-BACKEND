<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class EstablishPrimaryTenant extends Command
{
    protected $signature = 'tenants:establish-primary {--force : Backfill the newly-added tenant_id columns even when more than one tenant already exists}';

    protected $description = 'Establish the primary organisation (Jorsas Institute of Technology) as Organisation 1 and backfill tenant_id on all tenant-scoped tables. Idempotent.';

    /**
     * Tables that already carried tenant_id before Phase 1. Any NULL here is a
     * pre-multitenancy row and always belongs to the primary org (new tenants'
     * rows are auto-stamped), so these are safe to backfill unconditionally.
     */
    private array $legacyTables = [
        'lms_courses','lms_modules','lms_module_contents','lms_materials',
        'lms_students','lms_enrollments','lms_tracks','lms_scheduled_classes',
        'lms_certificates','lms_tasks','lms_task_submissions','lms_classrooms',
        'lms_sessions','lms_messages','lms_group_chats','payments','training_registrations',
        'agents','agent_commissions','agent_notifications',
    ];

    /**
     * Tables that only gained tenant_id in Phase 1. Every row is NULL right after
     * the column is added, so if more than one tenant already exists these NULLs
     * cannot be safely attributed to the primary org (they may belong to another
     * tenant created via the onboarding workaround). Guarded behind --force.
     */
    private array $newTables = [
        'lms_teachers','lms_teacher_notifications','lms_notifications',
        'lms_dm_threads','lms_attendances','lms_chat_read_states','agent_sessions',
    ];

    public function handle(): int
    {
        $slug = config('saas.primary_slug', 'jorsas');
        $name = config('saas.primary_name', 'Jorsas Institute of Technology');

        DB::beginTransaction();
        try {
            // 1. Resolve the primary record: by target slug, else the legacy 'default', else the lowest id.
            $tenant = Tenant::where('slug', $slug)->first()
                ?? Tenant::where('slug', 'default')->first()
                ?? Tenant::orderBy('id')->first();

            if (! $tenant) {
                $tenant = Tenant::create(['slug' => $slug, 'name' => $name, 'status' => 'active']);
                $this->info("Created primary tenant #{$tenant->id} ({$slug}).");
            } else {
                // 2. Rename in place — the same primary key already owns all legacy
                //    rows by tenant_id, so no FK re-pointing is needed.
                $tenant->update(['slug' => $slug, 'name' => $name, 'status' => 'active']);
                $this->info("Primary tenant is #{$tenant->id} ({$slug}).");
            }

            // 4. Multi-tenant guard for the newly-columned tables.
            $multiTenant = Tenant::count() > 1;
            $backfillNew = ! $multiTenant || $this->option('force');

            if ($multiTenant && ! $this->option('force')) {
                $this->warn('More than one tenant exists — skipping backfill of newly-added columns ('
                    . implode(', ', $this->newTables)
                    . '). Re-run with --force only if every NULL row in those tables belongs to the primary org.');
            }

            // 3. Backfill (idempotent: whereNull only, so a second run updates 0 rows).
            $tables = $backfillNew ? array_merge($this->legacyTables, $this->newTables) : $this->legacyTables;

            foreach ($tables as $tbl) {
                if (DB::getSchemaBuilder()->hasTable($tbl) && DB::getSchemaBuilder()->hasColumn($tbl, 'tenant_id')) {
                    $count = DB::table($tbl)->whereNull('tenant_id')->update(['tenant_id' => $tenant->id]);
                    $this->info("Backfilled {$count} rows in {$tbl}");
                }
            }

            DB::commit();
            $this->info('Primary tenant established.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
