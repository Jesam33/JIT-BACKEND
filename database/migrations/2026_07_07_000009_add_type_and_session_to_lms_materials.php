<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_materials', function (Blueprint $table): void {
            $table->enum('type', ['file', 'link', 'image'])->default('file')->after('title');
            $table->foreignId('session_id')->nullable()->after('file_url')->constrained('lms_classrooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lms_materials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn('type');
        });
    }
};
