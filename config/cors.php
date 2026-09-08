<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'admin/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Fail-closed: with no CORS_ALLOWED_ORIGINS set the list is EMPTY (no
    // cross-origin access), never '*'. A wildcard combined with
    // supports_credentials below is unsafe, so the real frontend origin(s)
    // must be listed explicitly, comma-separated, in the environment, e.g.
    //   CORS_ALLOWED_ORIGINS=https://jorsastech.com,https://www.jorsastech.com
    // For tenant subdomains, prefer an anchored regex in
    // allowed_origins_patterns over widening this list.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    // Subdomain admission, two sources, both hardened:
    //
    // 1. An anchored regex DERIVED from the frontend URL (App\Support\Cors::
    //    subdomainPattern): a frontend at https://jorsastech.com automatically
    //    admits https://{academy}.jorsastech.com. Read from the same env keys
    //    as config/saas.php 'frontend_url' (config files load alphabetically,
    //    so saas.php is NOT available here yet).
    // 2. The optional CORS_ALLOWED_ORIGINS_PATTERN env override — but ONLY if
    //    it compiles. A malformed regex here makes preg_match() emit a warning
    //    that Laravel converts to an ErrorException, so every request whose
    //    Origin is not an exact allowed_origins match 500s with no CORS
    //    headers (the browser then reports it as a CORS failure; the apex
    //    domain keeps working because exact matches short-circuit before the
    //    pattern loop). patternCompiles() drops such values instead of
    //    letting them take the site down.
    'allowed_origins_patterns' => array_values(array_filter([
        \App\Support\Cors::patternCompiles((string) env('CORS_ALLOWED_ORIGINS_PATTERN'))
            ? env('CORS_ALLOWED_ORIGINS_PATTERN')
            : null,
        \App\Support\Cors::subdomainPattern(
            rtrim((string) env('LMS_BASE_URL', env('FRONTEND_URL', '')), '/')
        ),
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
