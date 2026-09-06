<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourseReview;
use App\Models\LmsEnrollment;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student rates the course they're enrolled in (1 to 5 stars, optional comment).
 *
 * Gate: the student may only rate the course they are actually tied to, the
 * one resolved by the canonical enrollment pattern (selected_course_id, else
 * their enrollment's track → course). One rating per student per course; a
 * re-rate updates the same row (updateOrCreate + the unique index), so the
 * storefront average stays honest and the count never inflates.
 *
 * The route runs inside the `tenant.required` group (ResolveTenantFromSession
 * binds currentTenant from the bearer token), so the LmsStudent lookup, the
 * enrollment queries, and the review write are all institute-scoped and the new
 * row's tenant_id is auto-stamped by the TenantAware trait.
 */
class StudentCourseReviewController extends BaseLmsController
{
    public function store(Request $request, int $id): JsonResponse
    {
        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = LmsStudent::findOrFail($session->user_id);

        // Canonical "your course" resolution (same as the dashboard): the course
        // the student picked, else the course of the track they're enrolled in.
        $enrollment = LmsEnrollment::query()->where('student_id', $student->id)->first();
        $track = $enrollment ? LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;

        if (! $courseId || (int) $courseId !== $id) {
            return response()->json([
                'message' => "You can only rate a course you're enrolled in.",
            ], 403);
        }

        LmsCourseReview::query()->updateOrCreate(
            ['course_id' => $id, 'student_id' => $student->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null],
        );

        $agg = LmsCourseReview::query()
            ->where('course_id', $id)
            ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS cnt')
            ->first();

        return response()->json([
            'rating_average' => round((float) ($agg->avg_rating ?? 0), 1),
            'rating_count' => (int) ($agg->cnt ?? 0),
            'your_rating' => (int) $validated['rating'],
        ]);
    }
}
