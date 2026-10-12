<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name printed on a certificate is fixed when it's issued, so a student
 * changing their profile name later never rewrites a certificate they already
 * hold. Existing certificates take their student's current name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lms_certificates', 'recipient_name')) {
            Schema::table('lms_certificates', function (Blueprint $table) {
                $table->string('recipient_name', 191)->nullable()->after('student_id');
            });
        }

        // Backfill from the students table (both are tenant-scoped rows; a raw
        // join avoids the TenantScope, which fails closed in console context).
        DB::table('lms_certificates')
            ->whereNull('recipient_name')
            ->join('lms_students', 'lms_students.id', '=', 'lms_certificates.student_id')
            ->update(['lms_certificates.recipient_name' => DB::raw("TRIM(CONCAT(COALESCE(lms_students.first_name, ''), ' ', COALESCE(lms_students.last_name, '')))")]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('lms_certificates', 'recipient_name')) {
            Schema::table('lms_certificates', function (Blueprint $table) {
                $table->dropColumn('recipient_name');
            });
        }
    }
};
