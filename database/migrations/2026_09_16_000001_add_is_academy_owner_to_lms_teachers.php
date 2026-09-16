<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the one LmsTeacher row per academy that stands in for its OWNER, so
     * the owner can use every staff feature from the owner portal.
     *
     * The staff portal scopes everything through a teacher (`LmsTrack.instructor_id`,
     * `lms_scheduled_classes.teacher_id`, chat membership, …), so rather than
     * teach every staff endpoint a second "academy-wide" mode, the owner is given
     * a real teacher row of their own (auto-provisioned on first use) flagged
     * here. The flag is what makes that row mean "whole academy" instead of
     * "one instructor's courses": see BaseLmsController::actorCourseIds() and
     * actorTrackIds(), and the filters that keep the mirror out of staff lists
     * and instructor pickers.
     *
     * Additive + guarded (`hasColumn`), so re-running on a shared host is safe.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lms_teachers')) {
            return;
        }

        if (! Schema::hasColumn('lms_teachers', 'is_academy_owner')) {
            Schema::table('lms_teachers', function (Blueprint $table): void {
                $table->boolean('is_academy_owner')->default(false)->after('is_active');
            });
        }

        // One mirror per academy is looked up on nearly every owner request, and
        // the flag also drives the "exclude from staff lists" filters.
        if (! $this->hasIndex('lms_teachers', 'lms_teachers_tenant_owner_idx')) {
            Schema::table('lms_teachers', function (Blueprint $table): void {
                $table->index(['tenant_id', 'is_academy_owner'], 'lms_teachers_tenant_owner_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('lms_teachers') || ! Schema::hasColumn('lms_teachers', 'is_academy_owner')) {
            return;
        }

        if ($this->hasIndex('lms_teachers', 'lms_teachers_tenant_owner_idx')) {
            Schema::table('lms_teachers', function (Blueprint $table): void {
                $table->dropIndex('lms_teachers_tenant_owner_idx');
            });
        }

        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->dropColumn('is_academy_owner');
        });
    }

    /** Whether an index exists — a plain hasIndex check is unavailable on some drivers. */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);

            return ! empty($rows);
        } catch (\Throwable $e) {
            // Non-MySQL (or a permissions hiccup): fall back to attempting the
            // create, which is itself guarded by the caller's try/catch-free path.
            return false;
        }
    }
};
