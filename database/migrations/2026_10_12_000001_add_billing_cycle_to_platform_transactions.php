<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yearly plan billing: each platform payment records whether it bought a month
 * or a year, so confirmation activates the right period and the host's revenue
 * ledger can tell them apart. Existing rows were all monthly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_transactions', 'billing_cycle')) {
            Schema::table('platform_transactions', function (Blueprint $table) {
                $table->string('billing_cycle', 16)->default('monthly')->after('plan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_transactions', 'billing_cycle')) {
            Schema::table('platform_transactions', function (Blueprint $table) {
                $table->dropColumn('billing_cycle');
            });
        }
    }
};
