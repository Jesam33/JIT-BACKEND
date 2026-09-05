<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Owner-invited students (Issue C) join a course by email alone — at invite
     * time they have no date-of-birth / qualification / whatsapp / phone (they
     * fill their profile in later). The public course-registration form still
     * collects and REQUIRES all of these, so only the column storage becomes
     * optional here; the intake validation in LmsIntakeController is unchanged.
     */
    public function up(): void
    {
        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->change();
            $table->string('qualification_level')->nullable()->change();
            $table->string('phone_number')->nullable()->change();
            $table->string('whatsapp')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Best-effort revert. Owner-invite rows legitimately hold NULLs in these
        // columns, so backfill a placeholder before re-imposing NOT NULL — else
        // migrate:rollback would fail on existing data.
        DB::table('training_registrations')->whereNull('date_of_birth')->update(['date_of_birth' => '1970-01-01']);
        DB::table('training_registrations')->whereNull('qualification_level')->update(['qualification_level' => '']);
        DB::table('training_registrations')->whereNull('phone_number')->update(['phone_number' => '']);
        DB::table('training_registrations')->whereNull('whatsapp')->update(['whatsapp' => '']);

        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable(false)->change();
            $table->string('qualification_level')->nullable(false)->change();
            $table->string('phone_number')->nullable(false)->change();
            $table->string('whatsapp')->nullable(false)->change();
        });
    }
};
