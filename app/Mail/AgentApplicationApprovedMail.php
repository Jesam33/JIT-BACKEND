<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentApplicationApprovedMail extends Mailable
{
    use BrandedMailable, Queueable, SerializesModels;

    public function __construct(public Agent $agent, public string $portalUrl, public string $password)
    {
        // Agents are per-tenant: brand the approval as the agent's academy
        // (from tenant_id). Legacy agents with a null tenant fall back to the
        // platform identity, so no send ever breaks.
        $this->resolveBrand($agent->tenant_id);
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Your Agent Application Has Been Approved');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-application-approved');
    }
}
