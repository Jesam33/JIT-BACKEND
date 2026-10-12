<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourseReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The academy owner's view of its verified-learner reviews: every review with
 * the full student name, course, stars, comment and proof, plus one public
 * reply per review (shown under it on the course page).
 *
 * Owners can answer a review but never edit or delete it: that is what makes a
 * Jorsas review trustworthy. Authorization is the owner's own session +
 * tenant_admins check (ownerContext), which binds currentTenant, so every query
 * is scoped to their academy and another academy's review id 404s.
 */
class OwnerReviewController extends OwnerAdminController
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->ownerContext($request)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $reviews = LmsCourseReview::query()
            ->verified()
            ->with(['student:id,first_name,last_name', 'course:id,title'])
            ->latest('updated_at')
            ->limit(500)
            ->get();

        $avg = $reviews->avg('rating');

        return response()->json([
            'summary' => [
                'count' => $reviews->count(),
                'average' => $avg !== null ? round((float) $avg, 1) : null,
                'awaiting_reply' => $reviews->filter(fn ($r) => $r->reply === null && $r->comment !== null)->count(),
            ],
            'reviews' => $reviews->map(fn (LmsCourseReview $r) => [
                'id' => $r->id,
                'course_id' => $r->course_id,
                'course_title' => $r->course?->title,
                'student_name' => trim(($r->student?->first_name ?? '') . ' ' . ($r->student?->last_name ?? '')) ?: 'Student',
                'rating' => $r->rating,
                'comment' => $r->comment,
                'proof' => $r->proofText(),
                'reply' => $r->reply,
                'replied_at' => $r->replied_at?->toIso8601String(),
                'date' => $r->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Post (or edit, or clear with an empty reply) the academy's public reply to a review. */
    public function reply(Request $request, int $id): JsonResponse
    {
        if (! $this->ownerContext($request)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate(['reply' => ['nullable', 'string', 'max:1000']]);
        $review = LmsCourseReview::query()->verified()->findOrFail($id);

        $text = trim((string) ($validated['reply'] ?? ''));
        $review->forceFill([
            'reply' => $text !== '' ? $text : null,
            'replied_at' => $text !== '' ? now() : null,
        ])->save();

        return response()->json([
            'id' => $review->id,
            'reply' => $review->reply,
            'replied_at' => $review->replied_at?->toIso8601String(),
        ]);
    }
}
