<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_courses', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->default(0)->after('description');
            $table->integer('max_students')->default(0)->after('price');
            $table->integer('registered_count')->default(0)->after('max_students');
            $table->text('requirements')->nullable()->after('registered_count');
            $table->string('slug')->nullable()->after('title');
        });

        // Backfill slugs for existing courses
        DB::table('lms_courses')->whereNull('slug')->orderBy('id')->each(function (object $course): void {
            $slug = Str::slug($course->title);
            $baseSlug = $slug;
            $counter = 1;

            while (DB::table('lms_courses')->where('slug', $slug)->where('id', '!=', $course->id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            DB::table('lms_courses')->where('id', $course->id)->update(['slug' => $slug]);
        });

        Schema::table('lms_courses', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('lms_courses', function (Blueprint $table): void {
            $table->dropColumn(['price', 'max_students', 'registered_count', 'requirements', 'slug']);
        });
    }
};
