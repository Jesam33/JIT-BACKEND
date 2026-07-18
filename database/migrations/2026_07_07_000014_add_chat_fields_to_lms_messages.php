<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            $table->enum('chat_type', ['group', 'dm'])->nullable()->after('id');
            $table->unsignedBigInteger('chat_id')->nullable()->after('chat_type');
            $table->enum('sender_role', ['student', 'teacher'])->nullable()->after('chat_id');
            $table->unsignedBigInteger('sender_id')->nullable()->after('sender_role');
            $table->text('content')->nullable()->after('sender_id');
            $table->string('attachment_url')->nullable()->after('content');
            $table->dateTime('deleted_at')->nullable()->after('attachment_url');

            $table->index(['chat_type', 'chat_id']);
            $table->index(['sender_role', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            $table->dropIndex(['chat_type', 'chat_id']);
            $table->dropIndex(['sender_role', 'sender_id']);
            $table->dropColumn(['chat_type', 'chat_id', 'sender_role', 'sender_id', 'content', 'attachment_url', 'deleted_at']);
        });
    }
};
