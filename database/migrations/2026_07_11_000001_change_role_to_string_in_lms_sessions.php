<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE lms_sessions MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'student'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE lms_sessions MODIFY COLUMN role ENUM('student', 'teacher') NOT NULL DEFAULT 'student'");
    }
};
