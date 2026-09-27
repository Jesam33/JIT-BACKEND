<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\LmsTeacher;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LmsTeacherCredentialsMail extends Mailable
{
    use BrandedMailable, Queueable, SerializesModels;

    public function __construct(public LmsTeacher $teacher, public string $plainPassword)
    {
        // An academy admin creates the staff account, so the mail is stamped with
        // THAT academy: its sender name, its accent and its own header mark.
        // Before this the view hardcoded "Jorsas Tech", so a new teacher at any
        // academy was told they had a Jorsas account and saw our logo.
        $this->resolveBrand($teacher->tenant_id);
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Your Academic Portal Staff Account Credentials');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lms-teacher-credentials',
        );
    }
}
