<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CEO's Forum: platform-hosted live meetings for institute owners.
 *
 * jorsastech (the platform host) schedules a live Jitsi meeting on any topic and
 * time; every institute owner is emailed an invitation and a reminder, and joins
 * in-portal from their owner console. A row is created `scheduled`; the
 * `lms:send-ceo-forum-emails` command sends the invite (once) and a reminder (once,
 * about an hour before start) and rolls the status forward to `live` / `ended`.
 *
 * NOT tenant-scoped: this belongs to the platform, not any one institute, so it
 * deliberately has no real `tenant_id` and does not use the TenantAware trait. The
 * model exposes a constant tenant_id of 0 only so the shared Jitsi room helper can
 * mint a stable room slug (see BaseLmsController::ensureRoom).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ceo_forums')) {
            return;
        }

        Schema::create('ceo_forums', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // Agenda / description of what the forum covers.
            $table->text('topic')->nullable();
            $table->dateTime('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('host_name')->nullable();
            // Reuses the same room-name convention as classes; filled by ensureRoom.
            $table->string('meeting_id')->nullable();
            // scheduled -> live -> ended ; cancelled at any point.
            $table->string('status')->default('scheduled');
            // Set after the event so owners who missed it can re-watch.
            $table->string('recording_url')->nullable();
            $table->string('cover_image')->nullable();
            // Per-forum email guards (idempotent, retry-safe sweeps).
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // Botble admin user id
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ceo_forums');
    }
};
