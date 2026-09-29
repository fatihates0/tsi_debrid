<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'realdebrid' => [
        'api_token' => env('REAL_DEBRID_API_TOKEN', ''),
        'base_url' => env('REAL_DEBRID_BASE_URL', 'https://api.real-debrid.com/rest/1.0/'),
        'use_remote' => env('REAL_DEBRID_REMOTE_TRAFFIC', true),
        'proxy' => env('REAL_DEBRID_PROXY', null),
    ],

    'xenforo' => [
        'mode' => env('XENFORO_AUTH_MODE', 'database'),
        'url' => env('XENFORO_URL', 'https://turkcesesindir.com'),
        'allowed_groups' => env('XENFORO_ALLOWED_USER_GROUPS', ''),
        'verify_ssl' => env('XENFORO_VERIFY_SSL', true),
    ],

    'superuser' => [
        'username' => env('SUPERUSER_USERNAME', 'admin'),
        'password' => env('SUPERUSER_PASSWORD', ''),
    ],

    'cron' => [
        'secret' => env('CRON_SECRET', ''),
    ],

];
