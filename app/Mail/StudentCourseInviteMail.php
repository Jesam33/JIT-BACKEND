<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\TrainingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an institute owner invites a student straight into a course
 * (Issue C). Branded as the academy (from the registration's tenant_id) and it
 * NAMES the course the student is joining, closing the gap the owner reported
 * ("how do students know the course they're signing up for?").
 *
 * One template, two modes:
 *  - paid ($requiresPayment): names the course + fee and links to the signup
 *    page, which launches payment; the account is provisioned only after
 *    Paystack confirms (completePayment).
 *  - comped/free: names the course and links to the set-a-password signup page,
 *    which auto-enrols the student.
 */
class StudentCourseInviteMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public TrainingRegistration $registration,
        public string $link,
        public bool $requiresPayment,
        public string $priceDisplay,
    ) {
        $this->resolveBrand($registration->tenant_id);
    }

    public function envelope(): Envelope
    {
        // Academy-neutral subject; the sender NAME carries the institute.
        return $this->brandedEnvelope('You have been invited to join ' . $this->registration->course_name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.student-course-invite');
    }
}
