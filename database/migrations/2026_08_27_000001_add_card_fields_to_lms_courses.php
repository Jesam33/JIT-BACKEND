<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront course cards gained two owner-set fields for the Udemy-style card:
 *   1. `cover_image_path` — the owner-uploaded cover image (public disk path,
 *      served via asset('storage/…')); null falls back to a branded placeholder.
 *   2. `original_price` — an optional "was" price. The card renders it struck
 *      through ONLY when it exceeds the current `price` (an honest discount),
 *      never auto-invented.
 *
 * Guarded (Schema::hasColumn) + re-run safe, matching the 2026_08_* style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_courses')) {
            return;
        }

        Schema::table('lms_courses', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_courses', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable();
            }
            if (! Schema::hasColumn('lms_courses', 'original_price')) {
                $table->decimal('original_price', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lms_courses')) {
            return;
        }

        Schema::table('lms_courses', function (Blueprint $table): void {
            foreach (['cover_image_path', 'original_price'] as $col) {
                if (Schema::hasColumn('lms_courses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
