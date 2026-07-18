<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_tasks', function (Blueprint $table) {
            $table->foreignId('module_id')->nullable()->constrained('lms_modules')->nullOnDelete()->after('course_id');
        });
    }

    public function down(): void
    {
        Schema::table('lms_tasks', function (Blueprint $table) {
            $table->dropForeign(['module_id']);
            $table->dropColumn('module_id');
        });
    }
};
