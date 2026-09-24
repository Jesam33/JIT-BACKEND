<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every deactivate / delete / reactivate notice, for people and for academies.
 *
 * One class rather than eight, because all eight events are the same message with
 * different words: a heading, a few plain sentences explaining what the change
 * means, an optional date, and a link. Keeping the copy in one `match` below is
 * the point — the wording IS the feature here (the request was explicitly for
 * descriptions of what each action means), and eight near-identical Mailables
 * would let the wording drift apart between them.
 *
 * Two senders, decided by branding rather than by a flag:
 *
 *   - A PERSON's notice is sent to them by their academy, so it carries the
 *     academy's name and accent and routes support to the academy
 *     (tenantId = the person's academy).
 *   - An ACADEMY notice is sent by Jorsas to the owner, so tenantId is null and
 *     the platform identity is used. The academy's own name still appears in the
 *     copy, because that is the subject of the message.
 *
 * Deliberately no "verify your identity" or "click here to undo" links: the undo
 * lives behind a signed-in session, so the CTA points at the portal and the
 * person signs in to act. An emailed undo link would be a second, weaker
 * credential for the most destructive action in the product.
 */
class AccountLifecycleMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, string>       $paragraphs
     * @param array{title: string, body: string}|null $notice
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public ?string $subtitle,
        public string $greeting,
        public array $paragraphs,
        public ?string $ctaLabel = null,
        public ?string $ctaUrl = null,
        public ?array $notice = null,
        public ?string $footerNote = null,
    ) {
    }

    /**
     * Build the notice for one event.
     *
     * $kind is one of: deactivated, reactivated, deletion_scheduled,
     * deletion_cancelled, academy_deactivated, academy_reactivated,
     * academy_deletion_scheduled, academy_deletion_cancelled.
     *
     * $ctx carries: name (the person or the academy, whoever is being written to
     * or about), academy (the academy's display name), tenant_id, purge_after (a
     * human date string), url (where the CTA goes). Every key is optional except
     * academy; a missing name falls back to "there".
     */
    public static function make(string $kind, array $ctx = []): self
    {
        $name = trim((string) ($ctx['name'] ?? '')) ?: 'there';
        $academy = trim((string) ($ctx['academy'] ?? '')) ?: 'your academy';
        $date = (string) ($ctx['purge_after'] ?? '');
        $url = (string) ($ctx['url'] ?? '');

        $copy = match ($kind) {
            'deactivated' => [
                'subject' => 'Your account has been deactivated',
                'heading' => 'Your account has been deactivated',
                'subtitle' => 'You can restore it at any time',
                'paragraphs' => [
                    "Your account at {$academy} has been deactivated.",
                    'Deactivating pauses your access. Nothing has been deleted: your enrolments, attendance, submitted work and certificates are all exactly as you left them.',
                    "To use the portal again, sign in and choose Reactivate, or contact {$academy} and they can restore it for you.",
                ],
                'cta' => 'Sign in',
                'notice' => null,
                'tenant' => true,
            ],

            'reactivated' => [
                'subject' => 'Your account has been reactivated',
                'heading' => 'Your account has been reactivated',
                'subtitle' => 'You have full access again',
                'paragraphs' => [
                    "Your account at {$academy} is active again.",
                    'Nothing was ever removed, so you can sign in and pick up exactly where you left off.',
                ],
                'cta' => 'Go to your dashboard',
                'notice' => null,
                'tenant' => true,
            ],

            'deletion_scheduled' => [
                'subject' => 'Your account is scheduled for deletion',
                'heading' => 'Your account is scheduled for deletion',
                'subtitle' => $date !== '' ? "Restorable until {$date}" : null,
                'paragraphs' => [
                    "Your account at {$academy} is scheduled to be deleted" . ($date !== '' ? " on {$date}" : '') . '.',
                    'Deleting removes your personal details: your name, email address, phone number and photo. Your enrolment, attendance, submitted work and certificates are kept, so your certificate stays valid and ' . $academy . "'s records stay accurate.",
                    $date !== ''
                        ? "Nothing has been removed yet. You can cancel this any time before {$date} by signing in and choosing Cancel deletion."
                        : 'Nothing has been removed yet, and you can cancel this by signing in and choosing Cancel deletion.',
                ],
                'cta' => 'Sign in to cancel',
                'notice' => [
                    'title' => $date !== '' ? "What happens on {$date}" : 'What happens then',
                    'body' => 'Your personal details are removed and you can no longer sign in. Your enrolment, attendance and certificates are not deleted.',
                ],
                'tenant' => true,
            ],

            'deletion_cancelled' => [
                'subject' => 'Your scheduled deletion has been cancelled',
                'heading' => 'Your scheduled deletion has been cancelled',
                'subtitle' => 'Your account is active again',
                'paragraphs' => [
                    "The scheduled deletion of your account at {$academy} has been cancelled.",
                    'Nothing was removed, and your account is active. You can sign in as usual.',
                ],
                'cta' => 'Go to your dashboard',
                'notice' => null,
                'tenant' => true,
            ],

            'academy_deactivated' => [
                'subject' => 'Your academy has been deactivated',
                'heading' => 'Your academy has been deactivated',
                'subtitle' => 'Your students keep learning',
                'paragraphs' => [
                    "{$academy} has been deactivated by the Jorsas Tech team.",
                    'Your students and staff keep full access. Classes, materials, tasks and chats carry on exactly as before.',
                    'What stops is the public page and new enrolments: your academy is no longer listed and no new student can register while it is deactivated.',
                    'If you think this was a mistake, reply to this email and we will look into it.',
                ],
                'cta' => null,
                'notice' => null,
                'tenant' => false,
            ],

            'academy_reactivated' => [
                'subject' => 'Your academy has been reactivated',
                'heading' => 'Your academy has been reactivated',
                'subtitle' => 'You are open for enrolments again',
                'paragraphs' => [
                    "{$academy} is active again.",
                    'Your public page is back online and you can take new student enrolments. Nothing was removed while it was deactivated.',
                ],
                'cta' => null,
                'notice' => null,
                'tenant' => false,
            ],

            'academy_deletion_scheduled' => [
                'subject' => "{$academy} is scheduled for deletion",
                'heading' => 'Your academy is scheduled for deletion',
                'subtitle' => $date !== '' ? "Restorable until {$date}" : null,
                'paragraphs' => [
                    "{$academy} is scheduled to be deleted" . ($date !== '' ? " on {$date}" : '') . '.',
                    'Your students and staff keep full access until then, and nothing has been removed yet.',
                    $date !== ''
                        ? "If this is not what you want, reply to this email before {$date} and we will cancel it."
                        : 'If this is not what you want, reply to this email and we will cancel it.',
                ],
                'cta' => null,
                'notice' => [
                    'title' => $date !== '' ? "What happens on {$date}" : 'What happens then',
                    'body' => 'Your academy is closed and can no longer be signed into. Contact us before then if you want to keep it.',
                ],
                'tenant' => false,
            ],

            'academy_deletion_cancelled' => [
                'subject' => "The deletion of {$academy} has been cancelled",
                'heading' => 'Your academy will not be deleted',
                'subtitle' => 'Nothing was removed',
                'paragraphs' => [
                    "The scheduled deletion of {$academy} has been cancelled.",
                    'Your academy is unchanged, and your students and staff can carry on as normal.',
                ],
                'cta' => null,
                'notice' => null,
                'tenant' => false,
            ],

            default => throw new \InvalidArgumentException("Unknown account lifecycle email kind: {$kind}"),
        };

        // Academy mail is branded as the academy; platform mail (the host writing
        // to an owner) resolves no tenant and so falls back to the platform
        // identity, which is exactly what the layout's platformMail block keys on.
        $mail = new self(
            subjectLine: $copy['subject'],
            heading: $copy['heading'],
            subtitle: $copy['subtitle'] ?? null,
            greeting: $name,
            paragraphs: array_values($copy['paragraphs']),
            ctaLabel: $copy['cta'] ?? null,
            ctaUrl: ($copy['cta'] ?? null) ? $url : null,
            notice: $copy['notice'] ?? null,
            footerNote: $copy['tenant']
                ? "You're receiving this because you have an account at {$academy}."
                : "You're receiving this because you own an academy on Jorsas Tech.",
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
        return new Content(view: 'emails.account-lifecycle');
    }
}
