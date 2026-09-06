<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LmsPasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $name,
        public string $portalLabel,
        public string $resetLink,
        // Per-institute white-label identity so an invite/reset email is branded
        // as the academy that sent it, not the platform. All optional → any
        // un-updated caller still sends a valid (platform-default) email.
        //   $brandName, sender NAME + header wordmark (defaults to mail.from.name)
        //   $brandColor, header/button/link accent hex (defaults to red #ed180d)
        //   $brandReplyTo, where replies route (the institute), when known.
        //     NOTE: must NOT be named $replyTo, Illuminate\Mail\Mailable
        //     already declares an untyped `public $replyTo = []`, and PHP 8.4
        //     fatals ("Type of ... $replyTo must not be defined") if a subclass
        //     re-declares that inherited property WITH a type. That fatal fires
        //     at class-load (before any try/catch), killing the request with a
        //     header-less 500 that the browser misreports as a CORS failure.
        public ?string $brandName = null,
        public ?string $brandColor = null,
        public ?string $brandReplyTo = null,
    ) {
    }

    public function envelope(): Envelope
    {
        // Sender identity mirrors LmsNotificationMail: keep the verified
        // from-ADDRESS (so SPF/DKIM/DMARC pass and mail lands in inboxes) but
        // show the INSTITUTE's display name, a student invited by "Perka
        // Foundation Class" sees that academy, never "Jorsas". Replies route to
        // the institute via Reply-To when a valid address is known.
        $fromAddress = (string) config('mail.from.address');
        $fromName = trim((string) $this->brandName) !== ''
            ? (string) $this->brandName
            : (string) config('mail.from.name');

        $replyTo = [];
        if ($this->brandReplyTo && filter_var($this->brandReplyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($this->brandReplyTo, $fromName ?: 'Support');
        }

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            replyTo: $replyTo,
            subject: $this->portalLabel . ' Password Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lms-password-reset',
        );
    }
}
