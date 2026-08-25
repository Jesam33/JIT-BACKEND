<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_messages', 'reply_to_id')) {
                $table->unsignedBigInteger('reply_to_id')->nullable()->index()->after('edited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('lms_messages', 'reply_to_id')) {
                $table->dropColumn('reply_to_id');
            }
        });
    }
};
