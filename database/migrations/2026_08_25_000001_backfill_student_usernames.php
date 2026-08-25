<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill @handles for students created without one.
 *
 * Students provisioned through the SaaS storefront (LmsIntakeController /
 * StudentAuthController::updateOrCreate) never received a `username`, unlike the
 * pre-SaaS students the 2026_07_16 migration backfilled. A null username silently
 * broke staff/student group chat: the @mention dropdown filter, the inserted
 * handle and the mention->notification lookup all key on the username. New rows
 * are now handled by LmsStudent::booted(); this fills every existing null row.
 *
 * Handles are dotless (a single \w+ token, so the mention parser matches the whole
 * name) and unique WITHIN a tenant (the live unique index is (tenant_id, username),
 * NULL tenant_ids stay distinct). Idempotent: re-running finds no null rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_students') || ! Schema::hasColumn('lms_students', 'username')) {
            return;
        }

        $hasTenant = Schema::hasColumn('lms_students', 'tenant_id');

        // Seed per-tenant "used" sets from handles already taken so a backfilled
        // one never collides with an existing student in the same institute.
        $usedByTenant = [];
        $existing = DB::table('lms_students')
            ->whereNotNull('username')
            ->get($hasTenant ? ['username', 'tenant_id'] : ['username']);
        foreach ($existing as $row) {
            $key = $hasTenant ? ($row->tenant_id ?? 'null') : 'global';
            $usedByTenant[$key][strtolower($row->username)] = true;
        }

        $columns = $hasTenant
            ? ['id', 'first_name', 'last_name', 'email', 'tenant_id']
            : ['id', 'first_name', 'last_name', 'email'];

        foreach (DB::table('lms_students')->whereNull('username')->get($columns) as $s) {
            $key = $hasTenant ? ($s->tenant_id ?? 'null') : 'global';

            $first = preg_replace('/[^a-z0-9]/i', '', (string) $s->first_name);
            $last = preg_replace('/[^a-z0-9]/i', '', (string) $s->last_name);
            $base = strtolower($first . $last);
            if ($base === '') {
                $base = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok((string) $s->email, '@')));
            }
            if ($base === '') {
                $base = 'user';
            }

            $candidate = $base;
            $n = 1;
            while (isset($usedByTenant[$key][$candidate])) {
                $candidate = $base . $n;
                $n++;
            }
            $usedByTenant[$key][$candidate] = true;

            DB::table('lms_students')->where('id', $s->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        // No-op: usernames are now first-class (chat mentions depend on them) and
        // this migration adds no schema, so there is nothing to roll back.
    }
};
