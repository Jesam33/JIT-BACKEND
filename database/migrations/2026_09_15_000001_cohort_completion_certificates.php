<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cohort-completion certificates:
     *
     *  - lms_tracks gains two bookkeeping stamps for the ended-cohort flow:
     *      ended_notified_at      set by the `lms:notify-ended-cohorts` sweep the
     *                             first time it emails the owner about the cohort
     *                             ending (makes the sweep idempotent), and
     *      certificates_issued_at set when the owner accepts the auto-issue panel
     *                             (or dismisses it) so the panel + owner bell stop
     *                             surfacing the cohort.
     *
     *  - lms_certificates gains the fields the auto-issued certificate carries:
     *      track_id   the cohort it was issued for (null for the existing
     *                 hand-issued certificates, which have no cohort),
     *      serial     the printed certificate number (e.g. 2026-000123),
     *      start_date / end_date  the cohort's dates, COPIED at issue time so a
     *                             later cohort edit never rewrites a certificate
     *                             that was already issued.
     *
     * Plus a (student_id, track_id) unique so a student can never receive two
     * certificates for the same cohort — MySQL treats NULL track_id rows as
     * never colliding, so hand-issued (track-less) certificates are unaffected.
     *
     * Every step is guarded with hasColumn/hasIndex so a partially-applied run
     * (or a re-deploy that already ran this) is a no-op rather than a 500 —
     * MySQL ALTERs can't roll back.
     */
    public function up(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_tracks', 'ended_notified_at')) {
                $table->timestamp('ended_notified_at')->nullable()->after('registration_deadline');
            }
            if (! Schema::hasColumn('lms_tracks', 'certificates_issued_at')) {
                $table->timestamp('certificates_issued_at')->nullable()->after('ended_notified_at');
            }
        });

        Schema::table('lms_certificates', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_certificates', 'track_id')) {
                $table->unsignedBigInteger('track_id')->nullable()->index()->after('course_id');
            }
            if (! Schema::hasColumn('lms_certificates', 'serial')) {
                $table->string('serial')->nullable()->unique()->after('track_id');
            }
            if (! Schema::hasColumn('lms_certificates', 'start_date')) {
                $table->date('start_date')->nullable()->after('serial');
            }
            if (! Schema::hasColumn('lms_certificates', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });

        // One certificate per student per cohort. Added separately from the
        // column adds above because a duplicate pair in existing data would
        // abort the whole ALTER batch; check-then-create lets us report cleanly.
        $hasPairUnique = collect(Schema::getIndexes('lms_certificates'))
            ->contains(fn ($idx) => ($idx['name'] ?? '') === 'lms_certificates_student_track_unique'
                || ($idx['unique'] ?? false) && collect($idx['columns'] ?? [])->map(fn ($c) => strtolower($c))->sort()->values()->all() === ['student_id', 'track_id']);
        if (! $hasPairUnique) {
            Schema::table('lms_certificates', function (Blueprint $table): void {
                $table->unique(['student_id', 'track_id'], 'lms_certificates_student_track_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('lms_tracks', function (Blueprint $table): void {
            $table->dropColumn(['ended_notified_at', 'certificates_issued_at']);
        });

        Schema::table('lms_certificates', function (Blueprint $table): void {
            $hasUnique = collect(Schema::getIndexes('lms_certificates'))
                ->contains(fn ($idx) => ($idx['name'] ?? '') === 'lms_certificates_student_track_unique');
            if ($hasUnique) {
                $table->dropUnique('lms_certificates_student_track_unique');
            }
            $table->dropColumn(['track_id', 'serial', 'start_date', 'end_date']);
        });
    }
};
