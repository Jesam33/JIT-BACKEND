<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'paystack_init_reference')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->string('paystack_init_reference')->nullable()->after('paystack_subscription_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'paystack_init_reference')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->dropColumn('paystack_init_reference');
            });
        }
    }
};
