<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback from the people using an academy's portals: students, staff, the
 * academy owner and its Admission Marketers.
 *
 * One table for all four roles, for the same reason rights_requests has one:
 * they are read back identically (a queue the platform triages) and four
 * near-identical tables would drift apart.
 *
 * `tenant_id` is what the feedback is ABOUT, not who may read it. It is written
 * from the sender's own session, never from the request body, and nothing in an
 * academy's portal ever queries this table — only the host back office does,
 * explicitly dropping the TenantScope (see AdminController::feedbackPage).
 *
 * `author_name` / `author_email` are SNAPSHOTS, copied at the moment of sending.
 * The row has to stay readable after the account it names is renamed, anonymised
 * or purged: a piece of feedback you can no longer attribute is a piece of
 * feedback you cannot act on, and the account is free to change or disappear
 * long before the queue is worked through. `author_id` is kept alongside them as
 * a plain id (no FK) for the common case where the account still exists.
 *
 * `page` is the portal path the sender was looking at when they opened the form.
 * It is the single most useful field for reproducing a report, and it is
 * attacker-controlled free text, so it is length-capped and rendered escaped.
 *
 * `category` and `status` are plain strings rather than enums, matching
 * academy_reports: an enum cannot gain a value without an ALTER, and the valid
 * sets are validated in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feedback')) {
            return;
        }

        Schema::create('feedback', function (Blueprint $t): void {
            $t->id();
            // Nullable so a row can still be recorded if no tenant was bound,
            // exactly like rights_requests. In practice the session always
            // supplies one.
            $t->unsignedBigInteger('tenant_id')->nullable();

            // student | staff | owner | agent
            $t->string('author_role', 20);
            $t->unsignedBigInteger('author_id')->nullable();
            // Snapshots — see the docblock. Must survive a purge.
            $t->string('author_name')->nullable();
            $t->string('author_email')->nullable();

            // The portal path the form was opened from, e.g. /lms/app/tasks.
            $t->string('page', 191)->nullable();

            // idea | problem | confusing | other
            $t->string('category', 30);
            $t->text('message');

            // new | planned | done | dismissed
            $t->string('status', 20)->default('new');
            $t->text('resolution_note')->nullable();
            // No FK: the host user row can outlive the feedback, and a FK here
            // would block a user deletion for no benefit. Same choice as
            // academy_reports.handled_by.
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->dateTime('handled_at')->nullable();

            $t->timestamps();

            // The queue's default view is "newest first, per academy", and the
            // sidebar badge counts outstanding rows.
            $t->index('status', 'feedback_status_idx');
            $t->index(['tenant_id', 'status'], 'feedback_tenant_status_idx');
            $t->index('created_at', 'feedback_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
