<?php

namespace App\Support;

class Cors
{
    /**
     * Anchored regex admitting every https://{label}.{domain} origin below the
     * frontend's host. A frontend at https://jorsastech.com admits
     * https://pfc.jorsastech.com (any academy subdomain) while staying closed
     * to everything else. Compatible with supports_credentials=true, unlike
     * allowed_origins=['*'].
     *
     * Returns null when the URL is missing, local (IP or localhost), or has no
     * usable host, so dev environments keep whatever explicit list the env
     * provides instead of deriving a meaningless pattern.
     */
    public static function subdomainPattern(?string $frontendUrl): ?string
    {
        if (! $frontendUrl) {
            return null;
        }

        $host = parse_url(rtrim($frontendUrl, '/'), PHP_URL_HOST);

        if (! is_string($host)
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_contains(strtolower($host), 'localhost')) {
            return null;
        }

        $base = preg_replace('/^www\./i', '', $host);

        return '~^https:\/\/[a-z0-9-]+(?:\.[a-z0-9-]+)*\.' . preg_quote($base, '~') . '$~';
    }

    /**
     * True when the regex compiles. An invalid CORS_ALLOWED_ORIGINS_PATTERN
     * must never reach the CORS middleware: preg_match() emits a warning on a
     * malformed pattern, Laravel's error handler turns that into an
     * ErrorException, and every request whose Origin is not an exact
     * allowed_origins match 500s with no CORS headers (the browser then
     * reports a CORS failure). Exact-match origins short-circuit before the
     * pattern loop, which is why the apex domain keeps working while
     * academy subdomains break.
     */
    public static function patternCompiles(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        return @preg_match($pattern, '') !== false;
    }
}
