<?php

namespace App\Support;

/**
 * Where the transactional-email icons are served from.
 *
 * The icons are PNGs on the frontend's public disk (source of truth:
 * jorsas-tech-v2/public/images/email, rendered from SVG by
 * storage/app/_icongen.mjs). They are PNG rather than SVG deliberately: Gmail
 * strips <svg> entirely and Outlook's Word engine ignores it, so an SVG icon
 * would simply not appear for most recipients.
 *
 * The base is resolved from config and dropped when it points at a development
 * host. A recipient's mail client cannot reach localhost, so every icon would
 * arrive as a broken image, which looks worse than no icon at all. Callers get
 * '' and render the text-only form of the row instead.
 */
class EmailIcons
{
    /**
     * The icons that actually exist on disk. A template naming anything else
     * would render an <img> at a URL that 404s, which arrives as a broken-image
     * icon in the recipient's inbox, so an unknown name resolves to null and the
     * block falls back to its text-only form. New icon: add the SVG to
     * storage/app/_icongen.mjs, regenerate, then add the name here.
     *
     * @var string[]
     */
    public const KNOWN = ['shield', 'mail', 'phone', 'clock', 'info'];

    /**
     * The usable icon base, or '' when icons must be left out.
     */
    public static function base(): string
    {
        return static::usable(rtrim(trim((string) config('saas.email_footer.icons_base', '')), '/'));
    }

    /**
     * Absolute URL for one icon by file name (without the .png), or null when
     * icons are unavailable or the name is not one we ship. Callers treat null
     * as "render without the icon".
     */
    public static function url(string $name): ?string
    {
        $base = static::base();
        if ($base === '') {
            return null;
        }

        $file = ltrim($name, '/');
        if (! in_array(preg_replace('/\.png$/', '', $file), self::KNOWN, true)) {
            return null;
        }

        return $base . '/' . $file;
    }

    /**
     * Guard shared by both entry points: anything pointing at the machine we are
     * running on is unreachable from an inbox, so it counts as "not configured".
     */
    protected static function usable(string $base): string
    {
        if ($base === '' || preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#i', $base)) {
            return '';
        }

        return $base;
    }
}
