<?php

return [
    'users' => [
        'id' => 'users',
        'suspended_by' => 'users',
    ],
    'otp_codes' => [
        'id' => 'otp_codes',
        'google_user_id' => 'users',
    ],
    'teacher_profiles' => [
        'id' => 'teacher_profiles',
        'user_id' => 'users',
    ],
    'institute_profiles' => [
        'id' => 'institute_profiles',
        'user_id' => 'users',
    ],
    'profile_subjects' => [
        'profile_id' => 'users',
    ],
    'conversations' => [
        'id' => 'conversations',
        'learner_id' => 'users',
        'provider_id' => 'users',
    ],
    'messages' => [
        'id' => 'messages',
        'conversation_id' => 'conversations',
        'sender_id' => 'users',
        'reply_to_id' => 'messages',
    ],
    'admin_message_access_log' => [
        'id' => 'admin_message_access_log',
        'admin_id' => 'users',
        'conversation_id' => 'conversations',
    ],
    'bookings' => [
        'id' => 'bookings',
        'learner_id' => 'users',
        'provider_id' => 'users',
    ],
    'admin_booking_access_log' => [
        'id' => 'admin_booking_access_log',
        'admin_id' => 'users',
    ],
    'reviews' => [
        'id' => 'reviews',
        'booking_id' => 'bookings',
        'learner_id' => 'users',
        'provider_id' => 'users',
    ],
    'posts' => [
        'id' => 'posts',
        'author_id' => 'users',
    ],
    'post_likes' => [
        'id' => 'post_likes',
        'post_id' => 'posts',
        'user_id' => 'users',
    ],
    'post_comments' => [
        'id' => 'post_comments',
        'post_id' => 'posts',
        'user_id' => 'users',
        'parent_comment_id' => 'post_comments',
    ],
    'page_views' => [
        'id' => 'page_views',
    ],
    'referrals' => [
        'id' => 'referrals',
        'referrer_id' => 'users',
        'referred_id' => 'users',
    ],
    'phone_reveals' => [
        'id' => 'phone_reveals',
        'viewer_id' => 'users',
        'profile_user_id' => 'users',
    ],
    'support_tickets' => [
        // Ticket IDs stay UUIDs; only the user reference is converted.
        'user_id' => 'users',
    ],
    'suggestions' => [
        'id' => 'suggestions',
        'submitted_by' => 'users',
    ],
    'reports' => [
        'id' => 'reports',
        'reporter_id' => 'users',
        'target_id' => null,
    ],
    'blocks' => [
        'id' => 'blocks',
        'blocker_id' => 'users',
        'blocked_id' => 'users',
    ],
    'email_otp_codes' => [
        'id' => 'email_otp_codes',
        'user_id' => 'users',
    ],
    'auth_activity' => [
        'id' => 'auth_activity',
        'user_id' => 'users',
    ],
    'admin_report_access_log' => [
        // Access-log IDs stay UUIDs; only polymorphic record references change.
        'record_id' => null,
    ],
    'business_activity' => [
        // Append-only event IDs stay UUIDs; actor references use numeric users.
        'actor_user_id' => 'users',
        'related_user_id' => 'users',
        'entity_id' => null,
    ],
    'otp_delivery_attempts' => [
        'id' => 'otp_delivery_attempts',
        'otp_code_id' => 'otp_codes',
    ],
    'message_reactions' => [
        'id' => 'message_reactions',
        'message_id' => 'messages',
        'conversation_id' => 'conversations',
        'user_id' => 'users',
    ],
];
