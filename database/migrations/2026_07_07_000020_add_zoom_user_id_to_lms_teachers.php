<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->string('zoom_user_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->dropColumn('zoom_user_id');
        });
    }
};
