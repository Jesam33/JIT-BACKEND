<?php

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentApplicationSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Agent $agent, public string $adminUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New Agent Application Received');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-application-submitted');
    }
}
