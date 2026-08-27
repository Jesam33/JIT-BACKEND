<?php

namespace App\Support;

use App\Models\LmsCourse;
use App\Models\LmsCourseReview;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;

/**
 * Batch-computes the honestly-derived signals a Udemy-style course card needs —
 * star ratings, the instructor line, and the "Bestseller" badge — for a set of
 * course ids, in a handful of queries (no per-course N+1). Shared by the public
 * storefront serializer (PublicInstituteController) and the owner course list
 * (OwnerAdminController) so both agree on how a card is derived.
 *
 * Every value is real or an honest fallback — nothing is fabricated:
 *   - ratings     — AVG/COUNT over real lms_course_reviews rows (0/0 when none)
 *   - instructors — the course's track(s) → teacher name(s); the institute name
 *                   is the fallback, never a placeholder person
 *   - bestseller  — the single most-enrolled ACTIVE course in the institute, and
 *                   only once its real registered_count clears the configured
 *                   floor (config saas.bestseller_min_enrollments)
 *
 * The caller MUST have bound `currentTenant` first (both callers do, via the
 * storefront slug / ownerContext), so every query here is institute-scoped.
 */
class CourseCards
{
    /**
     * @param  int[]  $courseIds
     * @return array{ratings:array<int,array{average:float,count:int}>,instructors:array<int,string>,bestseller:array<int,bool>,fallback:?string}
     */
    public static function context(array $courseIds, ?string $fallbackInstructor = null): array
    {
        $ratings = [];
        $instructors = [];
        $bestseller = [];

        if (empty($courseIds)) {
            return compact('ratings', 'instructors', 'bestseller') + ['fallback' => $fallbackInstructor];
        }

        // ── Ratings: one aggregate row per course. ──
        LmsCourseReview::query()
            ->whereIn('course_id', $courseIds)
            ->selectRaw('course_id, AVG(rating) AS avg_rating, COUNT(*) AS cnt')
            ->groupBy('course_id')
            ->get()
            ->each(function ($row) use (&$ratings): void {
                $ratings[(int) $row->course_id] = [
                    'average' => round((float) $row->avg_rating, 1),
                    'count' => (int) $row->cnt,
                ];
            });

        // ── Instructor: the course's track(s) → teacher name(s). There is no
        // LmsTrack::instructor() relation, so resolve LmsTeacher directly (bulk). ──
        $tracks = LmsTrack::query()
            ->whereIn('course_id', $courseIds)
            ->get(['id', 'course_id', 'instructor_id']);

        $teacherNames = LmsTeacher::query()
            ->whereIn('id', $tracks->pluck('instructor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        foreach ($tracks->groupBy('course_id') as $cid => $courseTracks) {
            $names = $courseTracks
                ->pluck('instructor_id')
                ->filter()
                ->map(fn ($iid) => $teacherNames[$iid] ?? null)
                ->filter()
                ->unique()
                ->values();

            if ($names->isNotEmpty()) {
                $instructors[(int) $cid] = $names->count() > 1
                    ? $names->first() . ' +' . ($names->count() - 1) . ' more'
                    : $names->first();
            }
        }

        // ── Bestseller: top active registered_count in the institute, gated by
        // the floor. A floor of 0 still needs ≥1 enrollment to qualify as "top". ──
        $floor = (int) config('saas.bestseller_min_enrollments', 10);
        $top = (int) LmsCourse::query()->where('is_active', true)->max('registered_count');

        if ($top >= max($floor, 1)) {
            LmsCourse::query()
                ->where('is_active', true)
                ->where('registered_count', $top)
                ->whereIn('id', $courseIds)
                ->pluck('id')
                ->each(function ($id) use (&$bestseller): void {
                    $bestseller[(int) $id] = true;
                });
        }

        return compact('ratings', 'instructors', 'bestseller') + ['fallback' => $fallbackInstructor];
    }

    /**
     * Pull the per-course card fields out of a context array for one course id,
     * with safe defaults when the context is missing (0 ratings, no badge).
     *
     * @param  array{ratings?:array,instructors?:array,bestseller?:array,fallback?:?string}  $ctx
     * @return array{rating_average:float,rating_count:int,instructor_name:?string,is_bestseller:bool}
     */
    public static function fieldsFor(array $ctx, int $courseId): array
    {
        $rating = $ctx['ratings'][$courseId] ?? ['average' => 0.0, 'count' => 0];

        return [
            'rating_average' => (float) $rating['average'],
            'rating_count' => (int) $rating['count'],
            'instructor_name' => $ctx['instructors'][$courseId] ?? ($ctx['fallback'] ?? null),
            'is_bestseller' => (bool) ($ctx['bestseller'][$courseId] ?? false),
        ];
    }
}
