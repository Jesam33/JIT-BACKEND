<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\TrainingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrainingRegistrationApprovedMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    public function __construct(public TrainingRegistration $registration, public string $lmsLink)
    {
        // Brand as the academy that approved the registration (from tenant_id).
        $this->resolveBrand($registration->tenant_id);
    }

    public function envelope(): Envelope
    {
        // Academy-neutral subject; the sender NAME carries the institute.
        return $this->brandedEnvelope('Your Training Registration Has Been Approved');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.training-registration-approved',
        );
    }
}
