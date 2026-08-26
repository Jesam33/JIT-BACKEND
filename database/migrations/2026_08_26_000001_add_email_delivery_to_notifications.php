<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications gained an email-delivery outbox.
 *
 * A scheduled sweep (`lms:send-notification-emails`) emails every notification
 * row whose `emailed_at` is still null, then stamps it. Two of the three
 * notification tables already carried `emailed_at` (lms_notifications,
 * agent_notifications); lms_teacher_notifications did not. This migration:
 *   1. ensures `emailed_at` exists on all three,
 *   2. adds an `email_attempts` retry counter to all three,
 *   3. backfills `emailed_at = created_at` on every EXISTING row so the first
 *      sweep after deploy does NOT email historical notifications, and
 *   4. indexes `emailed_at` for the sweep's `WHERE emailed_at IS NULL` lookup.
 *
 * Idempotent + guarded (Schema::hasColumn / try-catch on the index) so it is
 * safe on a shared host where a re-run could otherwise collide.
 */
return new class extends Migration
{
    /** @var string[] */
    private array $tables = [
        'lms_notifications',
        'lms_teacher_notifications',
        'agent_notifications',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'emailed_at')) {
                    $t->dateTime('emailed_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'email_attempts')) {
                    $t->unsignedTinyInteger('email_attempts')->default(0);
                }
            });

            // Treat everything present at deploy time as already delivered, so we
            // never blast old notifications out as email on the first sweep.
            DB::table($table)
                ->whereNull('emailed_at')
                ->update(['emailed_at' => DB::raw('COALESCE(created_at, NOW())')]);

            // Index the sweep lookup. try/catch keeps it DB-agnostic + re-run safe.
            try {
                Schema::table($table, function (Blueprint $t) {
                    $t->index('emailed_at');
                });
            } catch (\Throwable $e) {
                // Index already exists — ignore.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['emailed_at']);
                });
            } catch (\Throwable $e) {
                // No such index — ignore.
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'email_attempts')) {
                    $t->dropColumn('email_attempts');
                }
                // `emailed_at` pre-existed on two of the three tables, so we
                // intentionally leave it in place rather than guess which to drop.
            });
        }
    }
};
