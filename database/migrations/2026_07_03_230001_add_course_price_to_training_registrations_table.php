<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->decimal('course_price', 12, 2)->nullable()->after('learning_mode');
        });
    }

    public function down(): void
    {
        Schema::table('training_registrations', function (Blueprint $table): void {
            $table->dropColumn('course_price');
        });
    }
};
