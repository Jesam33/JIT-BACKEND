<?php

/*
 * Trusted proxies (read by Laravel's TrustProxies middleware, which is in the
 * global stack).
 *
 * If the live server sits behind a proxy (Cloudflare, a load balancer, a
 * hosting panel's reverse proxy), every request arrives FROM the proxy, so the
 * app sees the proxy's IP for everyone: login alerts and the devices list then
 * show the wrong IP, and per-IP rate limits throttle all users together.
 * Trusting the proxy makes Laravel read the real visitor IP from the
 * X-Forwarded-For header instead.
 *
 * TRUSTED_PROXIES:
 *   (unset)        trust nothing: correct when nothing sits in front of the app
 *   *              trust the immediate proxy, whatever its IP (typical behind
 *                  Cloudflare or a managed load balancer)
 *   10.0.0.1,...   trust only these proxy IPs / CIDR ranges
 *
 * Only set it when there really is a proxy: trusting one that isn't there lets
 * a visitor fake their IP with their own X-Forwarded-For header.
 * Read through config, so it survives `php artisan config:cache`.
 */

$proxies = env('TRUSTED_PROXIES');

return [
    'proxies' => match (true) {
        $proxies === null, $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', (string) $proxies)))),
    },
];
