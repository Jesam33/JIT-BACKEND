<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourseReview;
use App\Models\LmsStudent;
use App\Support\LearnerProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student reviews the course they're enrolled in (1 to 5 stars, optional
 * comment): a verified-learner review.
 *
 * Gate: the student may only review the course they are actually tied to (the
 * canonical selected_course_id, else their enrollment's track → course), and
 * only once they've learned enough to judge it (App\Support\LearnerProof:
 * finished, or attended enough classes, or completed enough tasks/quizzes).
 * The proof is stamped on the row, refreshed on every re-rate, and shown with
 * the review. One review per student per course (updateOrCreate + the unique
 * index), so the count never inflates.
 *
 * The route runs inside the `tenant.required` group, so the lookups and the
 * write are all institute-scoped and tenant_id is stamped by TenantAware.
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

        if (LearnerProof::courseId($student) !== $id) {
            return response()->json([
                'message' => "You can only review a course you're enrolled in.",
            ], 403);
        }

        $proof = LearnerProof::for($student, $id);
        if (! $proof['eligible']) {
            return response()->json([
                'message' => $proof['missing'] ?? 'Keep learning a little longer before reviewing this course.',
                'not_yet_eligible' => true,
                'proof' => $proof,
            ], 403);
        }

        $comment = isset($validated['comment']) ? trim((string) $validated['comment']) : null;

        LmsCourseReview::query()->updateOrCreate(
            ['course_id' => $id, 'student_id' => $student->id],
            [
                'rating' => $validated['rating'],
                'comment' => $comment !== '' ? $comment : null,
                'verified' => true,
                'attended_classes' => $proof['attended'],
                'classes_held' => $proof['held'],
                'activities' => $proof['activities'],
                'finished' => $proof['finished'],
            ],
        );

        $agg = LmsCourseReview::query()
            ->verified()
            ->where('course_id', $id)
            ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS cnt')
            ->first();

        return response()->json([
            'rating_average' => round((float) ($agg->avg_rating ?? 0), 1),
            'rating_count' => (int) ($agg->cnt ?? 0),
            'your_rating' => (int) $validated['rating'],
            'your_comment' => $comment !== '' ? $comment : null,
        ]);
    }
}
