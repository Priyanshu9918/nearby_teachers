<?php

use App\Services\SeoDirectory;

// Explicit report contracts: new database columns are never exposed automatically.
return [
    'seo-pages' => [
        'title' => 'SEO page URLs', 'table' => 'seo_directory_pages', 'timestamp' => 'created_at',
        'columns' => ['id', 'subject_id', 'country', 'kind', 'city', 'path', 'created_at'],
        'participants' => [], 'search' => ['country', 'kind', 'city', 'path'],
        'computed' => ['eligible_providers' => SeoDirectory::COUNT_SQL,
            'route_enabled' => '(SELECT enabled FROM seo_subject_routes sr WHERE sr.id = r.route_id)'],
    ],
    'seo-location-pages' => [
        'title' => 'Location page URLs', 'table' => 'seo_location_pages', 'timestamp' => 'created_at',
        'columns' => ['id', 'kind', 'country', 'district', 'village', 'subject_slug', 'path', 'created_at'],
        'participants' => [], 'search' => ['kind', 'country', 'district', 'village', 'path'],
        'computed' => ['eligible_providers' => str_replace('lp.', 'r.', SeoDirectory::LOCATION_COUNT_SQL)],
    ],
    'seo-subject-routes' => [
        'title' => 'SEO subject URL settings', 'table' => 'seo_subject_routes', 'timestamp' => 'updated_at',
        'columns' => ['id', 'subject_id', 'country', 'language', 'display_name', 'city_slug', 'online_slug', 'enabled', 'created_at', 'updated_at'],
        'participants' => [], 'search' => ['country', 'language', 'display_name', 'city_slug', 'online_slug'],
    ],
    'seo-url-history' => [
        'title' => 'SEO URL ownership and redirects', 'table' => 'seo_urls', 'timestamp' => 'created_at',
        'columns' => ['id', 'path', 'target_type', 'target_id', 'created_at'],
        'participants' => [], 'search' => ['path', 'target_type', 'target_id'],
    ],
    'franchise-enquiries' => [
        'title' => 'BSL franchise enquiries', 'table' => 'franchise_enquiries', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'name', 'phone', 'location', 'status', 'created_at'],
        'participants' => ['user_id'], 'search' => ['name', 'phone', 'location', 'status'],
    ],
    'job-applications' => [
        'title' => 'BSL teaching job applications', 'table' => 'teaching_job_applications', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'name', 'phone', 'expected_salary', 'currency', 'salary_period', 'status', 'created_at'],
        'participants' => ['user_id'], 'search' => ['name', 'phone', 'status'],
        'computed' => ['courses' => '(SELECT JSON_ARRAYAGG(c.course_name) FROM teaching_job_application_courses AS c WHERE c.application_id = r.id)'],
    ],
    'job-application-courses' => [
        'title' => 'BSL job applications by course', 'table' => 'teaching_job_application_courses', 'timestamp' => 'created_at',
        'columns' => ['id', 'application_id', 'user_id', 'course_id', 'course_name', 'created_at'],
        'participants' => ['user_id'], 'search' => ['application_id', 'course_id', 'course_name'],
    ],
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
        'columns' => ['id', 'account_type', 'full_name', 'email', 'phone', 'phone_pin', 'avatar_url', 'avatar_status', 'is_admin', 'date_of_birth', 'is_minor', 'guardian_name', 'guardian_contact', 'parental_consent_confirmed_at', 'parental_consent_rejected_at', 'parental_consent_expires_at', 'safety_disclaimer_acknowledged_at', 'preferred_language', 'suspended_at', 'suspended_reason', 'suspended_by', 'phone_verified_at', 'email_verified_at', 'referral_code', 'referral_points', 'terms_accepted_at', 'registration_submitted_at', 'profile_completed_at', 'created_at', 'updated_at'],
        'participants' => ['id'], 'search' => ['full_name', 'email', 'phone', 'phone_pin'],
        'computed' => ['password_set' => 'CASE WHEN r.password_hash IS NOT NULL THEN 1 ELSE 0 END', 'google_linked' => 'CASE WHEN r.google_id IS NOT NULL THEN 1 ELSE 0 END', 'registration_completed' => "CASE WHEN r.profile_completed_at IS NOT NULL AND (r.account_type <> 'learner' OR (r.date_of_birth IS NOT NULL AND (r.is_minor = 0 OR r.parental_consent_confirmed_at IS NOT NULL))) THEN 1 ELSE 0 END"],
    ],
    'conversations' => [
        'title' => 'Chat participants', 'table' => 'conversations', 'timestamp' => 'created_at',
        'columns' => ['id', 'learner_id', 'provider_id', 'created_at'],
        'participants' => ['learner_id', 'provider_id'], 'search' => [],
        'computed' => ['message_count' => '(SELECT count(*) FROM messages AS m WHERE m.conversation_id = r.id)', 'last_message_at' => '(SELECT max(created_at) FROM messages AS m WHERE m.conversation_id = r.id)'],
    ],
    'messages' => [
        'title' => 'Messages by sender', 'table' => 'messages', 'timestamp' => 'created_at',
        'columns' => ['id', 'conversation_id', 'sender_id', 'body', 'reply_to_id', 'revision', 'delivered_at', 'read_at', 'edited_at', 'deleted_at', 'created_at'],
        'participants' => ['sender_id'], 'search' => ['body', 'conversation_id'],
    ],
    'message-reactions' => [
        'title' => 'Chat message reactions', 'table' => 'message_reactions', 'timestamp' => 'updated_at',
        'columns' => ['id', 'conversation_id', 'message_id', 'user_id', 'reaction', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['conversation_id', 'message_id', 'reaction'],
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
    'comment-likes' => [
        'title' => 'Comment and reply likes', 'table' => 'comment_likes', 'timestamp' => 'created_at',
        'columns' => ['id', 'comment_id', 'user_id', 'created_at'],
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
        'columns' => ['id', 'reporter_id', 'target_type', 'target_id', 'reason_category', 'reason', 'evidence_link', 'status', 'created_at'],
        'participants' => ['reporter_id'], 'search' => ['target_type', 'reason', 'reason_category', 'evidence_link', 'status'],
        'computed' => ['screenshot_available' => 'CASE WHEN r.screenshot_path IS NOT NULL THEN 1 ELSE 0 END'],
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
    'referral-clicks' => [
        'title' => 'Referral link clicks', 'table' => 'referral_clicks', 'timestamp' => 'created_at',
        'columns' => ['id', 'referrer_id', 'created_at'],
        'participants' => ['referrer_id'], 'search' => ['referrer_id'],
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
        'columns' => ['id', 'user_id', 'listing_name', 'is_profile_complete', 'completed_profile_steps', 'profile_draft_saved_at', 'bio', 'rate_per_hour', 'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'linkedin_url', 'public_page_slug', 'seo_country', 'seo_city_slug', 'location_details', 'district', 'sub_district', 'locality', 'locality_type', 'postal_code', 'slug', 'is_verified', 'verified_at', 'trial_days', 'rating_avg', 'rating_count', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['listing_name', 'city', 'bio', 'public_page_slug', 'seo_country', 'seo_city_slug', 'location_details', 'district', 'sub_district', 'locality', 'postal_code'],
        'computed' => ['seo_public' => "CASE WHEN r.is_profile_complete = 1 AND r.public_page_slug IS NOT NULL AND r.seo_country IS NOT NULL AND EXISTS (SELECT 1 FROM users pu WHERE pu.id = r.user_id AND pu.account_type = 'teacher' AND pu.suspended_at IS NULL AND pu.phone_verified_at IS NOT NULL AND pu.profile_completed_at IS NOT NULL) AND EXISTS (SELECT 1 FROM profile_subjects ps WHERE ps.profile_id = r.user_id AND ps.profile_type = 'teacher') THEN 1 ELSE 0 END"],
    ],
    'institute-profiles' => [
        'title' => 'Institute profiles', 'table' => 'institute_profiles', 'timestamp' => 'created_at',
        'columns' => ['id', 'user_id', 'listing_name', 'contact_person', 'is_profile_complete', 'completed_profile_steps', 'profile_draft_saved_at', 'description', 'starting_rate', 'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'instructors_text', 'district', 'sub_district', 'locality', 'locality_type', 'postal_code', 'location_details', 'slug', 'public_page_slug', 'seo_country', 'seo_city_slug', 'website_url', 'is_verified', 'verified_at', 'trial_days', 'institute_type', 'is_bsl_official', 'bsl_official_since', 'rating_avg', 'rating_count', 'created_at', 'updated_at'],
        'participants' => ['user_id'], 'search' => ['listing_name', 'contact_person', 'city', 'description', 'district', 'sub_district', 'locality', 'postal_code', 'location_details'],
        'computed' => ['seo_public' => "CASE WHEN r.is_profile_complete = 1 AND r.public_page_slug IS NOT NULL AND r.seo_country IS NOT NULL AND EXISTS (SELECT 1 FROM users pu WHERE pu.id = r.user_id AND pu.account_type = 'institute' AND pu.suspended_at IS NULL AND pu.phone_verified_at IS NOT NULL AND pu.profile_completed_at IS NOT NULL) AND EXISTS (SELECT 1 FROM profile_subjects ps WHERE ps.profile_id = r.user_id AND ps.profile_type = 'institute') THEN 1 ELSE 0 END"],
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
