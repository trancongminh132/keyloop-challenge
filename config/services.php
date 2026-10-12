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

    'dealership_documents' => [
        'sales_url' => env(
            'SALES_SYSTEM_URL',
            env('APP_URL', 'http://127.0.0.1:8000').'/api/mock/sales'
        ),
        'service_url' => env(
            'SERVICE_SYSTEM_URL',
            env('APP_URL', 'http://127.0.0.1:8000').'/api/mock/service'
        ),
        'timeout' => (int) env('DOCUMENT_API_TIMEOUT', 2),
        'mocks' => [
            'sales' => [
                'scenario' => env('MOCK_SALES_SCENARIO', 'success'),
                'delay_ms' => (int) env('MOCK_SALES_DELAY_MS', 0),
            ],
            'service' => [
                'scenario' => env('MOCK_SERVICE_SCENARIO', 'success'),
                'delay_ms' => (int) env('MOCK_SERVICE_DELAY_MS', 0),
            ],
        ],
    ],

];
