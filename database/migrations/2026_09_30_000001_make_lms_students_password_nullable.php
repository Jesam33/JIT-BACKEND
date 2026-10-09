<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lms_students.password must be nullable.
 *
 * A student account is created the moment a course is paid for (or a free /
 * comped registration is approved), BEFORE the student has chosen a password:
 * they set it afterwards from the emailed setup link. LmsIntakeController's
 * completePayment() and handleZeroPayment() therefore insert the row with no
 * password at all.
 *
 * The original create migration declared the column NOT NULL. Existing local
 * databases had been loosened by hand, which hid the problem; a database built
 * purely from migrations (a fresh server, the test database) rejected the
 * insert, so a student could be charged and never get an account or setup email.
 * This makes the schema match what the code has always required.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_students') || ! Schema::hasColumn('lms_students', 'password')) {
            return;
        }

        Schema::table('lms_students', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversed: re-tightening would break every student who has not set
        // a password yet.
    }
};
