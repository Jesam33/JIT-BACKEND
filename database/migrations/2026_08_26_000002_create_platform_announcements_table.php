<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-level (platform-wide) announcements.
 *
 * jorsastech the platform host broadcasts one announcement to every
 * student/staff/agent across ALL institutes. A row is created `queued`; the
 * scheduled `lms:dispatch-announcements` command fans it out into per-recipient
 * notification rows (across every tenant), records how many recipients it hit,
 * and flips it to `dispatched`. Those per-recipient rows are then emailed by the
 * normal notification-email sweep like any other notification.
 *
 * NOT tenant-scoped: this table belongs to the platform, not an institute, so it
 * deliberately has no `tenant_id` and does not use the TenantAware trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_announcements')) {
            return;
        }

        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // JSON list of audiences, any of: 'student', 'staff', 'agent'.
            $table->json('audiences');
            // queued -> dispatched (fanned out) ; failed on error.
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('created_by')->nullable();  // Botble admin user id
            $table->string('created_by_name')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
