<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('email');
            $table->string('profile_photo_url')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'profile_photo_url']);
        });
    }
};
