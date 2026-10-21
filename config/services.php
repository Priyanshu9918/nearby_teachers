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

    'nearby_teacher' => [
        'otp_test_mode' => env('OTP_TEST_MODE', false),
        'jwt_secret' => env('JWT_SECRET'),
        'firebase_service_account_json' => env('FIREBASE_SERVICE_ACCOUNT_JSON'),
        'app_base_url' => env('APP_BASE_URL', 'https://bsl.international'),
        'ios_app_store_url' => env('IOS_APP_STORE_URL'),
        'android_play_store_url' => env('ANDROID_PLAY_STORE_URL'),
        'support_email' => env('SUPPORT_EMAIL'),
        'msg91_auth_key' => env('MSG91_AUTH_KEY'),
        'msg91_sender_id' => env('MSG91_SENDER_ID'),
        'msg91_template_id' => env('MSG91_TEMPLATE_ID'),
        'twilio_account_sid' => env('TWILIO_ACCOUNT_SID'),
        'twilio_auth_token' => env('TWILIO_AUTH_TOKEN'),
        'twilio_from_number' => env('TWILIO_FROM_NUMBER'),
    ],

];
