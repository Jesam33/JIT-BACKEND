<?php

namespace App\Mail;

use App\Models\TrainingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TrainingRegistration $registration,
        public string $setupLink,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Registration Accepted — Set Up Your LMS Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmation',
        );
    }
}
