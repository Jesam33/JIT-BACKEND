<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('lms_message_reactions')) {
            return;
        }

        Schema::create('lms_message_reactions', function (Blueprint $table): void {
            $table->id();
            // tenant_id nullable + indexed, matching every other TenantAware table
            // (backfilled by tenants:establish-primary). Reactions are always
            // created in an authenticated, tenant-bound context.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('message_id')->constrained('lms_messages')->cascadeOnDelete();
            $table->enum('user_role', ['student', 'teacher']);
            $table->unsignedBigInteger('user_id');
            $table->string('emoji', 16);
            $table->timestamps();

            // One row per (message, reactor, emoji): a second tap toggles it off.
            $table->unique(['message_id', 'user_role', 'user_id', 'emoji'], 'msg_reaction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_message_reactions');
    }
};
