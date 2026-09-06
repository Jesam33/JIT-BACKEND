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

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
