<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill lms_students.phone from the student's training registration (item 4).
 *
 * Phone is captured on every registration (training_registrations.phone_number)
 * but was never copied onto the provisioned LmsStudent, so the owner's Students
 * table showed "—" for the column. The provisioning sites now write phone going
 * forward; this backfills the students that were created before that fix.
 *
 * Two passes, most-authoritative first: the exact registration link
 * (training_registration_id), then an email match scoped to the same tenant
 * (null-safe so pre-tenancy NULL rows still line up). Only fills blanks — a phone
 * a student already set on their profile is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_students') || ! Schema::hasTable('training_registrations')) {
            return;
        }
        if (! Schema::hasColumn('lms_students', 'phone')) {
            return;
        }

        // Pass 1 — exact link on the registration id (unambiguous).
        DB::statement("
            UPDATE lms_students s
            JOIN training_registrations r ON r.id = s.training_registration_id
            SET s.phone = r.phone_number
            WHERE (s.phone IS NULL OR s.phone = '')
              AND r.phone_number IS NOT NULL AND r.phone_number <> ''
        ");

        // Pass 2 — email match within the same tenant, for students provisioned
        // without a stored training_registration_id (zero-payment / free path).
        DB::statement("
            UPDATE lms_students s
            JOIN training_registrations r
              ON r.email = s.email
             AND (r.tenant_id <=> s.tenant_id)
            SET s.phone = r.phone_number
            WHERE (s.phone IS NULL OR s.phone = '')
              AND r.phone_number IS NOT NULL AND r.phone_number <> ''
        ");
    }

    public function down(): void
    {
        // One-way data backfill — there is no safe automatic reversal (we can't
        // tell a backfilled phone from one the student later edited).
    }
};
