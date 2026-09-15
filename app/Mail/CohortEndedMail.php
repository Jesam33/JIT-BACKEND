<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email an institute owner receives when one of their cohorts reaches its
 * end date, sent by the `lms:notify-ended-cohorts` sweep. One email per cohort,
 * ever — the per-cohort `ended_notified_at` stamp makes it idempotent.
 *
 * Like {@see CeoForumMail} this speaks with the full Jorsas identity (it is the
 * platform talking to its own customer, the owner), so there is no per-institute
 * Reply-To. Plain scalars only, so it stays cheap to build in a sweep loop.
 * Not ShouldQueue: the sweep command is the deferral mechanism (mail is sync).
 */
class CohortEndedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $ownerName,
        public string $cohortName,
        public ?string $courseTitle,
        public string $datesLine,
        public int $completedCount,
        public int $studentCount,
        public string $reviewUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('mail.from.name') ?: 'Jorsas Tech');

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            subject: 'A cohort has ended: ' . $this->cohortName,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cohort-ended', with: [
            'ownerName' => $this->ownerName,
            'cohortName' => $this->cohortName,
            'courseTitle' => $this->courseTitle,
            'datesLine' => $this->datesLine,
            'completedCount' => $this->completedCount,
            'studentCount' => $this->studentCount,
            'reviewUrl' => $this->reviewUrl,
        ]);
    }
}
