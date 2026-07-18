<?php

namespace App\Mail;

use App\Models\TrainingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrainingRegistrationSubmittedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TrainingRegistration $registration)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Training Registration Received',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.training-registration-submitted',
        );
    }
}
