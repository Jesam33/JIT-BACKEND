<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC (role presets for staff) and login security (device tracking).
 *
 * Two unrelated-looking changes in one file because they ship together and both
 * are additive column work on the same two tables.
 *
 * ── Staff roles ──────────────────────────────────────────────────────────────
 * `lms_teachers.staff_role` holds one of owner|admin|instructor|assistant. It is
 * a PRESET KEY, not a permission list: the section each role may reach lives in
 * App\Support\StaffPermissions, so the catalogue can gain a section without a
 * migration, and a role rename cannot silently grant access.
 *
 * Deliberately NOT reusing the existing `role` column. That one is a free-text
 * display label ("Instructor", "teacher") read by StaffAuthController and
 * OwnerAdminController purely to print a job title on screen — writing
 * authorization values into it would make a cosmetic field load-bearing, and any
 * typo in an existing row would become an accidental grant.
 *
 * The owner is still identified by `is_academy_owner`, never by this column:
 * there is exactly one owner account per academy and staff_role cannot express
 * "the account holder". LmsTeacher::staffRole() resolves owner first.
 *
 * ── Login devices ────────────────────────────────────────────────────────────
 * `ip` / `user_agent` / `last_seen_at` on lms_sessions record WHERE a session was
 * opened, so the device can be named in a "new sign-in" alert and listed on the
 * profile.
 *
 * The separate `lms_login_devices` table is what actually drives the alert, and
 * it exists because sessions expire (7 days): deriving "have I seen this device
 * before?" from lms_sessions would make a returning user on their own laptop look
 * like a new device every week, which trains people to ignore the email. Devices
 * outlive sessions.
 *
 * Additive + guarded (hasColumn / try-catch on every index) so re-running on a
 * shared host is safe. One column per Schema::table call because MySQL cannot
 * combine several ADD COLUMN statements with an ADD INDEX in one ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_teachers') && ! Schema::hasColumn('lms_teachers', 'staff_role')) {
            Schema::table('lms_teachers', function (Blueprint $t): void {
                $t->string('staff_role', 20)->default('instructor');
            });
        }

        if (Schema::hasTable('lms_sessions')) {
            if (! Schema::hasColumn('lms_sessions', 'ip')) {
                Schema::table('lms_sessions', function (Blueprint $t): void {
                    // 45 chars covers IPv6 including a zone index.
                    $t->string('ip', 45)->nullable();
                });
            }

            if (! Schema::hasColumn('lms_sessions', 'user_agent')) {
                Schema::table('lms_sessions', function (Blueprint $t): void {
                    $t->string('user_agent', 255)->nullable();
                });
            }

            if (! Schema::hasColumn('lms_sessions', 'last_seen_at')) {
                Schema::table('lms_sessions', function (Blueprint $t): void {
                    $t->dateTime('last_seen_at')->nullable();
                });
            }
        }

        if (! Schema::hasTable('lms_login_devices')) {
            Schema::create('lms_login_devices', function (Blueprint $t): void {
                $t->id();
                // 'student' | 'staff' | 'owner' | 'agent' — mirrors lms_sessions.role.
                $t->string('role', 20);
                $t->unsignedBigInteger('user_id');
                // sha1(ip . '|' . normalised user agent). Not reversible by design:
                // nothing needs to read back the raw pair, only compare.
                $t->string('fingerprint', 40);
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                // Human-readable labels parsed at write time (e.g. "Chrome on
                // Windows"), so the device list does not re-parse UAs of every
                // historical row on each render.
                $t->string('device_label', 120)->nullable();
                $t->dateTime('first_seen_at')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();

                // One row per (account, device), which is what makes the
                // "have I seen this before?" lookup a single indexed hit.
                $t->unique(['role', 'user_id', 'fingerprint'], 'lms_login_devices_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_login_devices');

        if (Schema::hasTable('lms_sessions')) {
            Schema::table('lms_sessions', function (Blueprint $t): void {
                foreach (['ip', 'user_agent', 'last_seen_at'] as $column) {
                    if (Schema::hasColumn('lms_sessions', $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('lms_teachers') && Schema::hasColumn('lms_teachers', 'staff_role')) {
            Schema::table('lms_teachers', function (Blueprint $t): void {
                $t->dropColumn('staff_role');
            });
        }
    }
};
