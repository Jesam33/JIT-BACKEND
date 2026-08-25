<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'lms_courses','lms_modules','lms_module_contents','lms_materials',
            'lms_students','lms_enrollments','lms_tracks','lms_scheduled_classes',
            'lms_certificates','lms_tasks','lms_task_submissions','lms_classrooms',
            'lms_sessions','lms_messages','lms_group_chats','payments','training_registrations',
            'agents','agent_commissions','agent_notifications'
        ];

        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    if (! Schema::hasColumn($tbl, 'tenant_id')) {
                        $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'lms_courses','lms_modules','lms_module_contents','lms_materials',
            'lms_students','lms_enrollments','lms_tracks','lms_scheduled_classes',
            'lms_certificates','lms_tasks','lms_task_submissions','lms_classrooms',
            'lms_sessions','lms_messages','lms_group_chats','payments','training_registrations',
            'agents','agent_commissions','agent_notifications'
        ];

        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'tenant_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
