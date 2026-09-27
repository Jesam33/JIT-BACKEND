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
 * The alert the ACADEMY OWNER gets when somebody applies to be an Admission
 * Marketer at their academy: it tells them an application is sitting in their
 * portal waiting for a decision, and links straight to it.
 *
 * Agents are per academy, and so is the decision, so this is academy mail in both
 * directions: it is addressed to the owner's own address (resolved by
 * AgentController from the tenant, not the platform inbox) and it speaks with the
 * academy's identity (name + accent colour + reply-to + header logo, via
 * {@see BrandedMailable}). An applicant at an academy that never linked an owner
 * row falls back to the platform address, so a submission is never silently
 * unreported.
 */
class AgentApplicationSubmittedMail extends Mailable
{
    use BrandedMailable, Queueable, SerializesModels;

    /**
     * @param string $reviewUrl absolute link into the OWNER's portal, where the
     *                          application is approved or rejected
     */
    public function __construct(public Agent $agent, public string $reviewUrl)
    {
        $this->resolveBrand($agent->tenant_id);
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('New Admission Marketer application');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-application-submitted');
    }
}
