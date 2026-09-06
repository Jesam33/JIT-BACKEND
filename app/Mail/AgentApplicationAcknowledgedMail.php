<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The receipt an applicant gets the moment they submit an Admission Marketer
 * application, before any review. It confirms we have their details and tells
 * them what happens next, no credentials yet (those ride in
 * {@see AgentApplicationApprovedMail} once an admin approves).
 *
 * Agents are per academy, so this speaks with the applicant's academy identity
 * (name + accent colour + reply-to resolved from the agent's tenant_id via
 * {@see BrandedMailable}); a legacy agent with a null tenant falls back to the
 * platform identity, so a send never breaks.
 */
class AgentApplicationAcknowledgedMail extends Mailable
{
    use BrandedMailable, Queueable, SerializesModels;

    public function __construct(public Agent $agent)
    {
        $this->resolveBrand($agent->tenant_id);
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('We received your Admission Marketer application');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-application-acknowledged');
    }
}
