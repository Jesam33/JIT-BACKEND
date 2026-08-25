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

];
