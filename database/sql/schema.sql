-- Nearby Teacher — PostgreSQL schema
-- Requires the PostGIS extension for radius/geo search.

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- =========================================================
-- USERS
-- =========================================================
-- One row per person/organization account. account_type decides
-- which profile table (if any) they have a matching row in.
CREATE TABLE users (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  account_type  TEXT NOT NULL CHECK (account_type IN ('learner', 'teacher', 'institute')),
  full_name     TEXT NOT NULL,
  email         TEXT UNIQUE,
  phone         TEXT UNIQUE,
  google_id     TEXT UNIQUE,
  avatar_url    TEXT,
  is_admin      BOOLEAN NOT NULL DEFAULT FALSE, -- grants access to the internal admin dashboard; set manually via SQL, no self-serve path
  -- Age verification & parental consent. Nullable: existing accounts
  -- predate this feature and won't have a date_of_birth on file yet —
  -- enforced going forward at the application layer (POST
  -- /auth/date-of-birth), not a DB-level NOT NULL, so this migration
  -- doesn't break existing rows. is_minor is computed server-side from
  -- date_of_birth at the time it's submitted, not re-derived on every
  -- read, so it stays stable even as the user ages past 18 later.
  date_of_birth DATE,
  is_minor      BOOLEAN NOT NULL DEFAULT FALSE,
  guardian_name TEXT,
  guardian_contact TEXT, -- parent/guardian email or phone, required alongside date_of_birth for a minor
  parental_consent_token TEXT, -- set when a consent request is sent; cleared once confirmed
  parental_consent_confirmed_at TIMESTAMPTZ, -- NULL until the guardian confirms; messaging/booking are blocked for a minor until this is set
  -- Background-check disclaimer: made an ENFORCED in-app gate, not just
  -- ToS text nobody reads. NULL until the user has explicitly
  -- acknowledged that Nearby Teacher does not background-check
  -- listings; messaging/booking are blocked until this is set — same
  -- enforcement pattern as parental consent above, applied to everyone
  -- (not just minors), since the underlying risk applies to any user.
  safety_disclaimer_acknowledged_at TIMESTAMPTZ,
  -- Photo moderation: every new avatar upload starts 'pending' and is
  -- excluded from public profile responses until an admin approves it
  -- (see src/routes/moderation.routes.js). Existing accounts and
  -- accounts with no photo default to 'approved' so this doesn't
  -- retroactively hide anything that predates the feature.
  avatar_status TEXT NOT NULL DEFAULT 'approved' CHECK (avatar_status IN ('pending', 'approved', 'rejected')),
  -- One of the 8 codes in src/i18n/ (en, hi, es, fr, ar, zh, pt, ru).
  -- Defaults to English; the client is free to override at first launch
  -- based on device locale without waiting for the user to explicitly
  -- pick one, then persist that choice here via PATCH
  -- /auth/preferred-language so it follows the account across devices,
  -- not just stored locally on one phone.
  preferred_language TEXT NOT NULL DEFAULT 'en',
  -- Account suspension: the other half of the admin moderation tooling
  -- — full visibility into messages/bookings is only useful if admin
  -- can actually act on what they find. Enforced in requireAuth itself
  -- (middleware/auth.js), not per-route, so a suspended user is blocked
  -- from every authenticated action, not just messaging/booking — this
  -- is broader than the safety-disclaimer/parental-consent gates,
  -- which only apply to those three specific endpoints.
  suspended_at      TIMESTAMPTZ,
  suspended_reason  TEXT,
  suspended_by      UUID REFERENCES users(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT users_has_login CHECK (email IS NOT NULL OR phone IS NOT NULL OR google_id IS NOT NULL)
);

-- OTP codes issued for phone login. Short-lived, deleted/expired after use.
CREATE TABLE otp_codes (
  id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  phone       TEXT NOT NULL,
  code_hash   TEXT NOT NULL,
  expires_at  TIMESTAMPTZ NOT NULL,
  attempts    INTEGER NOT NULL DEFAULT 0, -- failed verify attempts against this code; capped in src/utils/otp.js to stop brute-forcing a 6-digit code
  consumed_at TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_otp_codes_phone ON otp_codes(phone);

-- =========================================================
-- SUBJECT TAXONOMY
-- =========================================================
-- Two-level taxonomy: domain (e.g. "Languages & ESL") -> subject (e.g. "French").
-- is_bsl flags subjects that belong to BSL's own catalog, so they can be
-- featured/prioritized in search and the catalog screen.
CREATE TABLE subject_domains (
  id      SERIAL PRIMARY KEY,
  name    TEXT NOT NULL UNIQUE,
  icon    TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE subjects (
  id          SERIAL PRIMARY KEY,
  domain_id   INTEGER NOT NULL REFERENCES subject_domains(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  is_bsl      BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE (domain_id, name)
);
CREATE INDEX idx_subjects_is_bsl ON subjects(is_bsl) WHERE is_bsl = TRUE;

-- =========================================================
-- TEACHER / INSTITUTE PROFILES
-- =========================================================
CREATE TYPE lesson_mode AS ENUM ('in_person', 'home_tuition', 'online');

CREATE TABLE teacher_profiles (
  user_id       UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  bio           TEXT, -- product rule: max 14 words, enforced in the API layer (src/routes/profiles.routes.js)
  rate_per_hour NUMERIC(10, 2),
  currency      TEXT NOT NULL DEFAULT 'INR', -- placeholder only, briefly, before a country is set; PUT /profiles/me derives the real value from country_code (src/utils/currency.js) — see README
  lesson_modes  lesson_mode[] NOT NULL DEFAULT '{}',
  location      GEOGRAPHY(POINT, 4326),
  address_text  TEXT,
  city          TEXT, -- used for "other city / international" search, independent of geo radius
  state         TEXT,
  country       TEXT,
  country_code  TEXT, -- ISO 3166-1 alpha-2 (e.g. 'IN'), used client-side to render the flag
  linkedin_url  TEXT, -- optional, shown on the profile as a credibility signal
  slug          TEXT UNIQUE, -- URL-friendly identifier for the public website page, generated on first publish
  is_verified   BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at   TIMESTAMPTZ, -- when verification was granted; null if never verified
  trial_days    SMALLINT NOT NULL DEFAULT 0 CHECK (trial_days BETWEEN 0 AND 7), -- 0 = no trial, 1-6 = that many trial days, 7 = a full week trial
  rating_avg    NUMERIC(3, 2) NOT NULL DEFAULT 0,
  rating_count  INTEGER NOT NULL DEFAULT 0,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_teacher_profiles_location ON teacher_profiles USING GIST (location);
CREATE INDEX idx_teacher_profiles_city ON teacher_profiles (lower(city));

CREATE TABLE institute_profiles (
  user_id       UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  description   TEXT, -- the institute's bio; product rule: max 14 words, enforced in the API layer, same as teacher_profiles.bio
  starting_rate NUMERIC(10, 2),
  currency      TEXT NOT NULL DEFAULT 'INR', -- placeholder only, briefly, before a country is set; PUT /profiles/me derives the real value from country_code (src/utils/currency.js) — see README
  lesson_modes  lesson_mode[] NOT NULL DEFAULT '{}',
  location      GEOGRAPHY(POINT, 4326),
  address_text  TEXT,
  city          TEXT, -- used for "other city / international" search, independent of geo radius
  state         TEXT,
  country       TEXT,
  country_code  TEXT, -- ISO 3166-1 alpha-2 (e.g. 'IN'), used client-side to render the flag
  instructors_text TEXT, -- free-text list of staff, per product decision: no sub-accounts
  slug          TEXT UNIQUE, -- URL-friendly identifier for the public website page (e.g. "bright-route-institute-kanpur"), generated on first publish
  website_url   TEXT, -- the institute's own external website, optional
  is_verified   BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at   TIMESTAMPTZ, -- when verification was granted; null if never verified
  trial_days    SMALLINT NOT NULL DEFAULT 0 CHECK (trial_days BETWEEN 0 AND 7), -- 0 = no trial, 1-6 = that many trial days, 7 = a full week trial
  institute_type TEXT NOT NULL DEFAULT 'independent' CHECK (institute_type IN ('independent', 'franchise', 'mcp_partner', 'bsl_direct')), -- classifies the institute; only admin can set this, never self-declared
  is_bsl_official BOOLEAN NOT NULL DEFAULT FALSE, -- the "BSL Verified" badge -- distinct from is_verified above. Only institute_type = 'bsl_direct' is eligible; franchise and MCP partners are explicitly excluded
  bsl_official_since TIMESTAMPTZ,
  rating_avg    NUMERIC(3, 2) NOT NULL DEFAULT 0,
  rating_count  INTEGER NOT NULL DEFAULT 0,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_institute_profiles_location ON institute_profiles USING GIST (location);
CREATE INDEX idx_institute_profiles_city ON institute_profiles (lower(city));

-- Which subjects a teacher/institute offers. profile_type + profile_id together
-- point at either a teacher_profiles or institute_profiles row.
-- Product rule: a profile may list at most 5 subjects — enforced in the
-- API layer (src/routes/profiles.routes.js), not here, since a DB-level
-- cap needs a trigger; add one before launch if you want it enforced
-- even against direct DB writes.
CREATE TABLE profile_subjects (
  id           SERIAL PRIMARY KEY,
  profile_type TEXT NOT NULL CHECK (profile_type IN ('teacher', 'institute')),
  profile_id   UUID NOT NULL,
  subject_id   INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  UNIQUE (profile_type, profile_id, subject_id)
);
CREATE INDEX idx_profile_subjects_subject ON profile_subjects(subject_id);
CREATE INDEX idx_profile_subjects_profile ON profile_subjects(profile_type, profile_id);

-- =========================================================
-- MESSAGING
-- =========================================================
CREATE TABLE conversations (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  learner_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  provider_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, -- teacher or institute user
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (learner_id, provider_id)
);

CREATE TABLE messages (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
  sender_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body            TEXT NOT NULL,
  read_at         TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_messages_conversation ON messages(conversation_id, created_at);

-- Audit trail for admin access to conversations. Full admin visibility
-- into any conversation is a real, deliberate product decision (not
-- the default choice — a report-triggered-only model was the
-- recommended alternative), and broad access to private messages needs
-- accountability: a record of which admin looked at which conversation
-- and when, so this capability can't be silently misused. Written on
-- every GET /moderation/conversations/:id/messages call, not just
-- exposed as a nice-to-have — see that route for where this is
-- inserted.
CREATE TABLE admin_message_access_log (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  admin_id        UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
  accessed_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_admin_message_access_conversation ON admin_message_access_log(conversation_id, accessed_at);

-- =========================================================
-- BOOKINGS
-- =========================================================
CREATE TYPE booking_status AS ENUM ('requested', 'accepted', 'declined', 'cancelled', 'completed');

CREATE TABLE bookings (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  learner_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  provider_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id    INTEGER REFERENCES subjects(id),
  mode          lesson_mode NOT NULL,
  requested_time TIMESTAMPTZ NOT NULL,
  price         NUMERIC(10, 2),
  status        booking_status NOT NULL DEFAULT 'requested',
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_bookings_provider ON bookings(provider_id, status);
CREATE INDEX idx_bookings_learner ON bookings(learner_id, status);

-- Audit trail for admin access to the full bookings list — same
-- accountability principle as admin_message_access_log, applied here
-- as a single log entry per list view rather than per-booking (a
-- booking request is a structured record someone browses as a list,
-- not an open-ended private thread someone opens one at a time — the
-- granularity matches how the data is actually consumed).
CREATE TABLE admin_booking_access_log (
  id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  admin_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  accessed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================================================
-- REVIEWS
-- =========================================================
CREATE TABLE reviews (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  booking_id    UUID NOT NULL UNIQUE REFERENCES bookings(id) ON DELETE CASCADE,
  learner_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  provider_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  rating        SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment       TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_reviews_provider ON reviews(provider_id);

-- =========================================================
-- CITY FEED
-- =========================================================
CREATE TABLE posts (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  author_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  post_type     TEXT NOT NULL CHECK (post_type IN ('offer', 'request')), -- 'offer' = teacher/institute announcing something; 'request' = learner asking for a teacher/subject
  city          TEXT NOT NULL,
  subject_id    INTEGER REFERENCES subjects(id),
  body          TEXT NOT NULL,
  image_url     TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_posts_city ON posts(city, created_at DESC);

CREATE TABLE post_likes (
  post_id  UUID NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  user_id  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (post_id, user_id)
);

CREATE TABLE post_comments (
  id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  post_id    UUID NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  user_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  parent_comment_id UUID REFERENCES post_comments(id) ON DELETE CASCADE, -- null = top-level comment; set = a reply to another comment (typically the post author replying)
  body       TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_post_comments_post ON post_comments(post_id, created_at);
CREATE INDEX idx_post_comments_parent ON post_comments(parent_comment_id);

-- =========================================================
-- ANALYTICS
-- =========================================================
-- Lightweight page-view logging for the public website: how many people
-- view an institute/teacher page, which cities get traffic, and how the
-- "download the app" click breaks down by platform. Intentionally simple
-- (one table, no sessions/cookies) — enough to answer "is this working"
-- without building a full analytics stack.
CREATE TABLE page_views (
  id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  page_type   TEXT NOT NULL CHECK (page_type IN ('institute', 'teacher', 'city_index', 'city_feed', 'city_directory', 'download', 'app_profile')), -- app_profile: a teacher/institute profile viewed inside the mobile app (target = the profile owner's user_id); the others are all public-website page types
  target      TEXT, -- slug for institute/teacher pages, city name for city pages, null for the directory
  platform    TEXT, -- for page_type = 'download': 'ios' | 'android' | 'desktop' | 'unknown'
  referrer    TEXT,
  user_agent  TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_page_views_type_target ON page_views(page_type, target, created_at);
CREATE INDEX idx_page_views_created ON page_views(created_at);

-- =========================================================
-- REFERRALS (growth mechanic — no monetary reward built yet,
-- since monetization itself is a deliberately deferred decision;
-- this just tracks who brought in whom)
-- =========================================================
-- Every user gets a short referral_code, generated lazily on first
-- request (see GET /referrals/mine) rather than at signup, so existing
-- users before this feature shipped still get one the first time they
-- open the referrals screen.
ALTER TABLE users ADD COLUMN referral_code TEXT UNIQUE;

-- One row per successfully-referred user. referred_id is UNIQUE so a
-- person can only ever be credited to one referrer — the first
-- successful POST /referrals/redeem for a given user wins, later
-- attempts are rejected rather than silently overwriting who gets
-- credit.
CREATE TABLE referrals (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  referrer_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  referred_id   UUID NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_referrals_referrer ON referrals(referrer_id);

-- =========================================================
-- PHONE REVEALS (daily quota — 5 distinct phone numbers/day)
-- =========================================================
-- One row per (viewer, profile) the first time a viewer sees that
-- profile's phone number on a given day. Re-viewing a profile whose
-- phone was already revealed today doesn't consume more quota — only
-- a genuinely new profile does. Messaging and booking are NOT limited
-- by this table at all; the quota applies only to the phone-number
-- reveal itself (see GET /profiles/:type/:id).
CREATE TABLE phone_reveals (
  id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  viewer_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  profile_user_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  revealed_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_phone_reveals_viewer_day ON phone_reveals(viewer_id, revealed_at);

-- =========================================================
-- SUPPORT (contacting BSL directly, not a teacher/institute)
-- =========================================================
-- Powers the in-app "Contact BSL" form. Separate from `reports` (which
-- is about flagging another user/post) — this is a direct line to BSL
-- as the platform operator, for billing questions, legal requests,
-- account issues, or general confusion that isn't about any specific
-- other user.
CREATE TABLE support_tickets (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject       TEXT NOT NULL,
  message       TEXT NOT NULL,
  status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'resolved')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_support_tickets_status ON support_tickets(status, created_at);

-- =========================================================
-- SUGGESTIONS (missing course / city / state / country)
-- =========================================================
-- Submitted from empty-state screens when a search turns up nothing.
-- Reviewed by BSL/admin; approved suggestions get turned into real
-- subject/domain rows or into location values a profile can use.
CREATE TABLE suggestions (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  submitted_by  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  suggestion_type TEXT NOT NULL CHECK (suggestion_type IN ('course', 'city', 'state', 'country')),
  name          TEXT NOT NULL,
  notes         TEXT,
  status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'added', 'declined')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_suggestions_status ON suggestions(status, created_at);

-- =========================================================
-- TRUST & SAFETY
-- =========================================================
CREATE TABLE reports (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  reporter_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  target_type   TEXT NOT NULL CHECK (target_type IN ('user', 'post', 'comment', 'message')),
  target_id     UUID NOT NULL,
  reason_category TEXT NOT NULL CHECK (reason_category IN ('fraud', 'fake_profile', 'inappropriate', 'spam', 'other')),
  reason        TEXT,
  status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'reviewed', 'dismissed')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_reports_category ON reports(reason_category, status);
CREATE INDEX idx_reports_target ON reports(target_type, target_id);

CREATE TABLE blocks (
  blocker_id  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  blocked_id  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (blocker_id, blocked_id)
);
