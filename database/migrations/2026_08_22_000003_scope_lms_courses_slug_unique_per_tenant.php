<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Course slugs are unique PER INSTITUTE, not globally. The original
     * unique(slug) index (added in 2026_07_10, before multi-tenancy) makes the
     * second institute that wants a "aperture-class" course crash with a 1062
     * duplicate-key 500 on creation. Replace it with unique(tenant_id, slug) so
     * each institute owns its own slug namespace — which is exactly how the
     * storefront resolves them: /i/{institute-slug}/courses/{course-slug}.
     *
     * Guarded/idempotent (safe on fresh installs and re-runs): only touches an
     * index whose presence/absence has been checked first.
     */
    public function up(): void
    {
        $names = collect(Schema::getIndexes('lms_courses'))->pluck('name');

        if ($names->contains('lms_courses_slug_unique')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->dropUnique('lms_courses_slug_unique');
            });
        }

        if (! $names->contains('lms_courses_tenant_id_slug_unique')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'slug']);
            });
        }
    }

    public function down(): void
    {
        $names = collect(Schema::getIndexes('lms_courses'))->pluck('name');

        if ($names->contains('lms_courses_tenant_id_slug_unique')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->dropUnique('lms_courses_tenant_id_slug_unique');
            });
        }

        // Only restore the global unique if slugs are still globally unique — after
        // multi-tenant use two institutes may legitimately share one, in which case
        // re-adding unique(slug) would fail. Guard it.
        $hasDupes = DB::table('lms_courses')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDupes && ! $names->contains('lms_courses_slug_unique')) {
            Schema::table('lms_courses', function (Blueprint $table): void {
                $table->unique('slug');
            });
        }
    }
};
