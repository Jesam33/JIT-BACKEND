<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            $table->index(['chat_type', 'chat_id', 'deleted_at', 'id'], 'lms_messages_chat_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            $table->dropIndex('lms_messages_chat_lookup_idx');
        });
    }
};
