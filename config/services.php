<?php

declare(strict_types=1);

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

    'browsershot' => [
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'node_path' => env('BROWSERSHOT_NODE_PATH', '/usr/bin/node'),
        'npm_path' => env('BROWSERSHOT_NPM_PATH'),
        'node_module_path' => env('BROWSERSHOT_NODE_MODULE_PATH'),
    ],

    // AI report builder (CLAUDE.md §10.6). Uses the Claude API.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    // One-click "Connect with Google" (§9). ONE OAuth app in Google Cloud covers GA4,
    // Search Console and Google Ads. The developer token is only needed for Google Ads.
    // Configure these once; clients then connect without pasting any JSON or tokens.
    'google_oauth' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'ads_developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'ads_login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
    ],

    // One-click "Connect with Facebook" (§9): a Meta app with ads_read (App Review).
    'meta_oauth' => [
        'app_id' => env('META_OAUTH_APP_ID'),
        'app_secret' => env('META_OAUTH_APP_SECRET'),
    ],

];
