<?php

namespace App\Mail;

use App\Models\LmsTeacher;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LmsTeacherCredentialsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public LmsTeacher $teacher, public string $plainPassword)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LMS Staff Account Credentials',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lms-teacher-credentials',
        );
    }
}
