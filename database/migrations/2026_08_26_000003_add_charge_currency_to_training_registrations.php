<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the CHARGE currency on each registration.
 *
 * `course_price` holds the amount actually charged; this records the currency
 * that amount is in. Defaults to (and backfills as) 'NGN' — today every charge
 * is NGN, so existing rows are correct as-is. Populated at register() from the
 * visitor's country once USD charging is enabled (saas.usd_charge_enabled);
 * until then it stays 'NGN' for everyone.
 *
 * Guarded + idempotent (Schema::hasColumn) so a re-run on shared hosting is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_registrations')) {
            return;
        }

        if (! Schema::hasColumn('training_registrations', 'charge_currency')) {
            Schema::table('training_registrations', function (Blueprint $table): void {
                $table->string('charge_currency', 3)->default('NGN')->after('course_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('training_registrations') && Schema::hasColumn('training_registrations', 'charge_currency')) {
            Schema::table('training_registrations', function (Blueprint $table): void {
                $table->dropColumn('charge_currency');
            });
        }
    }
};
