<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->text('meeting_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->text('meeting_url')->nullable(false)->change();
        });
    }
};
