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

    'pwned_passwords' => [
        'ca_bundle' => env('PWNED_PASSWORDS_CA_BUNDLE'),
    ],

    'google_ads' => [
        'version' => env('BB2_GOOGLE_ADS_VERSION'),
        'client_id' => env('BB2_GOOGLE_CLIENT_ID'),
        'client_secret' => env('BB2_GOOGLE_CLIENT_SECRET'),
        // Optional legacy header; access levels are assigned to the OAuth client's Cloud project.
        'developer_token' => env('BB2_GOOGLE_DEVELOPER_TOKEN', ''),
        'redirect_uri' => env('BB2_GOOGLE_REDIRECT_URI'),
        'privacy_url' => env('BB2_GOOGLE_PRIVACY_URL'),
        'notice_revision' => env('BB2_GOOGLE_NOTICE_REVISION'),
    ],

    'tiktok_ads' => [
        'version' => env('BB2_TIKTOK_API_VERSION'),
        'app_id' => env('BB2_TIKTOK_APP_ID'),
        'app_secret' => env('BB2_TIKTOK_APP_SECRET'),
        'authorization_url' => env('BB2_TIKTOK_AUTHORIZATION_URL'),
        'redirect_uri' => env('BB2_TIKTOK_REDIRECT_URI'),
        'privacy_url' => env('BB2_TIKTOK_PRIVACY_URL'),
        'notice_revision' => env('BB2_TIKTOK_NOTICE_REVISION'),
    ],

    'snapchat_ads' => [
        'client_id' => env('BB2_SNAPCHAT_CLIENT_ID'),
        'client_secret' => env('BB2_SNAPCHAT_CLIENT_SECRET'),
        'redirect_uri' => env('BB2_SNAPCHAT_REDIRECT_URI'),
        'privacy_url' => env('BB2_SNAPCHAT_PRIVACY_URL'),
        'notice_revision' => env('BB2_SNAPCHAT_NOTICE_REVISION'),
    ],

    'x_ads' => [
        'consumer_key' => env('BB2_X_CONSUMER_KEY'),
        'consumer_secret' => env('BB2_X_CONSUMER_SECRET'),
        'redirect_uri' => env('BB2_X_REDIRECT_URI'),
        'privacy_url' => env('BB2_X_PRIVACY_URL'),
        'notice_revision' => env('BB2_X_NOTICE_REVISION'),
    ],

    'meta_ads' => [
        'version' => env('BB2_META_GRAPH_VERSION'),
        'app_id' => env('BB2_META_APP_ID'),
        'app_secret' => env('BB2_META_APP_SECRET'),
        'redirect_uri' => env('BB2_META_REDIRECT_URI'),
        'configuration_id' => env('BB2_META_CONFIGURATION_ID'),
        'token_kind' => env('BB2_META_TOKEN_KIND', 'BUSINESS_INTEGRATION_SYSTEM_USER'),
        'privacy_url' => env('BB2_META_PRIVACY_URL'),
        'deletion_instructions_url' => env('BB2_META_DELETION_INSTRUCTIONS_URL'),
        'notice_revision' => env('BB2_META_NOTICE_REVISION'),
    ],

];
