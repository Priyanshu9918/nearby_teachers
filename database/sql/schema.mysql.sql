-- MySQL 8 schema for the Laravel application.
-- Latitude/longitude columns replace PostgreSQL/PostGIS geography values.

CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  account_type ENUM('learner', 'teacher', 'institute') NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(255) NULL UNIQUE,
  phone VARCHAR(32) NULL UNIQUE,
  google_id VARCHAR(128) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  avatar_url TEXT NULL,
  is_admin BOOLEAN NOT NULL DEFAULT FALSE,
  date_of_birth DATE NULL,
  is_minor BOOLEAN NOT NULL DEFAULT FALSE,
  guardian_name VARCHAR(160) NULL,
  guardian_contact VARCHAR(255) NULL,
  parental_consent_token VARCHAR(128) NULL,
  parental_consent_confirmed_at DATETIME(6) NULL,
  parental_consent_expires_at DATETIME(6) NULL,
  parental_consent_rejected_at DATETIME(6) NULL,
  safety_disclaimer_acknowledged_at DATETIME(6) NULL,
  terms_accepted_at DATETIME(6) NULL,
  profile_completed_at DATETIME(6) NULL,
  avatar_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  preferred_language VARCHAR(8) NOT NULL DEFAULT 'en',
  suspended_at DATETIME(6) NULL,
  suspended_reason TEXT NULL,
  suspended_by CHAR(36) NULL,
  phone_verified_at DATETIME(6) NULL,
  referral_code VARCHAR(32) NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX users_parental_consent_token_index (parental_consent_token),
  CONSTRAINT users_suspended_by_fk FOREIGN KEY (suspended_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
  id CHAR(36) NOT NULL PRIMARY KEY,
  phone VARCHAR(32) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  consumed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_otp_codes_phone (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subject_domains (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  icon VARCHAR(160) NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  domain_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  is_bsl BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE KEY subjects_domain_name_unique (domain_id, name),
  INDEX idx_subjects_is_bsl (is_bsl),
  CONSTRAINT subjects_domain_fk FOREIGN KEY (domain_id) REFERENCES subject_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teacher_profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL UNIQUE,
  primary_subject_id INT UNSIGNED NULL,
  bio TEXT NULL,
  rate_per_hour DECIMAL(10,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  lesson_modes JSON NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  address_text VARCHAR(1000) NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  country_code CHAR(2) NULL,
  linkedin_url VARCHAR(2048) NULL,
  slug VARCHAR(255) NULL UNIQUE,
  is_verified BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at DATETIME(6) NULL,
  trial_days SMALLINT NOT NULL DEFAULT 0,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
  rating_count INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_teacher_profiles_location (latitude, longitude),
  INDEX idx_teacher_profiles_city (city),
  CONSTRAINT teacher_profiles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT teacher_profiles_primary_subject_fk FOREIGN KEY (primary_subject_id) REFERENCES subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE institute_profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL UNIQUE,
  contact_person VARCHAR(160) NULL,
  description TEXT NULL,
  starting_rate DECIMAL(10,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  lesson_modes JSON NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  address_text VARCHAR(1000) NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  country_code CHAR(2) NULL,
  instructors_text TEXT NULL,
  slug VARCHAR(255) NULL UNIQUE,
  website_url VARCHAR(2048) NULL,
  is_verified BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at DATETIME(6) NULL,
  trial_days SMALLINT NOT NULL DEFAULT 0,
  institute_type ENUM('independent', 'franchise', 'mcp_partner', 'bsl_direct') NOT NULL DEFAULT 'independent',
  is_bsl_official BOOLEAN NOT NULL DEFAULT FALSE,
  bsl_official_since DATETIME(6) NULL,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
  rating_count INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_institute_profiles_location (latitude, longitude),
  INDEX idx_institute_profiles_city (city),
  CONSTRAINT institute_profiles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profile_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_type ENUM('teacher', 'institute') NOT NULL,
  profile_id CHAR(36) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  UNIQUE KEY profile_subjects_unique (profile_type, profile_id, subject_id),
  INDEX idx_profile_subjects_subject (subject_id),
  INDEX idx_profile_subjects_profile (profile_type, profile_id),
  CONSTRAINT profile_subjects_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversations (
  id CHAR(36) NOT NULL PRIMARY KEY,
  learner_id CHAR(36) NOT NULL,
  provider_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY conversations_learner_provider_unique (learner_id, provider_id),
  CONSTRAINT conversations_learner_fk FOREIGN KEY (learner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT conversations_provider_fk FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id CHAR(36) NOT NULL PRIMARY KEY,
  conversation_id CHAR(36) NOT NULL,
  sender_id CHAR(36) NOT NULL,
  body TEXT NOT NULL,
  read_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_messages_conversation (conversation_id, created_at),
  CONSTRAINT messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_message_access_log (
  id CHAR(36) NOT NULL PRIMARY KEY,
  admin_id CHAR(36) NOT NULL,
  conversation_id CHAR(36) NOT NULL,
  accessed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_admin_message_access_conversation (conversation_id, accessed_at),
  CONSTRAINT admin_message_access_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT admin_message_access_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id CHAR(36) NOT NULL PRIMARY KEY,
  learner_id CHAR(36) NOT NULL,
  provider_id CHAR(36) NOT NULL,
  subject_id INT UNSIGNED NULL,
  mode ENUM('in_person', 'home_tuition', 'online') NOT NULL,
  requested_time DATETIME(6) NOT NULL,
  price DECIMAL(10,2) NULL,
  status ENUM('requested', 'accepted', 'declined', 'cancelled', 'completed') NOT NULL DEFAULT 'requested',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_bookings_provider (provider_id, status),
  INDEX idx_bookings_learner (learner_id, status),
  CONSTRAINT bookings_learner_fk FOREIGN KEY (learner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT bookings_provider_fk FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT bookings_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_booking_access_log (
  id CHAR(36) NOT NULL PRIMARY KEY,
  admin_id CHAR(36) NOT NULL,
  accessed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT admin_booking_access_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id CHAR(36) NOT NULL PRIMARY KEY,
  booking_id CHAR(36) NOT NULL UNIQUE,
  learner_id CHAR(36) NOT NULL,
  provider_id CHAR(36) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_reviews_provider (provider_id),
  CONSTRAINT reviews_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT reviews_learner_fk FOREIGN KEY (learner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT reviews_provider_fk FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  author_id CHAR(36) NOT NULL,
  post_type ENUM('offer', 'request') NOT NULL,
  city VARCHAR(160) NOT NULL,
  subject_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  image_url VARCHAR(2048) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_posts_city (city, created_at),
  CONSTRAINT posts_author_fk FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT posts_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_likes (
  id CHAR(36) NOT NULL PRIMARY KEY,
  post_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY post_likes_post_user_unique (post_id, user_id),
  CONSTRAINT post_likes_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT post_likes_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_comments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  post_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  parent_comment_id CHAR(36) NULL,
  body TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_post_comments_post (post_id, created_at),
  INDEX idx_post_comments_parent (parent_comment_id),
  CONSTRAINT post_comments_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT post_comments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT post_comments_parent_fk FOREIGN KEY (parent_comment_id) REFERENCES post_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_views (
  id CHAR(36) NOT NULL PRIMARY KEY,
  page_type ENUM('institute', 'teacher', 'city_index', 'city_feed', 'city_directory', 'download', 'app_profile') NOT NULL,
  target VARCHAR(255) NULL,
  platform VARCHAR(32) NULL,
  referrer VARCHAR(2048) NULL,
  user_agent VARCHAR(2048) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_page_views_type_target (page_type, target, created_at),
  INDEX idx_page_views_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referrals (
  id CHAR(36) NOT NULL PRIMARY KEY,
  referrer_id CHAR(36) NOT NULL,
  referred_id CHAR(36) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_referrals_referrer (referrer_id),
  CONSTRAINT referrals_referrer_fk FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT referrals_referred_fk FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE phone_reveals (
  id CHAR(36) NOT NULL PRIMARY KEY,
  viewer_id CHAR(36) NOT NULL,
  profile_user_id CHAR(36) NOT NULL,
  revealed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_phone_reveals_viewer_day (viewer_id, revealed_at),
  CONSTRAINT phone_reveals_viewer_fk FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT phone_reveals_profile_fk FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_contact_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  whatsapp_number VARCHAR(32) NULL,
  email VARCHAR(254) NULL,
  linkedin_url VARCHAR(2048) NULL,
  linkedin_name VARCHAR(160) NULL,
  linkedin_title VARCHAR(160) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_support_tickets_status (status, created_at),
  CONSTRAINT support_tickets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suggestions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  submitted_by CHAR(36) NOT NULL,
  suggestion_type ENUM('course', 'city', 'state', 'country') NOT NULL,
  name VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  status ENUM('open', 'added', 'declined') NOT NULL DEFAULT 'open',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_suggestions_status (status, created_at),
  CONSTRAINT suggestions_submitter_fk FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
  id CHAR(36) NOT NULL PRIMARY KEY,
  reporter_id CHAR(36) NOT NULL,
  target_type ENUM('user', 'post', 'comment', 'message') NOT NULL,
  target_id CHAR(36) NOT NULL,
  reason_category ENUM('fraud', 'fake_profile', 'inappropriate', 'spam', 'other') NOT NULL,
  reason TEXT NULL,
  evidence_link VARCHAR(2048) NULL,
  screenshot_path VARCHAR(1024) NULL,
  status ENUM('open', 'reviewed', 'dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_reports_category (reason_category, status),
  INDEX idx_reports_target (target_type, target_id),
  CONSTRAINT reports_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocks (
  id CHAR(36) NOT NULL PRIMARY KEY,
  blocker_id CHAR(36) NOT NULL,
  blocked_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY blocks_blocker_blocked_unique (blocker_id, blocked_id),
  CONSTRAINT blocks_blocker_fk FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT blocks_blocked_fk FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
