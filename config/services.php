<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'jitsi' => [
        // 8x8 JaaS (managed Jitsi). App ID is the "magic cookie"; api_key_id is the JWT `kid`;
        // private_key is the RS256 PEM (may be stored base64-encoded on one line in .env).
        'app_id' => env('JITSI_APP_ID'),
        'api_key_id' => env('JITSI_API_KEY_ID'),
        'private_key' => env('JITSI_PRIVATE_KEY'),
        'domain' => env('JITSI_DOMAIN', '8x8.vc'),
    ],

    'gamma' => [
        // Gamma Generate API (developers.gamma.app) — the Pro+ AI training-material
        // generator. `key` is a secret (looks like `sk-gamma-…`); it lives in env
        // only and is NEVER committed. `base` carries the API version segment so a
        // version bump is a one-line env change. Read via config('services.gamma.*')
        // (NEVER env() at the call site) so both survive `php artisan config:cache`.
        // Absent key → GammaService degrades to a clean 503, like the Jitsi path.
        'key' => env('GAMMA_API_KEY'),
        'base' => env('GAMMA_API_BASE', 'https://public-api.gamma.app/v1.0'),
    ],

    'bunny_stream' => [
        // Bunny Stream (video.bunnycdn.com) — external host for pre-recorded lesson
        // videos + video materials (Basic+ feature). Uploaded bytes go browser→Bunny
        // via signed TUS and are NEVER stored on our server or in our DB; we keep only
        // the video guid, a thumbnail URL and the player embed URL. Mirrors the Gamma
        // block: `api_key` is a SECRET (env only, NEVER committed); every value is read
        // via config('services.bunny_stream.*') — NEVER env() at the call site — so it
        // survives `php artisan config:cache`. Absent library_id/api_key →
        // BunnyStreamService degrades to a clean 503, like the Gamma/Jitsi paths.
        //  - library_id   : the Stream video-library id (numeric).
        //  - api_key      : that library's API key (the secret).
        //  - cdn_hostname : the pull-zone host, e.g. `vz-xxxxxxxx-xxx.b-cdn.net`
        //                   (used to build thumbnail + HLS URLs). No scheme.
        //  - collection_id: optional Stream collection to file uploads under.
        'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        'api_key' => env('BUNNY_STREAM_API_KEY'),
        'cdn_hostname' => env('BUNNY_STREAM_CDN_HOSTNAME'),
        'collection_id' => env('BUNNY_STREAM_COLLECTION_ID'),
    ],

    'paystack' => [
        // Payment gateway credentials. `secret_key` is a SECRET (env only, NEVER
        // committed). Like gamma/bunny it MUST be read via config('services.paystack.*'),
        // NEVER env() at the call site, so it survives `php artisan config:cache`:
        // once the config is cached, an env() read returns null → PaystackService
        // reports "not configured" and EVERY payment fails in production. Use LIVE
        // keys (pk_live_… / sk_live_…) in prod; test keys transact only in Paystack's
        // separate test mode (test subaccounts/customers don't exist under live keys).
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

];
