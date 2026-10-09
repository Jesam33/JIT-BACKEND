<?php

namespace App\Support;

use App\Models\LmsScheduledClass;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;

/**
 * An .ics calendar invite for one-on-one session emails, so the session lands
 * in Google Calendar / Outlook / Apple Calendar.
 *
 * Attached by the notification email sweep (SendNotificationEmails) to the
 * emails about a private session being booked, moved or cancelled. Each
 * session has a stable UID, and SEQUENCE follows its last update, so a move
 * updates the existing calendar entry and a cancellation removes it instead
 * of adding a duplicate. A weekly series goes out as one file with every
 * upcoming session in it.
 */
class CalendarInvite
{
    /** Notification types that carry an invite (all reference a scheduled_class). */
    public const TYPES = [
        'class_scheduled',
        'class_rescheduled',
        'class_cancelled',
        'one_on_one_booked',
        'one_on_one_series',
        'one_on_one_cancelled',
    ];

    /**
     * The invite for one notification, or null when it doesn't need one.
     */
    public static function forNotification(string $type, ?string $referenceType, ?int $referenceId, string $recipientEmail, string $organizerName, string $url): ?string
    {
        if (! in_array($type, self::TYPES, true) || $referenceType !== 'scheduled_class' || ! $referenceId) {
            return null;
        }

        $session = LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)->find($referenceId);
        if (! $session || ! $session->isPrivate() || ! $session->starts_at) {
            return null;
        }

        $sessions = collect([$session]);
        if ($type === 'one_on_one_series' && $session->series_id) {
            $sessions = LmsScheduledClass::query()->withoutGlobalScope(TenantScope::class)
                ->where('series_id', $session->series_id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('starts_at')
                ->get();
        }

        $cancel = $sessions->count() === 1 && $session->status === 'cancelled';

        return static::build($sessions->all(), $cancel, $recipientEmail, $organizerName, $url);
    }

    /** @param array<int, LmsScheduledClass> $sessions */
    public static function build(array $sessions, bool $cancel, string $recipientEmail, string $organizerName, string $url): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'jorsastech.com';
        $organizerEmail = (string) config('mail.from.address') ?: 'no-reply@' . $host;
        $now = static::utc(now());

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Jorsas Tech//One-on-one sessions//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($cancel ? 'CANCEL' : 'REQUEST'),
        ];

        foreach ($sessions as $s) {
            $end = $s->ends_at ?? $s->starts_at->copy()->addHour();
            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:session-' . $s->id . '@' . $host,
                // Must only ever grow for the same UID; seconds since epoch do.
                'SEQUENCE:' . (int) ($s->updated_at?->timestamp ?? 0),
                'DTSTAMP:' . $now,
                'DTSTART:' . static::utc($s->starts_at),
                'DTEND:' . static::utc($end),
                'SUMMARY:' . static::escape((string) $s->title),
                'DESCRIPTION:' . static::escape('Join from your academy portal: ' . $url),
                'URL:' . $url,
                'LOCATION:' . static::escape('Online (' . $organizerName . ' portal)'),
                'ORGANIZER;CN=' . static::param($organizerName) . ':mailto:' . $organizerEmail,
                'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $recipientEmail,
                'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED'),
            ]);
            if (! $cancel) {
                $lines = array_merge($lines, [
                    'BEGIN:VALARM',
                    'TRIGGER:-PT30M',
                    'ACTION:DISPLAY',
                    'DESCRIPTION:' . static::escape((string) $s->title),
                    'END:VALARM',
                ]);
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([static::class, 'fold'], $lines)) . "\r\n";
    }

    private static function utc(Carbon $at): string
    {
        return $at->copy()->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
    }

    private static function param(string $text): string
    {
        return '"' . str_replace('"', "'", $text) . '"';
    }

    /** RFC 5545: lines longer than 75 octets continue on the next with a space. */
    private static function fold(string $line): string
    {
        // Split on characters (never inside a UTF-8 sequence); continuation
        // lines get 74 octets because the leading space is the 75th.
        $chunks = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current . $char) > $limit) {
                $chunks[] = $current;
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
