<?php

namespace App\Mail;

use App\Mail\Concerns\BrandedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A student has reported their academy. Goes to the PLATFORM, never to the
 * academy — the academy being reported is the subject of the message, not its
 * recipient, so `resolveBrand(null)` is called explicitly and this always sends
 * under the platform identity.
 *
 * The academy's own name still appears in the body, because that is what the
 * platform needs to read. The reply-to is left as the platform's, NOT the
 * student's: replying to a report should reach the team handling it, and putting
 * a student's address in reply-to would let a mis-aimed reply go straight back to
 * someone who asked for help.
 */
class AcademyReportMail extends Mailable
{
    use BrandedMailable;
    use Queueable;
    use SerializesModels;

    /**
     * @param array{academy: string, student: string, student_email: string, category: string, details: string, url: string} $ctx
     */
    public function __construct(
        public string $academy,
        public string $student,
        public string $studentEmail,
        public string $categoryLabel,
        public string $details,
        public string $ctaUrl,
    ) {
    }

    public static function make(array $ctx): self
    {
        $mail = new self(
            academy: (string) ($ctx['academy'] ?? 'Unknown academy'),
            student: (string) ($ctx['student'] ?? 'Unknown student'),
            studentEmail: (string) ($ctx['student_email'] ?? ''),
            categoryLabel: (string) ($ctx['category'] ?? ''),
            details: (string) ($ctx['details'] ?? ''),
            ctaUrl: (string) ($ctx['url'] ?? ''),
        );

        // null => platform identity. See the class docblock.
        $mail->resolveBrand(null);

        return $mail;
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Academy reported: {$this->academy}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.academy-report');
    }
}
