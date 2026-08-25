<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'paystack_customer_code')) {
                $table->string('paystack_customer_code')->nullable()->after('stripe_customer_id');
            }
            if (! Schema::hasColumn('tenants', 'paystack_subscription_id')) {
                $table->string('paystack_subscription_id')->nullable()->after('paystack_customer_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'paystack_subscription_id')) {
                $table->dropColumn('paystack_subscription_id');
            }
            if (Schema::hasColumn('tenants', 'paystack_customer_code')) {
                $table->dropColumn('paystack_customer_code');
            }
        });
    }
};
