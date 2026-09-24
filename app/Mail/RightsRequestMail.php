<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Both halves of a data-rights request: the notice to the platform that one has
 * arrived, and the answer back to the person who made it.
 *
 * One class with a `kind` switch rather than two, because both are the same
 * message shape (a heading, a few sentences, the request's own words, an optional
 * CTA) and keeping them together stops the two halves of one conversation from
 * drifting into different voices.
 *
 * The two kinds deliberately brand DIFFERENTLY:
 *   - `submitted` goes to the platform, so `resolveBrand(null)` — the academy is
 *     the subject, not the sender.
 *   - `answered` goes to the person, branded as their academy, because that is who
 *     they have an account with.
 */
class RightsRequestMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, string> $paragraphs
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public string $greeting,
        public array $paragraphs,
        public ?string $quoteLabel,
        public ?string $quote,
        public ?string $ctaLabel,
        public ?string $ctaUrl,
        public string $footerNote,
    ) {
    }

    /**
     * $kind is `submitted` or `answered`.
     *
     * $ctx carries: name, academy, tenant_id, type (the human label), details (the
     * requester's own words), status, response (the platform's note), url.
     */
    public static function make(string $kind, array $ctx = []): self
    {
        $name = trim((string) ($ctx['name'] ?? '')) ?: 'there';
        // Kept EMPTY when there is no academy rather than defaulted to a phrase:
        // an owner's request can arrive with no tenant, and "Ada at your academy
        // has submitted…" reads as a template that was not finished. The copy
        // below branches on it instead.
        $academy = trim((string) ($ctx['academy'] ?? ''));
        $atAcademy = $academy !== '' ? " at {$academy}" : '';
        $type = (string) ($ctx['type'] ?? 'request');
        $details = trim((string) ($ctx['details'] ?? ''));
        $response = trim((string) ($ctx['response'] ?? ''));
        $url = (string) ($ctx['url'] ?? '');

        $copy = match ($kind) {
            'submitted' => [
                'subject' => $academy !== '' ? "New rights request from {$academy}" : 'New rights request',
                'heading' => 'A rights request has been submitted',
                'greeting' => 'there',
                'paragraphs' => [
                    "{$name}{$atAcademy} has submitted a data rights request.",
                    "Type: {$type}.",
                    'It is waiting in the platform queue. Reply to the requester from there once you have decided.',
                ],
                'quote_label' => 'What they asked for',
                'quote' => $details,
                'cta' => 'Open the request queue',
                'cta_url' => $url !== '' ? $url : null,
                'footer' => 'You are receiving this because you administer Jorsas Tech.',
                'tenant' => null,
            ],

            'answered' => [
                'subject' => 'Your data request has been answered',
                'heading' => 'Your request has been answered',
                'greeting' => $name,
                'paragraphs' => array_values(array_filter([
                    "Jorsas Tech has responded to your {$type} request about your account{$atAcademy}.",
                    $response !== '' ? $response : null,
                    'If anything here is unclear, you can reply to this email.',
                ])),
                'quote_label' => 'What you asked for',
                'quote' => $details,
                'cta' => null,
                'cta_url' => null,
                'footer' => $academy !== ''
                    ? "You're receiving this because you made a data request at {$academy}."
                    : "You're receiving this because you made a data request to Jorsas Tech.",
                'tenant' => true,
            ],

            default => throw new \InvalidArgumentException("Unknown rights request email kind: {$kind}"),
        };

        $mail = new self(
            subjectLine: $copy['subject'],
            heading: $copy['heading'],
            greeting: $copy['greeting'],
            paragraphs: $copy['paragraphs'],
            quoteLabel: $copy['quote_label'],
            quote: ($copy['quote'] ?? '') !== '' ? $copy['quote'] : null,
            ctaLabel: $copy['cta'] ?? null,
            ctaUrl: $copy['cta_url'] ?? null,
            footerNote: $copy['footer'],
        );

        $mail->resolveBrand($copy['tenant'] ? ($ctx['tenant_id'] ?? null) : null);

        return $mail;
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rights-request');
    }
}
