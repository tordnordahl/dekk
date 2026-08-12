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

    'vegvesen' => [
        'api_key' => env('VEGVESEN_API_KEY'),
        'endpoint' => env('VEGVESEN_API_URL', 'https://akfell-datautlevering.atlas.vegvesen.no/enkeltoppslag/kjoretoydata'),
    ],

    'fiken' => [
        'client_id' => env('FIKEN_CLIENT_ID'),
        'client_secret' => env('FIKEN_CLIENT_SECRET'),
    ],

    'tripletex' => [
        'consumer_token' => env('TRIPLETEX_CONSUMER_TOKEN'),
        'test_consumer_token' => env('TRIPLETEX_TEST_CONSUMER_TOKEN'),
    ],

    'poweroffice' => [
        'app_key' => env('POWEROFFICE_APP_KEY'),
        'subscription_key' => env('POWEROFFICE_SUBSCRIPTION_KEY'),
    ],

    'zettle' => [
        'client_id' => env('ZETTLE_CLIENT_ID'),
        'client_secret' => env('ZETTLE_CLIENT_SECRET'),
    ],

    'sms' => [
        'billing_unit_price_cents' => (int) env('SMS_BILLING_UNIT_PRICE_CENTS', 0),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'price_id' => env('STRIPE_PRICE_ID'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'monthly_price_nok' => 119,
    ],

];
