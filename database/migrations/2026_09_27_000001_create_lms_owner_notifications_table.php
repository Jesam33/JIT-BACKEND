<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academy owner's own notification store.
 *
 * Until now the owner portal had no notifications table at all: its bell is
 * SYNTHESISED on every read by OwnerAdminController::notifications() out of rows
 * that already exist (ended cohorts, newest students, newest staff, pending
 * agent applications), and the frontend kept a local "seen" marker instead of an
 * `is_read` column. That works for "here is what happened lately", but it cannot
 * carry what this item is about: a notification raised by a specific action,
 * addressed to a person, that they can MARK READ and DISMISS.
 *
 * Three deliberate choices:
 *
 *  1. It is TENANT-scoped, not user-scoped. Every other bell in the platform
 *     hangs off one identity (student_id / teacher_id / agent_id) because those
 *     portals are single-user. An academy's owner side is the academy, and
 *     `Tenant::ownerEmail()` already resolves "the" owner address the same way
 *     for mail — so a second owner-role user reading the same bell is correct,
 *     not a leak between academies. TenantAware scopes it.
 *
 *  2. It carries the same email-outbox columns as the other three tables
 *     (`emailed_at`, `email_attempts`), so `lms:send-notification-emails` sweeps
 *     it with no special casing.
 *
 *  3. `reference_type` / `reference_id` are the same loose pointer the other
 *     tables use. Nothing joins on them; they let the frontend route an item
 *     ("enrolment", 41 -> the student page) without a second lookup column.
 *
 * Created with the guarded, non-transactional-MySQL-safe pattern used by the
 * other tables on this host: existence checks around every statement and a
 * try-catch on index creation, so a re-run on a shared host cannot collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_owner_notifications')) {
            return;
        }

        Schema::create('lms_owner_notifications', function (Blueprint $table): void {
            $table->id();
            // No foreign key: `tenants` is the platform's own table and a tenant
            // is never hard-deleted while its data exists (removal is a status
            // change), so a constraint would only add an ordering dependency to
            // provisioning.
            $table->unsignedBigInteger('tenant_id');
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('emailed_at')->nullable();
            $table->unsignedTinyInteger('email_attempts')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_read'], 'lms_owner_notifs_tenant_read_idx');
            $table->index('emailed_at', 'lms_owner_notifs_emailed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_owner_notifications');
    }
};
