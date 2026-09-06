<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email sent for one in-app notification (student/staff/agent) or a
 * platform announcement, by the `lms:send-notification-emails` sweep.
 *
 * Plain scalars only (no models) so it is cheap to build in a loop and carries
 * no tenant-scoped model that could resolve against the wrong tenant. The CTA
 * deep-links straight into the LMS via App\Support\NotificationLinks. Not
 * ShouldQueue: the sweep command is the deferral mechanism, mail is sent inline.
 */
class LmsNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $greetingName,
        public string $notifTitle,
        public string $notifBody,
        public string $actionUrl,
        public string $instituteName,
        public ?string $instituteReplyTo = null,
        // The academy's brand accent hex, themes the email header + CTA so an
        // announcement/notification matches its storefront (defaults to red in
        // the layout when null). View-only; the envelope doesn't use it.
        public ?string $instituteColor = null,
        public string $actionLabel = 'Open in the LMS',
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = trim($this->notifTitle) !== ''
            ? $this->notifTitle
            : trim($this->instituteName . ' notification');

        // Sender identity is per-institute WITHOUT spoofing the from-address:
        // the address stays on the platform's verified domain (SPF/DKIM/DMARC
        // pass, so it lands in inboxes), only the display NAME is the institute
        //, so a student sees "Brightstone Academy", not "Jorsas". Replies are
        // routed to the institute via Reply-To (its public contact email, or
        // the owner's login email) when one is known.
        $fromAddress = (string) config('mail.from.address');
        $fromName = trim($this->instituteName) !== ''
            ? $this->instituteName
            : (string) config('mail.from.name');

        $replyTo = [];
        if ($this->instituteReplyTo && filter_var($this->instituteReplyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($this->instituteReplyTo, $fromName);
        }

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            replyTo: $replyTo,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lms-notification');
    }
}
