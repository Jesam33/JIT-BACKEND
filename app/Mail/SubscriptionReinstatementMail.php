<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily nudge an institute owner gets for every day of the grace window
 * after their paid plan's period ends, sent by the `lms:send-subscription-
 * reminders` sweep. It is the platform talking to its own customer about the
 * platform's own billing, so (like {@see CohortEndedMail} and {@see CeoForumMail})
 * it carries the full Jorsas identity and no per-institute Reply-To.
 *
 * One per tenant per calendar day — the sweep stamps the date it last sent, so
 * re-running it never double-sends. Plain scalars only so it stays cheap to
 * build in a loop, and not ShouldQueue: the sweep command IS the deferral
 * mechanism (mail is sync on this host).
 */
class SubscriptionReinstatementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $ownerName,
        public string $academyName,
        public string $planName,
        // Human date the paid period ended.
        public string $endedOn,
        // Human date the portal freezes if they don't reinstate.
        public string $freezesOn,
        // Whole days left in the grace window, 0 on the final day.
        public int $daysLeft,
        public string $billingUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('mail.from.name') ?: 'Jorsas Tech');

        $urgent = $this->daysLeft <= 1;

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            subject: $urgent
                ? 'Last day: your academy pauses tomorrow'
                : 'Your ' . $this->planName . ' plan has ended — reinstate to keep your academy running',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-reinstatement', with: [
            'ownerName' => $this->ownerName,
            'academyName' => $this->academyName,
            'planName' => $this->planName,
            'endedOn' => $this->endedOn,
            'freezesOn' => $this->freezesOn,
            'daysLeft' => $this->daysLeft,
            'billingUrl' => $this->billingUrl,
        ]);
    }
}
