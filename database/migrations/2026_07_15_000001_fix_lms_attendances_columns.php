<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('lms_attendances', 'first_joined_at')) {
                $table->dateTime('first_joined_at')->nullable()->after('joined_at');
            }
            if (! Schema::hasColumn('lms_attendances', 'last_left_at')) {
                $table->dateTime('last_left_at')->nullable()->after('first_joined_at');
            }
            if (! Schema::hasColumn('lms_attendances', 'total_seconds')) {
                $table->integer('total_seconds')->default(0)->after('last_left_at');
            }
            if (! Schema::hasColumn('lms_attendances', 'status')) {
                $table->string('status')->nullable()->after('total_seconds');
            }
            if (! Schema::hasColumn('lms_attendances', 'calculated_at')) {
                $table->dateTime('calculated_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_attendances', function (Blueprint $table) {
            $table->dropColumn(['first_joined_at', 'last_left_at', 'total_seconds', 'status', 'calculated_at']);
        });
    }
};
