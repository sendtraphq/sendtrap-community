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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam check (sendtrap/core host requirement)
    |--------------------------------------------------------------------------
    |
    | Sendtrap\Core\Support\SpamCheck reads these keys, each with its own
    | sensible fallback. Disabled by default: Community is offline-first,
    | so spam analysis is on-demand and optional. With this disabled the
    | Spam tab shows a "not configured" state rather than an error, and
    | the app needs no internet connection to install, receive mail, or
    | read it.
    |
    */

    'spamcheck' => [
        'enabled' => env('SPAMCHECK_ENABLED', false),
        'url' => env('SPAMCHECK_URL'),
        'timeout' => env('SPAMCHECK_TIMEOUT', 5),
        'threshold' => env('SPAMCHECK_THRESHOLD', 5.0),
    ],

];
