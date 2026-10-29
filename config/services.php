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
    'fonnte' => ['token' => env('FONNTE_TOKEN')],

    // QRIS lewat Midtrans Core API. Kosongkan MIDTRANS_SERVER_KEY untuk mode DEMO.
    'midtrans' => [
        'server_key'     => env('MIDTRANS_SERVER_KEY'),
        'is_production'  => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'acquirer'       => env('MIDTRANS_QRIS_ACQUIRER', 'gopay'),
        'expiry_minutes' => (int) env('MIDTRANS_QRIS_EXPIRY', 15),
    ],
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

];
