<?php

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentWithdrawalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Agent $agent, public float $amount, public string $adminUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Withdrawal Requested, ' . $this->agent->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-withdrawal-requested');
    }
}
