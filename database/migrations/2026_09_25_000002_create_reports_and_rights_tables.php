<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two Jorsas-facing queues: reports about an academy, and data-rights
 * requests from the people on it.
 *
 * Both tables are `tenant_id`-bearing and both are read by the HOST admin, which
 * drops the TenantScope explicitly (see AdminController::index for the pattern).
 * The tenant_id is what the academy is, not who may see the row: a report is
 * written by a student about their own academy, and only the platform reads it
 * back. Nothing in an academy's own portal queries these tables.
 *
 * ── academy_reports ─────────────────────────────────────────────────────────
 * `status` and `category` are plain strings rather than enums. Enum columns
 * cannot gain a value without an ALTER, and the valid sets are validated in PHP
 * (the codebase's existing choice for status-ish columns). `category` is what the
 * student picked; `details` is their own words.
 *
 * `resolution_note` / `handled_by` / `handled_at` are the audit trail: who at
 * Jorsas closed it, when, and what they concluded. `handled_by` is a plain
 * unsigned bigint with no FK because the host user row can outlive the report's
 * usefulness and a FK here would block a user deletion for no benefit.
 *
 * ── rights_requests ─────────────────────────────────────────────────────────
 * `requester_role` covers student|staff|owner in one table, because the platform
 * answers all three the same way and three near-identical tables would drift.
 *
 * `requester_email` is a SNAPSHOT, deliberately duplicated from the account. It
 * is what makes the request answerable after the account is anonymised or purged:
 * the erasure flow literally destroys the email on the source row, and a request
 * that could no longer be replied to would be a dead letter. This is the one
 * place in the schema where copying an email is correct rather than a denormal
 * smell.
 *
 * `tenant_id` is nullable so a request can still be recorded if no tenant was
 * bound (a platform-level query about a deleted academy), but in practice the
 * session always binds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('academy_reports')) {
            Schema::create('academy_reports', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                // The reporting student. Kept as a plain id (no FK) so that
                // anonymising or removing the student does not erase the fact
                // that the academy was reported.
                $t->unsignedBigInteger('student_id')->nullable();
                $t->string('category', 40);
                $t->text('details');
                // open | reviewing | resolved | dismissed
                $t->string('status', 20)->default('open');
                $t->text('resolution_note')->nullable();
                $t->unsignedBigInteger('handled_by')->nullable();
                $t->dateTime('handled_at')->nullable();
                $t->timestamps();

                // The host queue's default view is "everything still open", and
                // the per-academy grouping reads by tenant first.
                $t->index(['tenant_id', 'status'], 'academy_reports_tenant_status_idx');
                $t->index('status', 'academy_reports_status_idx');
            });
        }

        if (! Schema::hasTable('rights_requests')) {
            Schema::create('rights_requests', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                // student | staff | owner
                $t->string('requester_role', 20);
                $t->unsignedBigInteger('requester_id')->nullable();
                // Snapshot — see the class docblock. Must never be nulled by a purge.
                $t->string('requester_email');
                // access | rectification | erasure | portability | restrict | object
                $t->string('type', 30);
                $t->text('details');
                // open | in_progress | completed | refused
                $t->string('status', 20)->default('open');
                $t->text('response_note')->nullable();
                $t->unsignedBigInteger('handled_by')->nullable();
                $t->dateTime('handled_at')->nullable();
                $t->timestamps();

                $t->index('status', 'rights_requests_status_idx');
                $t->index(['requester_role', 'requester_id'], 'rights_requests_requester_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rights_requests');
        Schema::dropIfExists('academy_reports');
    }
};
