<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->dropColumn(['meeting_sdk_key', 'meeting_sdk_secret']);
        });
    }

    public function down(): void
    {
        Schema::table('lms_classrooms', function (Blueprint $table): void {
            $table->string('meeting_sdk_key')->nullable()->after('recording_url');
            $table->string('meeting_sdk_secret')->nullable()->after('meeting_sdk_key');
        });
    }
};
