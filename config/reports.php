<?php

// Explicit report contracts: new database columns are never exposed automatically.
return [
    'otp-delivery' => [
        'title' => 'OTP channel submissions', 'table' => 'otp_delivery_attempts', 'timestamp' => 'created_at',
        'columns' => ['id', 'otp_code_id', 'channel', 'provider', 'status', 'phone_last_four', 'created_at'],
        'participants' => [], 'search' => ['channel', 'provider', 'status'],
    ],
    'activity' => [
        'title' => 'Business activity history', 'table' => 'business_activity', 'timestamp' => 'created_at',
        'columns' => ['id', 'event', 'entity_type', 'entity_id', 'actor_user_id', 'related_user_id', 'version', 'before_data', 'after_data', 'created_at'],
        'participants' => ['actor_user_id', 'related_user_id'], 'search' => ['event', 'entity_type', 'entity_id'],
    ],
    'users' => [
        'title' => 'Users and registrations', 'table' => 'users', 'timestamp' => 'created_at',
        'columns' => ['id', 'account_type', 'full_name', 'email', 'phone', 'avatar_url', 'avatar_status', 'is_admin', 'date_of_birth', 'is_minor', 'guardian_name', 'guardian_contact', 'parental_consent_confirmed_at', 'safety_disclaimer_acknowledged_at', 'preferred_language', 'suspended_at', 'suspended_reason', 'suspended_by', 'phone_verified_at', 'email_verified_at', 'referral_code', 'terms_accepted_at', 'profile_completed_at', 'created_at', 'updated_at'],
        'participants' => ['id'], 'search' => ['full_name', 'email', 'phone'],
        'computed' => ['password_set' => 'CASE WHEN r.password_hash IS NOT NULL THEN 1 ELSE 0 END', 'google_linked' => 'CASE WHEN r.google_id IS NOT NULL THEN 1 ELSE 0 END'],
    ],
    'conversations' => [
        'title' => 'Chat participants', 'table' => 'conversations', 'timestamp' => 'created_at',
        'columns' => ['id', 'learner_id', 'provider_id', 'created_at'],
        'participants' => ['learner_id', 'provider_id'], 'search' => [],
        'computed' => ['message_count' => '(SELECT count(*) FROM messages AS m WHERE m.conversation_id = r.id)', 'last_message_at' => '(SELECT max(created_at) FROM messages AS m WHERE m.conversation_id = r.id)'],
    ],
    'messages' => [
        'title' => 'Messages by sender', 'table' => 'messages', 'timestamp' => 'created_at',
        'columns' => ['id', 'conversation_id', 'sender_id', 'body', 'read_at', 'created_at'],
        'participants' => ['sender_id'], 'search' => ['body', 'conversation_id'],
    ],
    'logins' => [
        'title' => 'Login and registration activity', 'table' => 'auth_activity', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'identifier', 'event', 'successful', 'status_code', 'ip_address', 'user_agent', 'created_at'],
        'participants' => ['user_id'], 'search' => ['identifier', 'event'],
    ],
    'bookings' => [
        'title' => 'Bookings', 'table' => 'bookings', 'timestamp' => 'created_at',
        'columns' => ['id', 'learner_id', 'provider_id', 'subject_id', 'mode', 'requested_time', 'price', 'status', 'created_at', 'updated_at'],
        'participants' => ['learner_id', 'provider_id'], 'search' => ['status', 'mode'],
    ],
    'reviews' => [
        'title' => 'Reviews', 'table' => 'reviews', 'timestamp' => 'created_at',
        'columns' => ['id', 'booking_id', 'learner_id', 'provider_id', 'rating', 'comment', 'created_at'],
        'participants' => ['learner_id', 'provider_id'], 'search' => ['comment'],
    ],
    'posts' => [
        'title' => 'Feed posts', 'table' => 'posts', 'timestamp' => 'created_at',
        'columns' => ['id', 'author_id', 'post_type', 'city', 'subject_id', 'body', 'image_url', 'created_at'],
        'participants' => ['author_id'], 'search' => ['body', 'city'],
    ],
    'comments' => [
        'title' => 'Feed comments', 'table' => 'post_comments', 'timestamp' => 'created_at',
        'columns' => ['id', 'post_id', 'user_id', 'parent_comment_id', 'body', 'created_at'],
        'participants' => ['user_id'], 'search' => ['body'],
    ],
    'likes' => [
        'title' => 'Post likes', 'table' => 'post_likes', 'timestamp' => 'created_at',
        'columns' => ['id', 'post_id', 'user_id', 'created_at'],
        'participants' => ['user_id'], 'search' => [],
    ],
    'support' => [
        'title' => 'Support tickets', 'table' => 'support_tickets', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'subject', 'message', 'status', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['subject', 'message', 'status'],
    ],
    'suggestions' => [
        'title' => 'Suggestions', 'table' => 'suggestions', 'timestamp' => 'created_at',
        'columns' => ['id', 'submitted_by', 'suggestion_type', 'name', 'notes', 'status', 'created_at'],
        'participants' => ['submitted_by'], 'search' => ['name', 'notes', 'status'],
    ],
    'reports' => [
        'title' => 'Safety reports', 'table' => 'reports', 'timestamp' => 'created_at',
        'columns' => ['id', 'reporter_id', 'target_type', 'target_id', 'reason_category', 'reason', 'status', 'created_at'],
        'participants' => ['reporter_id'], 'search' => ['reason', 'reason_category', 'status'],
    ],
    'blocks' => [
        'title' => 'User blocks', 'table' => 'blocks', 'timestamp' => 'created_at',
        'columns' => ['id', 'blocker_id', 'blocked_id', 'created_at'],
        'participants' => ['blocker_id', 'blocked_id'], 'search' => [],
    ],
    'referrals' => [
        'title' => 'Referrals', 'table' => 'referrals', 'timestamp' => 'created_at',
        'columns' => ['id', 'referrer_id', 'referred_id', 'created_at'],
        'participants' => ['referrer_id', 'referred_id'], 'search' => [],
    ],
    'phone-reveals' => [
        'title' => 'Phone reveals', 'table' => 'phone_reveals', 'timestamp' => 'revealed_at',
        'columns' => ['id', 'viewer_id', 'profile_user_id', 'revealed_at'],
        'participants' => ['viewer_id', 'profile_user_id'], 'search' => [],
    ],
    'page-views' => [
        'title' => 'Page views', 'table' => 'page_views', 'timestamp' => 'created_at',
        'columns' => ['id', 'page_type', 'target', 'platform', 'referrer', 'user_agent', 'created_at'],
        'participants' => [], 'search' => ['page_type', 'target', 'platform'],
    ],
    'teacher-profiles' => [
        'title' => 'Teacher profiles', 'table' => 'teacher_profiles', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'bio', 'rate_per_hour', 'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'linkedin_url', 'slug', 'is_verified', 'verified_at', 'trial_days', 'rating_avg', 'rating_count', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['city', 'bio'],
    ],
    'institute-profiles' => [
        'title' => 'Institute profiles', 'table' => 'institute_profiles', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'description', 'starting_rate', 'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'instructors_text', 'slug', 'website_url', 'is_verified', 'verified_at', 'trial_days', 'institute_type', 'is_bsl_official', 'bsl_official_since', 'rating_avg', 'rating_count', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['city', 'description'],
    ],
    'profile-subjects' => [
        'title' => 'Profile subjects', 'table' => 'profile_subjects', 'timestamp' => null,
        'columns' => ['id', 'profile_type', 'profile_id', 'subject_id'],
        'participants' => [], 'search' => [],
    ],
    'subjects' => [
        'title' => 'Subjects', 'table' => 'subjects', 'timestamp' => null,
        'columns' => ['id', 'domain_id', 'name', 'is_bsl'],
        'participants' => [], 'search' => ['name'],
    ],
    'subject-domains' => [
        'title' => 'Subject domains', 'table' => 'subject_domains', 'timestamp' => null,
        'columns' => ['id', 'name', 'icon', 'sort_order'],
        'participants' => [], 'search' => ['name'],
    ],
    'admin-access' => [
        'title' => 'Admin report access', 'table' => 'admin_report_access_log', 'timestamp' => 'created_at',
        'columns' => ['id', 'admin_email', 'report', 'record_id', 'action', 'ip_address', 'created_at'],
        'participants' => [], 'search' => ['admin_email', 'report', 'action'],
    ],
    'api-chat-access' => [
        'title' => 'API admin chat access', 'table' => 'admin_message_access_log', 'timestamp' => 'accessed_at',
        'columns' => ['id', 'admin_id', 'conversation_id', 'accessed_at'],
        'participants' => ['admin_id'], 'search' => [],
    ],
    'api-booking-access' => [
        'title' => 'API admin booking access', 'table' => 'admin_booking_access_log', 'timestamp' => 'accessed_at',
        'columns' => ['id', 'admin_id', 'accessed_at'],
        'participants' => ['admin_id'], 'search' => [],
    ],
];
