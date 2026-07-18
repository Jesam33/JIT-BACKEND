<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_students', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('last_name');
            $table->string('phone')->nullable()->after('date_of_birth');
            $table->string('gender')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('lms_students', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'phone', 'gender']);
        });
    }
};
