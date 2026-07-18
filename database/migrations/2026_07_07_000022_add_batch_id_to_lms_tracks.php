<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('instructor_id')->constrained('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
