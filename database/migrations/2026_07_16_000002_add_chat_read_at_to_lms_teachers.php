<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->timestamp('group_chat_read_at')->nullable()->after('password');
            $table->timestamp('dm_chat_read_at')->nullable()->after('group_chat_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->dropColumn(['group_chat_read_at', 'dm_chat_read_at']);
        });
    }
};
