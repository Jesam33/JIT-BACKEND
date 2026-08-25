<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lms_tasks')->whereNull('module_id')->update(['module_id' => 0]);

        Schema::table('lms_tasks', function (Blueprint $table) {
            // attempt to drop existing foreign key (if any) so we can alter the column
            try {
                $table->dropForeign(['module_id']);
            } catch (\Throwable $e) {
                // ignore if the foreign key does not exist
            }

            $table->unsignedBigInteger('module_id')->nullable(false)->change();
            $table->foreign('module_id')->references('id')->on('lms_modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lms_tasks', function (Blueprint $table) {
            // attempt to drop FK then make nullable and restore previous FK behavior
            try {
                $table->dropForeign(['module_id']);
            } catch (\Throwable $e) {
                // ignore
            }

            $table->unsignedBigInteger('module_id')->nullable()->change();
            $table->foreign('module_id')->references('id')->on('lms_modules')->nullOnDelete();
        });
    }
};
