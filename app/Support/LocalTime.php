<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Times in notifications, in each READER's own timezone.
 *
 * A notification is written once and read by many people in different
 * timezones, so its text never contains a ready-made time. It carries a marker,
 * `[[time:2026-10-14T21:50:00Z|time]]` (UTC + a style), that's turned into a
 * time where it's read:
 *   - in the portal, by the reader's own device (frontend lib/local-time.ts);
 *   - in the email, by render() with the recipient's timezone: the one saved
 *     from their device (lms_students / lms_teachers .timezone), else their
 *     academy's (Tenant::timezone(), from its country), else the platform's.
 *
 * Styles: datetime "Wed 14 Oct, 10:50pm WAT" · time "10:50pm WAT" ·
 * date "14 Oct" · weekly "Wednesday at 10:50pm WAT".
 */
class LocalTime
{
    private const PATTERN = '/\[\[time:([0-9T:\-+.Z]+)\|(datetime|time|date|weekly)\]\]/';

    private const FORMATS = [
        'datetime' => 'D j M, g:ia T',
        'time' => 'g:ia T',
        'date' => 'j M',
        'weekly' => 'l \a\t g:ia T',
    ];

    /** Usual timezone for each country academies sell from. */
    private const COUNTRY_TZ = [
        'NG' => 'Africa/Lagos', 'GH' => 'Africa/Accra', 'KE' => 'Africa/Nairobi', 'UG' => 'Africa/Kampala',
        'TZ' => 'Africa/Dar_es_Salaam', 'RW' => 'Africa/Kigali', 'ZA' => 'Africa/Johannesburg', 'ZM' => 'Africa/Lusaka',
        'MW' => 'Africa/Blantyre', 'CM' => 'Africa/Douala', 'CI' => 'Africa/Abidjan', 'SN' => 'Africa/Dakar',
        'EG' => 'Africa/Cairo', 'US' => 'America/New_York', 'GB' => 'Europe/London', 'IE' => 'Europe/Dublin',
        'DE' => 'Europe/Berlin', 'FR' => 'Europe/Paris', 'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels',
        'ES' => 'Europe/Madrid', 'IT' => 'Europe/Rome', 'PT' => 'Europe/Lisbon', 'AT' => 'Europe/Vienna',
        'FI' => 'Europe/Helsinki',
    ];

    /** The marker to put in notification text in place of a time. */
    public static function marker(?Carbon $at, string $style = 'datetime'): string
    {
        if (! $at) {
            return '';
        }
        $style = array_key_exists($style, self::FORMATS) ? $style : 'datetime';

        return '[[time:' . $at->copy()->utc()->format('Y-m-d\TH:i:s\Z') . '|' . $style . ']]';
    }

    /** Replace every marker in $text with the time in $timezone. Text without markers is returned as is. */
    public static function render(?string $text, ?string $timezone): string
    {
        $text = (string) $text;
        if (! str_contains($text, '[[time:')) {
            return $text;
        }
        $tz = static::valid($timezone) ? $timezone : static::platformTimezone();

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($tz) {
            try {
                return Carbon::parse($m[1])->setTimezone($tz)->format(self::FORMATS[$m[2]]);
            } catch (\Throwable) {
                return $m[0];
            }
        }, $text);
    }

    /** A person's timezone: their own (saved from their device), else their academy's. */
    public static function forPerson(?string $own, ?int $tenantId): string
    {
        if (static::valid($own)) {
            return (string) $own;
        }
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        return $tenant ? $tenant->timezone() : static::platformTimezone();
    }

    /** Default timezone for an academy in this country. */
    public static function forCountry(?string $country): ?string
    {
        return self::COUNTRY_TZ[strtoupper((string) $country)] ?? null;
    }

    public static function platformTimezone(): string
    {
        $tz = (string) config('saas.monthly_reminder_timezone', 'Africa/Lagos');

        return static::valid($tz) ? $tz : 'Africa/Lagos';
    }

    public static function valid(?string $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
