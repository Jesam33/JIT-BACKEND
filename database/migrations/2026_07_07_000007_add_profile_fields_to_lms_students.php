<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_students', function (Blueprint $table): void {
            $table->string('profile_photo_url')->nullable()->after('learning_mode');
            $table->boolean('notify_class_reminders')->default(true)->after('profile_photo_url');
            $table->boolean('notify_chat')->default(true)->after('notify_class_reminders');
            $table->boolean('notify_announcements')->default(true)->after('notify_chat');
        });
    }

    public function down(): void
    {
        Schema::table('lms_students', function (Blueprint $table): void {
            $table->dropColumn(['profile_photo_url', 'notify_class_reminders', 'notify_chat', 'notify_announcements']);
        });
    }
};
