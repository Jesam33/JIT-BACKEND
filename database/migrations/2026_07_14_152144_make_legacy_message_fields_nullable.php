<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE lms_messages MODIFY from_role VARCHAR(20) NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY from_id BIGINT UNSIGNED NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY to_role VARCHAR(20) NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY to_id BIGINT UNSIGNED NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY body TEXT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE lms_messages MODIFY from_role ENUM('student', 'teacher') NOT NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY from_id BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY to_role ENUM('student', 'teacher') NOT NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY to_id BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE lms_messages MODIFY body TEXT NOT NULL");
    }
};
