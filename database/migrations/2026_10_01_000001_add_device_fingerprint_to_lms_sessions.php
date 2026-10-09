<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which DEVICE each session was opened from.
 *
 * Login alerts now recognise a device by a random per-browser id the frontend
 * sends (X-Device-Id), not by IP + browser: on mobile data the IP changes all
 * the time, which made people's own phones look new and trained them to ignore
 * the alert. A session stores the resulting fingerprint, so "sign out this
 * device" still finds that device's sessions after its IP has changed.
 * Null on older sessions, which keep matching the old way (ip + user agent).
 * See App\Support\LoginDeviceTracker.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_sessions') && ! Schema::hasColumn('lms_sessions', 'device_fingerprint')) {
            Schema::table('lms_sessions', function (Blueprint $table): void {
                $table->string('device_fingerprint', 64)->nullable()->index();
            });
        }

        // Which known devices are keyed by the new device id (true) versus the
        // old ip + browser fingerprint (false). An old row is quietly adopted the
        // first time that browser signs in with a device id, so the switch-over
        // doesn't email everyone about their own laptop.
        if (Schema::hasTable('lms_login_devices') && ! Schema::hasColumn('lms_login_devices', 'by_device_id')) {
            Schema::table('lms_login_devices', function (Blueprint $table): void {
                $table->boolean('by_device_id')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lms_login_devices') && Schema::hasColumn('lms_login_devices', 'by_device_id')) {
            Schema::table('lms_login_devices', function (Blueprint $table): void {
                $table->dropColumn('by_device_id');
            });
        }

        if (Schema::hasTable('lms_sessions') && Schema::hasColumn('lms_sessions', 'device_fingerprint')) {
            Schema::table('lms_sessions', function (Blueprint $table): void {
                $table->dropIndex(['device_fingerprint']);
                $table->dropColumn('device_fingerprint');
            });
        }
    }
};
