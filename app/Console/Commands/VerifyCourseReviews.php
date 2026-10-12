<?php

namespace App\Console\Commands;

use App\Models\LmsCourseReview;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Support\LearnerProof;
use Illuminate\Console\Command;

/**
 * Checks reviews written before verified-learner reviews existed against their
 * authors' real records (App\Support\LearnerProof). A review whose author has
 * learned enough becomes verified, with its proof stamped; the rest stay
 * unverified and out of the stars. Safe to re-run: verified rows are skipped.
 */
class VerifyCourseReviews extends Command
{
    protected $signature = 'lms:verify-reviews';

    protected $description = 'Verify existing course reviews against each author\'s attendance, tasks, quizzes and certificates';

    public function handle(): int
    {
        $verified = 0;
        $kept = 0;

        foreach (Tenant::query()->get() as $tenant) {
            // Reviews and students are tenant-scoped: bind each academy in turn.
            app()->instance('currentTenant', $tenant);

            LmsCourseReview::query()->where('verified', false)->get()
                ->each(function (LmsCourseReview $review) use (&$verified, &$kept): void {
                    $student = LmsStudent::query()->find($review->student_id);
                    $proof = $student ? LearnerProof::for($student, (int) $review->course_id) : null;

                    if (! $proof || ! $proof['eligible']) {
                        $kept++;

                        return;
                    }

                    $review->forceFill([
                        'verified' => true,
                        'attended_classes' => $proof['attended'],
                        'classes_held' => $proof['held'],
                        'activities' => $proof['activities'],
                        'finished' => $proof['finished'],
                    ])->save();
                    $verified++;
                });
        }

        app()->forgetInstance('currentTenant');
        $this->info("Verified {$verified} review(s); {$kept} left unverified (their authors haven't learned enough yet).");

        return self::SUCCESS;
    }
}
