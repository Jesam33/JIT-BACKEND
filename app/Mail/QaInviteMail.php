<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\QaTester;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The QA testing pass: sent to a tester the moment they register, carrying the
 * personal join link that IS their credential.
 *
 * Branded as the event's academy (the iungo tenant), so the sender name and
 * accent colour come from iungo rather than the platform. The collaboration with
 * Jorsas is carried in the body copy instead of the header, because the shared
 * email layout always renders the platform logo and has no per-academy mark
 * (see BrandedMailable) and changing that would touch every other template.
 */
class QaInviteMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public QaTester $tester,
        public string $link,
    ) {
        $this->resolveBrand($tester->tenant_id);
    }

    public function envelope(): Envelope
    {
        $event = $this->tester->event;
        $window = $event?->starts_at?->format('D j M');

        // Naming the day in the subject is the one thing that makes this findable
        // in an inbox a week later, which is exactly when someone will look for it.
        return $this->brandedEnvelope(
            'Your testing pass'
            . ($window ? ' for ' . $window : '')
            . ' - join link inside'
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.qa-invite');
    }
}
