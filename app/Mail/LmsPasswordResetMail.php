<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
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
