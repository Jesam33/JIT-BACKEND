<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\TrainingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmationMail extends Mailable
{
    use BrandedMailable, Queueable, SerializesModels;

    public function __construct(
        public TrainingRegistration $registration,
        public string $setupLink,
    ) {
        // Brand the email as the academy the student paid, resolved from the
        // registration's tenant_id — never the platform "Jorsas".
        $this->resolveBrand($registration->tenant_id);
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Registration Accepted — Set Up Your LMS Account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmation',
        );
    }
}
