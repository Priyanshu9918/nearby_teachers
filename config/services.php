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
        'otp_delivery_channels' => env('OTP_DELIVERY_CHANNELS', 'sms'),
        'whatsapp' => [
            'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'template_name' => env('WHATSAPP_OTP_TEMPLATE_NAME'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en_US'),
            'graph_version' => env('WHATSAPP_GRAPH_VERSION'),
        ],
        'otp_sms_provider' => env('OTP_SMS_PROVIDER', 'auto'),
        'muzztech' => [
            'api_key' => env('MUZZTECH_API_KEY'),
            'sender_name' => env('MUZZTECH_SENDER_NAME', 'BSLONL'),
            'template_id' => env('MUZZTECH_TEMPLATE_ID'),
            'message' => env('MUZZTECH_OTP_MESSAGE', 'Your Nearby Teacher verification code is {otp}. Valid for 10 minutes. Do not share this code with anyone.'),
            'phone_format' => env('MUZZTECH_PHONE_FORMAT', 'digits'),
            'success_field' => env('MUZZTECH_SUCCESS_FIELD', 'status'),
            'success_value' => env('MUZZTECH_SUCCESS_VALUE', 'success'),
        ],
        'jwt_secret' => env('JWT_SECRET'),
        'firebase_project_id' => env('FIREBASE_PROJECT_ID'),
        'firebase_service_account_path' => env('FIREBASE_SERVICE_ACCOUNT_PATH'),
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
