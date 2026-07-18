<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->string('meeting_id')->nullable()->after('meeting_url');
            $table->string('session_thumbnail_url')->nullable()->after('meeting_id');
            $table->string('recording_url')->nullable()->after('session_thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->dropColumn(['meeting_id', 'session_thumbnail_url', 'recording_url']);
        });
    }
};
