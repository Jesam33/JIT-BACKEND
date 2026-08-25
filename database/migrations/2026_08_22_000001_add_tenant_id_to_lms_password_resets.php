<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_password_resets', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_password_resets', 'tenant_id')) {
                // Which institute issued this reset/setup token. Lets the reset
                // step bind the correct tenant even on the bare domain, and keeps
                // same-email accounts across institutes from clobbering each
                // other's tokens. Nullable for legacy/primary (JIT) tokens.
                $table->unsignedBigInteger('tenant_id')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_password_resets', function (Blueprint $table): void {
            if (Schema::hasColumn('lms_password_resets', 'tenant_id')) {
                $table->dropColumn('tenant_id');
            }
        });
    }
};
