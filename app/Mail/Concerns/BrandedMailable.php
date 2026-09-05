<?php

namespace App\Mail\Concerns;

use App\Models\Tenant;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Per-institute identity for a transactional mailable that carries a
 * tenant-scoped model (a registration, an agent). Resolves the sender NAME,
 * brand accent COLOUR and REPLY-TO from the row's tenant_id — so a paying
 * academy's payment/approval emails are stamped with THAT academy, never
 * "Jorsas" — and builds the branded Envelope the same way {@see \App\Mail\LmsNotificationMail}
 * does: the from-ADDRESS stays on the platform's verified domain (so SPF/DKIM/
 * DMARC pass and mail lands in inboxes) while only the display NAME + Reply-To
 * become the institute.
 *
 * The view receives the resolved brand as $brand ({name, color, reply_to}); the
 * template feeds brand.name/brand.color into emails.layout for the header + CTA.
 * A missing/unknown tenant falls back to the platform mail name + red.
 */
trait BrandedMailable
{
    /** @var array{name: string, color: string, reply_to: ?string} */
    public array $brand = [];

    /**
     * Populate $brand from a recipient row's tenant_id. Tolerant of int,
     * numeric-string or null (Eloquent may hand back any of these, and an
     * un-migrated row simply yields null → the platform fallback).
     */
    protected function resolveBrand($tenantId): void
    {
        $this->brand = Tenant::brandMailById(
            ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null
        );
    }

    protected function brandedEnvelope(string $subject): Envelope
    {
        $fromAddress = (string) config('mail.from.address');
        $fromName = trim((string) ($this->brand['name'] ?? '')) !== ''
            ? (string) $this->brand['name']
            : (string) config('mail.from.name');

        $replyTo = [];
        $rt = $this->brand['reply_to'] ?? null;
        if (is_string($rt) && filter_var($rt, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($rt, $fromName !== '' ? $fromName : 'Support');
        }

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            replyTo: $replyTo,
            subject: $subject,
        );
    }
}
