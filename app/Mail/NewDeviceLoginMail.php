<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We noticed a new sign-in" — sent when an account signs in from a device we
 * have not seen before (see LoginDeviceTracker).
 *
 * Branded as the person's academy, because that is who they think they have an
 * account with; a student of an academy has never heard of Jorsas Tech, and an
 * unrecognised sender on a security email is exactly when a warning gets
 * dismissed as phishing.
 *
 * Deliberately carries NO action link that grants anything. The CTA points at the
 * profile page, where the real controls live (change password, sign out devices)
 * behind a signed-in session. An emailed "click here to secure your account" link
 * is the single most-phished pattern there is, and this email's whole job is to be
 * trustworthy.
 */
class NewDeviceLoginMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    /**
     * @param array{name: string, academy: string, tenant_id: ?int, device: string, ip: string, time: string, url: string} $ctx
     */
    public function __construct(
        public string $greeting,
        public string $academy,
        public string $device,
        public string $ip,
        public string $when,
        public string $ctaUrl,
    ) {
    }

    public static function make(array $ctx): self
    {
        $mail = new self(
            greeting: trim((string) ($ctx['name'] ?? '')) ?: 'there',
            academy: trim((string) ($ctx['academy'] ?? '')) ?: 'your academy',
            device: (string) ($ctx['device'] ?? 'Unknown device'),
            ip: (string) ($ctx['ip'] ?? 'unknown'),
            when: (string) ($ctx['time'] ?? ''),
            ctaUrl: (string) ($ctx['url'] ?? ''),
        );

        $mail->resolveBrand($ctx['tenant_id'] ?? null);

        return $mail;
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('New sign-in to your account');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-device-login');
    }
}
