<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_password_resets', function (Blueprint $table): void {
            $table->string('role', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('lms_password_resets', function (Blueprint $table): void {
            $table->enum('role', ['student', 'staff'])->change();
        });
    }
};
