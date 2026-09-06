<?php

namespace App\Mail;

use App\Models\CeoForum;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

/**
 * The email an institute owner receives about a CEO's Forum, sent by the
 * `lms:send-ceo-forum-emails` sweep in two modes: `invite` (when the forum is
 * first scheduled) and `reminder` (about an hour before it starts).
 *
 * Unlike {@see LmsNotificationMail}, this speaks with the FULL Jorsas identity,
 * name and address both on the platform. It is Jorsas talking to its own
 * customers (the CEOs), so there is deliberately no per-institute Reply-To.
 *
 * A minimal VCALENDAR (.ics) is attached so a single tap adds the session to
 * the owner's calendar. Plain scalars only, so it is cheap to build in a loop.
 * Not ShouldQueue: the sweep command is the deferral mechanism (mail is sync).
 */
class CeoForumMail extends Mailable
{
    use Queueable, SerializesModels;

    public const MODE_INVITE = 'invite';
    public const MODE_REMINDER = 'reminder';

    public function __construct(
        public string $mode,
        public string $ownerName,
        public string $forumTitle,
        public ?string $forumTopic,
        public string $forumHost,
        public string $whenLine,
        public int $durationMinutes,
        public string $joinUrl,
        // Calendar payload, kept as scalars so the mailable serialises cleanly.
        public string $icsUid,
        public int $startTimestamp,
        public int $endTimestamp,
    ) {
    }

    /**
     * Build a CeoForumMail from a forum + a resolved owner, for one delivery mode.
     */
    public static function forOwner(string $mode, CeoForum $forum, string $ownerName, string $joinUrl): self
    {
        $start = $forum->scheduled_at;
        $end = $forum->endsAt();

        // Server time, spelled out, then the timezone so the owner can convert.
        $whenLine = $start->format('l, F j, Y \a\t g:i A') . ' (' . $start->format('T') . ')';

        return new self(
            mode: $mode,
            ownerName: $ownerName,
            forumTitle: $forum->title,
            forumTopic: $forum->topic,
            forumHost: $forum->host_name ?: 'Jorsas Tech',
            whenLine: $whenLine,
            durationMinutes: (int) ($forum->duration_minutes ?: 60),
            joinUrl: $joinUrl,
            icsUid: 'ceo-forum-' . $forum->id . '@jorsastech',
            startTimestamp: $start->getTimestamp(),
            endTimestamp: $end->getTimestamp(),
        );
    }

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('mail.from.name') ?: 'Jorsas Tech');

        $subject = $this->mode === self::MODE_REMINDER
            ? 'Starting soon: ' . $this->forumTitle
            : "You're invited: " . $this->forumTitle;

        return new Envelope(
            from: $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ceo-forum', with: [
            'mode' => $this->mode,
            'ownerName' => $this->ownerName,
            'forumTitle' => $this->forumTitle,
            'forumTopic' => $this->forumTopic,
            'forumHost' => $this->forumHost,
            'whenLine' => $this->whenLine,
            'durationMinutes' => $this->durationMinutes,
            'joinUrl' => $this->joinUrl,
        ]);
    }

    /**
     * Attach a minimal VCALENDAR so the owner can add the session to a calendar.
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->buildIcs(), 'ceo-forum.ics')
                ->withMime('text/calendar'),
        ];
    }

    private function buildIcs(): string
    {
        $fmt = fn (int $ts) => gmdate('Ymd\THis\Z', $ts);
        $escape = fn (string $s) => str_replace(
            ["\\", ";", ",", "\r\n", "\n"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n"],
            $s
        );

        $summary = $escape('CEO\'s Forum: ' . $this->forumTitle);
        $description = $escape(trim(($this->forumTopic ?? '') . "\n\nHost: " . $this->forumHost . "\nJoin: " . $this->joinUrl));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Jorsas Tech//CEO Forum//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->icsUid,
            'DTSTAMP:' . $fmt(time()),
            'DTSTART:' . $fmt($this->startTimestamp),
            'DTEND:' . $fmt($this->endTimestamp),
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'URL:' . $this->joinUrl,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }
}
