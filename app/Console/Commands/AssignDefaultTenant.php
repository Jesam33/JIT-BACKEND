<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class AssignDefaultTenant extends Command
{
    protected $signature = 'tenants:assign-default';

    protected $description = 'Create a default tenant and assign existing rows with null tenant_id to it';

    public function handle()
    {
        $this->info('Starting assignment of existing rows to default tenant...');

        DB::beginTransaction();
        try {
            $tenant = Tenant::firstOrCreate(
                ['slug' => 'default'],
                ['name' => 'Default Tenant', 'status' => 'active']
            );

            $tables = [
                'lms_courses','lms_modules','lms_module_contents','lms_materials',
                'lms_students','lms_enrollments','lms_tracks','lms_scheduled_classes',
                'lms_certificates','lms_tasks','lms_task_submissions','lms_classrooms',
                'lms_sessions','lms_messages','lms_group_chats','payments','training_registrations',
                'agents','agent_commissions','agent_notifications'
            ];

            foreach ($tables as $tbl) {
                if (DB::getSchemaBuilder()->hasTable($tbl) && DB::getSchemaBuilder()->hasColumn($tbl, 'tenant_id')) {
                    $count = DB::table($tbl)->whereNull('tenant_id')->update(['tenant_id' => $tenant->id]);
                    $this->info("Updated {$count} rows in {$tbl}");
                }
            }

            DB::commit();
            $this->info('Assignment complete.');
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error during assignment: ' . $e->getMessage());
            return 1;
        }
    }
}
