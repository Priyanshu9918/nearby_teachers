# Nearby Teacher — Backend

Laravel 11 application for the Nearby Teacher platform, configured for
MySQL 8. Mobile APIs, the admin portal, public SEO pages, download/deep-link
handling, sitemap, and robots routes are served by Laravel. The original
Express implementation remains under `src/` as a migration reference and
still targets its PostgreSQL/PostGIS schema; it is not the Laravel runtime.

## Laravel 11 setup

Requirements: PHP 8.2+, Composer, the `pdo_mysql` PHP extension, MySQL 8, and Node.js 22+ for Socket.IO.

1. Install dependencies and configure the environment:
   ```
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
2. Set `DB_*` to the MySQL database. Keep credentials in the untracked
   `.env` file only. Set up the browser admin credentials with:
   ```
   php artisan admin:setup-login
   ```
   The command prompts for the email and a hidden password, writes only the
   password hash to `.env`, and clears cached configuration.
   Configure Firebase and SMS credentials for Google/OTP sign-in. Configure
   `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, and
   `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` for application email notifications.
   Chat uses the Socket.IO server under `realtime/`. Run `npm ci --prefix realtime`,
   set `BROADCAST_CONNECTION=socketio`, and configure `SOCKET_IO_SHARED_SECRET`
   (same server-only secret for Laravel and Node). See [Socket.IO setup](docs/CHAT_SOCKET_IO.md).
3. Run the individual table migrations and load the subject taxonomy:
   ```
   php artisan migrate --seed
   ```
   On a fresh database, Laravel creates one Nearby Teacher table per
   migration, including latitude/longitude columns and JSON lesson-mode
   lists. Every application table has an `id` primary key; profile user IDs
   and the post-like/block pairs remain unique. Laravel only enables its
   MySQL connection. These create-table migrations are for a fresh database:
   back up and verify an existing database's migration history and columns
   before running them. The MySQL reference schema is
   `database/sql/schema.mysql.sql`; the PostgreSQL schema used by the legacy
   Express service remains in `database/sql/schema.sql`.
4. Start the Laravel app:
   ```
   php artisan serve
   ```
   In a second process, start the WebSocket server:
   ```
   npm run chat:start
   ```
   Keep background workers running in additional processes:
   ```
   php artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30
   php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30
   ```
   `QUEUE_CONNECTION=database` is the default; `sync` runs work inside API
   requests. Production workers should be kept alive by a process manager.
   After deployment use `php artisan queue:restart` to reload worker code.
   The admin portal is at `/admin`. Its Subjects, Students, Teachers, and
   Institutes sections support add, edit, list, and delete operations.
   Students use the existing `learner` account type. Database access in the
   Laravel admin code uses the Query Builder; related profile and subject
   listings use SQL joins rather than Eloquent relationships.

   Documentation for the Laravel implementation:

   - [Mobile/API reference](docs/API.md)
   - [Email login and password recovery](docs/EMAIL_AUTH.md)
   - [Admin portal guide](docs/ADMIN.md)
   - [Pagination, workers and performance changes](docs/PERFORMANCE.md)

   The existing Express code and documentation below are retained as a legacy
   implementation reference. Do not run the Express service against the
   Laravel MySQL database: its SQL and schema remain PostgreSQL/PostGIS-specific.
   The Laravel public-site equivalents are now served at `/`, `/site/*`,
   `/download`, `/sitemap.xml`, and `/robots.txt`.

## Laravel mobile API status

The Laravel application preserves these existing Express URL paths:

- `/auth/otp/request`, `/auth/otp/verify`, `/auth/session`, account-type
  selection, profile-photo and language updates, phone/Google account
  linking, date-of-birth verification, parental-consent confirmation,
  and safety-disclaimer endpoints.
- `/subjects` returns the BSL featured list, virtual "Instant Teacher
  Available" domain, and subjects grouped by subject domain.
- `/profiles/me`, profile stats, public teacher/institute profiles,
  phone-reveal quotas, and admin verification/classification endpoints.
- `/i18n/languages` and `/i18n/{lang}` return the existing localized
  language metadata and dictionaries.
- `/`, `/site`, `/site/institute/{slug}`, `/site/teacher/{slug}`,
  `/site/city/{city}`, and `/site/feed/{city}` serve the public directory,
  listing, city, and read-only feed pages. `/download` handles app-store
  redirects and Android deep-link/referral parameters; `/sitemap.xml` and
  `/robots.txt` support search indexing.
- `/search`, its count/other-city/subject tiers, and `/reviews/for/{id}`
  preserve radius discovery using MySQL `ST_Distance_Sphere`, weighted rating/referral sort,
  and public review listing.
- Authenticated `/bookings`, `/messages`, `/reviews`, and `/feed` APIs
  provide booking requests and status updates, participant-scoped message
  access, lesson reviews, city posts, likes, and comments. Messaging and
  booking enforce the safety acknowledgement and minor-consent gates.
- `/safety`, `/suggestions`, `/support`, and `/referrals` provide user
  submissions plus admin review queues. `/moderation` includes photo
  review, report/bookings/conversation queues, access audit logs, contact
  statistics, and account suspension. `/analytics/page-view` records
  public-page views.

Google sign-in uses a verified Firebase Google ID token and returns a
Laravel session token. Google sign-in creates or links the account by its
verified email and requires phone verification via SMS before protected
app endpoints are available. Mobile OTP is for registration verification; returning users use email/password login.
SMS delivery, phone-number validation, OTP attempt limits, suspension
checks, and existing JSON field names are handled by Laravel
services/controllers. Discovery uses MySQL's `ST_Distance_Sphere` over
stored latitude/longitude coordinates.

The Laravel implementation is the converted application surface, but the
existing Express code is retained as a reference during cutover. The target
MySQL schema and database-backed APIs still need validation against the
actual database before production traffic is moved. Switching from
PostgreSQL does not automatically copy existing users, listings, or other
data.

<!-- Legacy Express implementation reference follows. -->

Node.js + Express + PostgreSQL (with PostGIS) API for the Nearby Teacher app.
Companion to the product brief (`nearby-teacher-product-brief.md`) and the
screen designs supplied separately.

> **Important scope note:** Nearby Teacher ships as new features added
> inside the **existing** BSL app — [iOS](https://apps.apple.com/in/app/bsl-british-school-of-language/id6751044599),
> [Android](https://play.google.com/store/apps/details?id=com.britishschooloflanguage)
> — not as a separate, newly-published app. This backend and the screen
> designs are still accurate and ready to build against, but the mobile
> engineer integrating them will be adding new screens/tabs to the
> existing app's codebase, not starting a fresh React Native project from
> scratch. This has a few practical implications:
> - The existing app's current navigation, auth, and design system take
>   priority — new screens should match its patterns, not necessarily
>   the exact visual mockups produced earlier in this process
> - Many users already have the app installed, so the `/download` route
>   (see below) often opens the app directly ("Open" in the store)
>   rather than triggering a fresh install
> - Whoever picks this up should treat the existing app's codebase as
>   the source of truth for tech stack (framework, state management,
>   navigation library, etc.) rather than assuming a greenfield choice

## Age verification & parental consent

Addresses a known gap: the platform's learner base likely includes
minors, but age was never verified at signup. Not a COPPA-style
verifiable-parental-consent system (that's a much bigger build) — a
practical, bounded first version:

- `POST /auth/date-of-birth` `{ dateOfBirth, guardianName?, guardianContact? }`
  — call once during onboarding. Under 13 (`MIN_AGE` in
  `src/utils/age.js`) is rejected outright, no account created at that
  age. 13–17 requires a guardian name and contact, marks the account
  `is_minor = TRUE`, and emails a consent link to the guardian (SMS
  path for a phone-number guardian contact is a known TODO — flagged in
  code, not silently skipped). 18+ proceeds with no restriction.
- `POST /auth/parental-consent/confirm` `{ token }` — public, no auth
  (the guardian may not have their own account). One-time use: the
  token is cleared after confirming, so it can't be reused. Tested: 8
  scenarios across age classification (including the exact-13,
  exact-18, and birthday-hasn't-happened-yet-this-year edge cases) and
  the confirm-token flow (valid, reused, invalid) all pass.
- `requireParentalConsentIfMinor` middleware (`middleware/auth.js`)
  blocks a minor account from starting a conversation, sending a
  message, or creating a booking until `parental_consent_confirmed_at`
  is set — applied to those three actions specifically, not to
  browsing/search, since there's no safety reason to block a minor from
  looking at listings, only from initiating contact.
- **Existing accounts** (created before this feature) have
  `date_of_birth = NULL` and are treated as not-a-minor by the
  middleware — age is unknown, not assumed. The app should prompt every
  account missing a DOB to complete this step, ideally before their
  next message/booking attempt, not just once at a hard cutover.

## Rate limiting

Two endpoints were flagged as scrapeable — someone hitting them
repeatedly could bulk-extract the teacher/institute database. Both now
use `express-rate-limit`, 60 requests/minute per IP:
- `GET /search` and its sub-routes (`src/routes/search.routes.js`)
- `GET /profiles/:type/:id`, the public profile view
  (`src/routes/profiles.routes.js`) — keyed by IP specifically because
  this endpoint doesn't require login, so a per-user limit alone
  wouldn't stop an anonymous scraper enumerating sequential IDs.

Verified the limiter actually blocks over-limit requests (not just that
it loads without error) — a 5-request-limit test correctly allowed the
first 5 and returned `429` for the next 3.

## Account linking

Without this, someone who signs up via Google and later logs in via
phone/OTP (or vice versa) would silently get a second, disconnected
account — same person, two separate booking histories. Two endpoints
let a logged-in user add the *other* login method to their existing
account, each requiring fresh proof of ownership of what's being added:

- `PATCH /auth/link-phone` `{ phone, code }` — adds a phone number,
  verified by a real OTP (request it first via the existing
  `POST /auth/otp/request`).
- `PATCH /auth/link-google` `{ firebaseIdToken }` — adds a Google
  identity, verified by a real Firebase ID token from the client's
  normal Google sign-in flow.

Both reject with a `409` if the phone/Google identity already belongs to
a *different* account — linking is mutual-consent (already logged into
account A, prove ownership of the thing being added), never a silent
merge that could let someone attach a stranger's identifier to their
own account or vice versa. Verified with 5 test cases against a mocked
database: correct OTP accepted, a phone owned by a different account
correctly blocked, an unclaimed phone correctly allowed, and re-linking
an already-owned phone correctly not false-flagged as a conflict.

**What this does NOT do**: merge two *already-existing* separate
accounts' data (bookings, reviews, profiles) into one. That's a
genuinely harder problem — reassigning foreign keys across every table
that references a `user_id` — and isn't built. These endpoints only
*prevent new duplicates going forward*. If two accounts already exist
for one person before this ships, resolving that is a manual admin/DB
job, not a self-service flow.

## Search ranking

Two different sort rules, depending on scope — not a combined
relevance score:

- **Nearby** (Home screen, and the "Nearby" tab on course detail):
  distance only, closest first. Rating, price, and verification don't
  factor in at all.
- **City / State / Country / other-city-online**: a weighted rating
  (`src/routes/search.routes.js`, `weightedRatingSql`), not a raw
  average. A raw `ORDER BY rating_avg DESC` let a single 5-star review
  outrank a profile with 50 reviews averaging 4.8 — a real trust
  problem, not just a rough edge. The weighted version is a standard
  Bayesian/IMDb-style formula: a profile's score is pulled toward a
  neutral prior (4.0) until it has enough reviews of its own (5) to be
  trusted on its own merits. A brand-new profile with zero reviews
  scores exactly the prior — visible, not buried, but not falsely at
  the top either. Verified with concrete numbers: 50 reviews @ 4.8
  scores 4.727 and correctly outranks 1 review @ 5.0, which scores only
  4.167; as a side effect the formula is symmetric, so a single bad
  review doesn't unfairly tank a profile either (1 review @ 1.0 scores
  3.5, not 1.0). Tune `MIN_VOTES` (currently 5) and `PRIOR_RATING`
  (currently 4.0) if either number feels off once real review data
  exists.

**Deliberately not a ranking factor... mostly**: referral count is a
SECONDARY sort key on the City/State/Country/other-city-online tiers
(never Nearby, which stays distance-only) — applied only *after*
weighted rating, so it can only break ties, never override a real
rating difference. In practice this means it mainly differentiates
among profiles with zero reviews, which all sit at the same neutral
prior (4.0) otherwise and would tie in a non-deterministic order
without it. A profile with real reviews outranks a heavily-referred
profile with none, always — verified: an established teacher (50
reviews @ 4.8, 0 referrals) ranks #1 over three zero-review teachers
with 12/3/0 referrals respectively, who then correctly sort among
themselves by referral count (12 > 3 > 0). Gives new teachers/institutes
something to compete on before they have reviews, without ever letting
sharing the app substitute for actually being good at teaching.

## Referrals (growth mechanic)

No monetary reward is built — monetization itself is a deliberately
deferred decision (see the product brief) — this is pure tracking, so
whichever reward system gets decided later has real data to work from
rather than starting blind.

- `GET /referrals/mine` (any logged-in user) — returns the caller's
  referral code and shareable link (generated lazily on first call, so
  accounts created before this feature shipped still get one), plus
  who they've referred so far.
- `POST /referrals/redeem` `{ code }` — called once, right after a new
  account is created, if the app was opened via a referral link.
  Rejects self-referral and rejects crediting an account that's already
  been referred by someone else (`referred_id` is `UNIQUE` — first
  successful redemption wins, permanently).
- `GET /referrals/top` (admin-only) — top referrers, to see whether the
  mechanic is actually working.

**The referral link reuses the existing `/download` deep-link route**
rather than inventing a second link format — `?ref=CODE` rides alongside
`?type=&slug=` the same way, and on Android it's carried through the
Play Install Referrer mechanism so the code survives a fresh install,
not just an "already have the app" open. **iOS has the same limitation
as the existing deep-link TODO**: without Universal Links or a service
like Branch, a referral code doesn't currently survive a fresh iOS
install — the click is still logged, but the app can't read the code
back out after install completes.

Tested: referral code generation (1000-code sample checked for correct
length, a confusion-safe alphabet with no 0/O/1/I/L, and a low collision
rate) and the redemption logic (valid redemption, self-referral
blocked, double-redemption blocked, invalid code rejected) — all 7
cases pass.

## Phone number visibility

Fixed a real inconsistency: the screen mockups showed a phone number
directly on every public profile, but the actual `GET
/profiles/:type/:id` endpoint never returned `phone` at all — not
publicly, not to a logged-in user, not after a booking. Three sources
(the design, the privacy policy draft, and the code) disagreed with
each other about when a phone number should be visible.

**Resolved as: visible to any signed-in user, not the general public,
subject to a daily quota — not gated behind a specific booking.** The
endpoint uses a new `optionalAuth` middleware (`src/middleware/auth.js`)
— it stays public (no login required to view a profile at all, matching
how search and the public website work), but only includes `phone` in
the response when a valid token was presented. An anonymous request
(including every request from the public website, which never sends a
token) gets everything else on the profile, just not the phone number.

**Daily quota — 5 distinct phone numbers per day**
(`src/utils/phoneReveal.js`): a signed-in learner can see up to 5
different teachers'/institutes' phone numbers per calendar day.
Re-viewing a profile whose phone was already revealed today is always
free — the quota tracks distinct profiles seen today, not total views,
so refreshing the same teacher's page 20 times doesn't burn through it.
The 6th *new* profile in a day gets `phoneLimitReached: true` in the
response instead of a phone number. **Messaging and booking are
deliberately not subject to this limit at all** — a learner can message
or book as many teachers as they want; only the phone-number reveal
itself is quota'd.

Tested: 5 scenarios against a mocked database — the first 5 distinct
teachers all allowed, the 6th correctly blocked, re-viewing an
already-unlocked profile confirmed free, a second student confirmed to
have their own independent quota (not sharing or depleting the first
student's), and the block confirmed stable on a repeat attempt (not
accidentally allowed or double-recorded).

**Note for whoever finalizes the privacy policy**: it currently says
phone numbers "become visible once you accept a booking request" —
that line needs to change to match this actual behavior (visible to
any signed-in user, up to 5 distinct/day) before publishing.

## Safety disclaimer (enforced, not just written)

The "no background checks" warning used to live only in the Terms of
Service — true, but not actually seen by anyone in practice. It's now
an enforced in-app gate, applied to every account, not just minors:

- `GET /auth/safety-disclaimer` — returns the disclaimer text. The
  client should fetch and display this rather than hardcoding a copy,
  so the two can never drift out of sync.
- `POST /auth/acknowledge-safety-disclaimer` (any logged-in user) —
  records the acknowledgment.
- `requireSafetyDisclaimerAcknowledged` middleware
  (`src/middleware/auth.js`) blocks starting a conversation, sending a
  message, or creating a booking until this is set — checked *before*
  the parental-consent check on the same three endpoints, so an adult
  who hasn't acknowledged it is blocked too, not just minors.

Tested: 5 scenarios covering both gates in combination — confirmed an
adult is blocked on the disclaimer alone, a minor is blocked on
whichever of the two isn't satisfied yet, and the disclaimer check
fires before the consent check when both are outstanding.

## Photo moderation

Every new profile photo now goes through a review queue before it's
visible anywhere — the app, the public website, search results, city
feed posts, messages. No automated image-moderation API is wired up
here (that needs a real account/API key decision, not something to
bolt on as a side effect of this pass) — this is a manual admin queue,
the same pattern as Reports and Suggestions.

- `PATCH /auth/avatar` now sets `avatar_status = 'pending'` on every
  new upload, not just `avatar_url`.
- **Enforced at the SQL level, not in application code**: every query
  across the API that selects `avatar_url` (profiles, search results,
  the public website, the message inbox, the city feed) now wraps it in
  `CASE WHEN avatar_status = 'approved' THEN avatar_url ELSE NULL END`
  — a pending or rejected photo cannot leak through any endpoint,
  because there's no code path that selects the raw column anymore.
  Verified with a full repo-wide search after the change: zero
  remaining ungated `avatar_url` selects.
- `GET /moderation/photos/pending` and
  `PATCH /moderation/photos/:userId` (admin-only) — the review queue,
  now in the admin dashboard's "Photos" tab.

Tested: 5 scenarios on the approve/reject flow (pending list accuracy,
approve, reject, rejecting an already-decided photo correctly fails,
reviewing a photo that was never pending correctly fails) and 4 on the
SQL-level gating logic (approved shows, pending/rejected/no-photo all
correctly hidden).

## Localization (8 languages)

Real translation infrastructure, not just a placeholder — 8 languages,
finalized after discussion: **English** (default), **Hindi**,
**Spanish**, **French**, **Arabic**, **Mandarin Chinese**,
**Portuguese**, **Russian**.

- `src/i18n/*.json` — one file per language, 115 keys each (90
  originally, +25 added later for the website's remaining pages — see
  "The entire public website" note below), covering
  navigation, common actions, and every screen described in the design
  spec. **Verified programmatically that all 8 files have identical
  key structure** (zero missing, zero extra) and that every dynamic
  placeholder (`{count}`, `{km}`, `{city}`, `{rating}`) is preserved
  correctly in every translation — a silently-missing key or a dropped
  placeholder in just one language would be very easy to miss without
  this check.
- `GET /i18n/languages` — the list of supported languages with native
  names (हिन्दी, العربية, 中文…) and an RTL flag, for a language-picker
  screen to render from.
- `GET /i18n/:lang` — the full translation dictionary for one language,
  falling back to English for an unrecognized code rather than
  erroring. This is what the (not-yet-built) mobile app fetches.
- `PATCH /auth/preferred-language` — persists a signed-in user's choice
  against their account, so it follows them across devices rather than
  living only in one phone's local storage.
- **`src/middleware/language.js`** — resolves a visitor's language for
  the public website: `?lang=` query param (explicit, always wins) →
  `nt_lang` cookie (a returning visitor's previous choice) → the
  browser's `Accept-Language` header (first visit) → English default.
  Applied globally, so `req.lang` and `req.t` (that language's full
  dictionary) are available in every route without repeating the logic.

**Arabic is genuinely right-to-left, not just translated text** — the
`/download` page (see below) sets `dir="rtl"` on `<html>` based on each
language's `_meta.rtl` flag, not hardcoded.

**What's actually wired up vs. what's just infrastructure**: the
**entire public website is now fully localized end-to-end** — every
page, not just `/download` (see the follow-up section below for how
this was extended). The mobile app itself doesn't exist as code yet
(see "known open items"), so the website is genuinely all that's
buildable right now — but a real mobile app can use `GET /i18n/:lang`
directly with no additional backend work once it exists.

Tested end-to-end over real HTTP, not just unit-style: default English
rendering, Hindi via `?lang=hi` with real translated content, Arabic
correctly rendering `dir="rtl"` with real Arabic text, an unsupported
code gracefully falling back to English instead of erroring, and the
language-picker dropdown containing all 8 options.

**The entire public website is now fully localized end-to-end** — not
just `/download`. Every page (`city-directory`, `city-index`,
`institute`, `teacher`, `feed`, `not-found`) uses the same pattern:
`t.*` for every string, `dir="rtl"` driven by each language's
`_meta.rtl` flag, and correct `lang` attribute. This required adding
25 more keys beyond the original 90 (now 115 total) to cover strings
specific to these pages — added to **all 8 languages at once** via a
script, then re-verified with the same completeness check: zero
missing/extra keys, every placeholder consistent, across all 115 keys
in all 8 files.

**One deliberate simplification, stated plainly rather than hidden**:
count-bearing strings (e.g. "12 listings") use a single noun form
regardless of count, not proper per-language pluralization rules.
Building real pluralization is substantial scope on its own — English
has 2 forms, Arabic has 6, Russian has 3, Chinese has 1 — and doing it
inconsistently across only some strings would be worse than being
consistent about not having it yet.

**Real bug caught during this pass**: `city-index.ejs` originally used
`t` as a loop variable name inside `teachers.forEach(function(t) {...})`
— which would have silently shadowed the `t` translations object inside
that block. Renamed to `teacher` before it became a real bug, not after.

Tested: 16 full template renders across multiple languages (English,
Hindi, Arabic, Mandarin, French, Portuguese, Russian) covering every
page, RTL layout, empty-state variants (no cities, no posts, no
institutes), and edge cases (free pricing, no trial badge, BSL
verified badge with a formatted date) — all passing. Followed by a
targeted content-correctness check confirming placeholders are
genuinely substituted (not left as literal `{city}` text in the
output) and that `dir="rtl"` is set only for Arabic, never for the
other 7 languages.

## Admin message visibility (a deliberate, disclosed decision)

BSL admins can view **any conversation, at any time** — not limited to
reported conversations. This was a real decision between two options:
report-triggered-only access (narrower, the initially recommended
default) versus full visibility (broader, chosen instead). Given that
teachers/institutes aren't background-checked and the platform likely
serves minors, full visibility was picked as an additional safety
measure — but it's a genuine privacy trade-off, not a free action, so
it comes with real accountability built in, not just an API endpoint:

- `GET /moderation/conversations` (admin) — lists every conversation
  with participant names, message count, last activity.
- `GET /moderation/conversations/:id/messages` (admin) — returns the
  full message history for one conversation. **Every successful call
  writes a row to `admin_message_access_log`** (who, which
  conversation, when) *before* returning the messages — this can't be
  silently skipped, and there's no way to view messages through this
  route without it being recorded.
- `GET /moderation/bookings` (admin) — the same visibility extended to
  booking requests ("applying for a trainer"), not just messaging —
  the full list of every booking request platform-wide (who requested
  a lesson with whom, subject, status). Logged per list-view
  (`admin_booking_access_log`), not per-booking — a booking request is
  structured data someone browses as a list, not an open-ended private
  thread someone opens one at a time, so the audit granularity matches
  how the data is actually consumed.
- Now in the admin dashboard's "Messages" and "Bookings" tabs.

Tested: viewing an existing conversation, a nonexistent conversation
404ing cleanly, exactly one audit-log entry written for a successful
view (not for the failed one), a second admin's view logged as its own
separate entry, and the same three properties verified again for the
bookings-list endpoint.

**This is disclosed prominently in both the Terms of Service and
Privacy Policy** — not a quiet backend capability. If this ever changes
(e.g. moving to report-triggered-only access later), both documents
need updating to match, the same discipline applied everywhere else in
this codebase between what's built and what's documented.

## Contact frequency ranking

`GET /moderation/contact-stats` (admin) — two ranked lists, most to
least: which teachers/institutes have been contacted by the most
distinct students, and which students have contacted the most distinct
teachers/institutes. Counts **distinct conversations**, not raw message
volume — a thread with 200 messages and a thread with 2 both count as
1 "contact." Now in the admin dashboard's "Contact Stats" tab, shown as
two side-by-side ranked lists.

This is aggregate/statistical data (names and counts), not message
content — the same sensitivity tier as the existing Analytics tab, not
the conversation/booking content endpoints above, so it doesn't carry
the same per-view audit log. Tested: correct descending order, correct
counts per person, ties broken alphabetically, and every teacher/
student with at least one conversation present in their respective
ranking (none silently dropped).

## Account suspension

The other half of the moderation tooling above — full visibility into
messages/bookings/contact patterns is only useful if admin can act on
what they find.

- `POST /moderation/users/:userId/suspend` `{ reason }` (admin) —
  suspends an account. An admin cannot suspend themselves (a real
  footgun otherwise: locking out the only person able to undo it).
- `POST /moderation/users/:userId/unsuspend` (admin) — restores it.
- `GET /moderation/users/suspended` (admin) — the current list, with
  who suspended whom, when, and why.
- **Enforced in `requireAuth` itself** (`src/middleware/auth.js`), not
  per-route — a suspended user is blocked from *every* authenticated
  action platform-wide, not just messaging/booking. This matters more
  than it might otherwise because tokens never expire (see
  "Authentication" above): without this check living in the shared
  verification path, a suspended user's still-valid token would
  continue working everywhere except the specific routes someone
  remembered to gate. `optionalAuth` (used for public profile viewing)
  was also fixed to treat a suspended user's token as logged-out rather
  than granting authenticated privileges like the phone-number reveal.
- Admin dashboard: a contextual "Suspend [name]" button right on the
  conversation detail view (the natural point of action — admin is
  already reading a concerning conversation), plus a dedicated "Users"
  tab for the suspend form and the list of currently-suspended accounts.

Tested: a normal user passes the auth check, suspending blocks them
immediately even with an otherwise-valid token, the exact reason is
returned to them, self-suspension is rejected, unsuspending restores
access, and suspending a nonexistent user 404s cleanly.

## Locale-aware dates &amp; prices

Every price and date on the public website used to be formatted one
way regardless of language — `£22/hour` and `Sep 20, 2026` for every
visitor, even in French or Arabic. Fixed using the standard `Intl`
API (`src/i18n/format.js`) rather than hand-rolled per-language rules
— the same approach a real production app or backend should use.

**These are genuine, verified differences, not cosmetic**:
English/Hindi/Chinese use a 12-hour clock with AM/PM; Spanish, French,
Portuguese, and Russian use 24-hour; Arabic uses 12-hour with its own
markers (`ص`/`م`). Currency formatting differs in decimal separator
(`.` vs `,`) and symbol placement/style — confirmed by actually
running `Intl.NumberFormat` for GBP 22 in all 8 languages before
writing any code, not assumed: `£22.00` (en) vs `22,00 £GB` (fr) vs
`‏22.00 UK£` (ar) vs `22,00 £` (ru).

- `formatPrice(amount, currency, lang)` — a fully-formatted price
  string, used to replace the old `{currency} {rate}/hour` template
  pattern. That pattern's translation keys (`website.priceHour`,
  `website.fromPriceHour`) were changed to a single `{price}`
  placeholder across **all 8 languages**, re-verified with the same
  key-completeness/placeholder-consistency check used throughout this
  project — still 115 keys, still zero mismatches.
- `formatWeekdayAndTime(date, lang, timeZone)` — used for booking
  times; includes one language-specific correction `Intl` doesn't make
  on its own: French conventionally writes clock time as `16h00`, not
  `16:00` — handled explicitly rather than silently shipping the wrong
  convention.
- `formatDate(date, lang, style)` — used for the "BSL Verified since"
  badge and city-feed post timestamps.

Wired into `institute.ejs`, `teacher.ejs`, and `feed.ejs` — every
place a price or date was previously hardcoded to English formatting
conventions. Tested by actually rendering each template in multiple
languages and checking the real output, not just that the functions
return *something*: confirmed `142 lessons completed · £22.00/hour`
(en) becomes `142 cours terminés · 22,00 £GB/heure` (fr) and `142
درسًا مكتملًا · ‏22.00 UK£/ساعة` (ar) — correct translation, correct
number formatting, and correct RTL mark all present together.

**Scope note**: this covers the public website (server-rendered EJS,
real running code). The mobile app itself doesn't exist as code yet —
once it does, React Native's JS engine has the same `Intl` API built
in, so the identical functions in `format.js` are the pattern to reuse
there, not something that needs reinventing.

## Local currency by country (no universal default)

Real bug, found and fixed: every teacher/institute profile previously
defaulted to `INR` regardless of where they actually were — the
schema's `DEFAULT 'INR'` was never overridden by anything based on the
teacher's real country. A teacher in France or the UAE would show
prices in Indian Rupees unless they happened to manually correct it.
Same class of problem as defaulting everyone to USD — just with a
different currency doing the imposing.

- `src/utils/currency.js` — a real country → currency mapping (ISO
  3166-1 country code → ISO 4217 currency), covering the countries
  realistically relevant given the platform's 8 supported languages
  and likely early markets — not exhaustive of all ~180 ISO countries,
  and says so in its own comment, with a fallback (`USD`, used only
  for a genuinely unmapped country code — not imposed as a default)
  rather than crashing on an unrecognized code.
- `PUT /profiles/me` now derives the effective currency itself: an
  **explicit currency from the client always wins** (a teacher can
  still deliberately quote in a different currency if they want — this
  isn't a restriction, just a sensible default); otherwise, if a
  country is being set, the currency is derived from it; if neither is
  present in the request, the existing value on the row is preserved
  (so updating just a bio doesn't accidentally reset an already-correct
  currency).

**This forward fix doesn't reach data that already exists** — a
profile created before it shipped, whose owner never happened to touch
the currency field, is still sitting on the old `INR` default
regardless of their real country. `scripts/backfill-currency.js`
one-time-corrects those specific rows:

```
npm run backfill:currency          # dry run — reports what WOULD change, writes nothing
npm run backfill:currency:apply    # actually applies it
```

**Deliberately narrow targeting**, not a blanket re-check of every
row: only touches a profile where `currency = 'INR'` AND a non-Indian
`country_code` is on file — the specific fingerprint of "still on the
old buggy default," not just "any currency that happens to look
questionable." An Indian teacher's `INR` is already correct and is
left alone either way. **Known limitation, stated rather than hidden**:
this can't perfectly distinguish "never touched the field" from "a
French teacher who deliberately chose INR" — those look identical in
the data. Given INR isn't a common deliberate cross-border pricing
choice, the bug is the far more likely explanation for any matched
row, but every change is logged (old currency, new currency, whose
profile) specifically so it can be reviewed and manually reverted if a
genuine exception turns up.

Tested: 8 scenarios against simulated data — correctly identifies only
the bug-fingerprint row, computes the right replacement currency,
dry-run mode provably doesn't mutate anything, apply mode does, an
already-correct India/INR row is left untouched, already-non-INR rows
(already fixed or deliberately different) never even enter the
affected set, a profile with no country on file is left alone (nothing
to derive from), and — importantly — running the script a second time
after applying finds nothing left to fix (idempotent; safe to re-run).

Tested: 9 scenarios — known countries map correctly (India→INR,
France→EUR, Saudi Arabia→SAR, Brazil→BRL, etc.), case-insensitive
lookup, an unmapped country code falls back without crashing, no
country code returns `null` rather than guessing, and the full
decision logic: explicit currency wins, country-derived currency
applies when no explicit choice is made, neither-present preserves the
existing value, and a teacher can still deliberately choose a
non-local currency if they want to.

## Contact BSL (support tickets)

The Settings screen's "Contact BSL" is a real, working feature — distinct
from `/safety/reports` (which flags *another user*, a post, or a
comment). This is a direct line to BSL as the platform operator: billing
questions, legal requests, account issues, or general confusion that
isn't about any specific other user.

- `POST /support` `{ subject, message }` (any logged-in user) — creates
  a ticket and emails `SUPPORT_EMAIL` immediately, so a ticket doesn't
  just sit unseen in the database until someone happens to check the
  admin dashboard.
- `GET /support/mine` — a user's own ticket history, so the Contact BSL
  screen can show past conversations instead of a blank form every time.
- `GET /support` and `PATCH /support/:id/status` (admin-only) — the
  review queue, in the admin dashboard's "Support" tab, same pattern as
  Reports and Suggestions (open / in progress / resolved).

Set `SUPPORT_EMAIL` in `.env` to the inbox that should receive these.

## Authentication

Two login paths, both landing on the same `req.user` in every route:

**Google sign-in** — unchanged: the app authenticates with Firebase Auth
client-side, sends the resulting Firebase ID token, and
`middleware/auth.js` verifies it against Firebase.

**Phone/OTP sign-in** — a custom path, not Firebase Phone Auth (see the
cost note below for why):
1. `POST /auth/otp/request` `{ phone }` — validates the number against
   real per-country rules (`src/utils/phone.js`, via `libphonenumber-js`
   — correct digit count, valid area/prefix patterns per country, not a
   generic regex that would accept a too-short number as "plausible"),
   normalizes it to E.164, generates a 6-digit code
   (`crypto.randomInt`, not `Math.random`), stores only its bcrypt hash
   (`otp_codes` table), and sends it via the routed provider below.
   Rate-limited to 3 requests per phone per 10-minute window
   (`src/utils/otp.js`).
2. `POST /auth/otp/verify` `{ phone, code }` — checks the code against
   the most recent unconsumed, unexpired hash for that phone. Capped at
   5 wrong attempts per code (brute-forcing a 6-digit code without this
   cap would be feasible). On success, finds or creates the user by
   phone and returns our own JWT (`JWT_SECRET`). **The token never
   expires on its own** — by design, no automatic logout, only explicit
   (the client deletes the stored token when the user taps "Log out").
   **Real tradeoff, not a free lunch**: a leaked or stolen token stays
   valid forever, and there's currently no server-side way to revoke
   one early — no session table, no remote "log out other devices."
   That's the same gap already flagged in "Known open items" below;
   the non-expiring choice makes closing it more worth prioritizing,
   not less.
3. Every other route's `requireAuth` middleware tries this JWT first
   (fast, local, no network call), and falls back to Firebase
   verification if that fails — so the rest of the API never needs to
   know which login method a given user used.

**International numbers:** SMS is routed by country
(`src/utils/sms.js`) — **India (+91) goes through MSG91**
(cheap, but MSG91's DLT-registered-template requirement is an
India-specific SMS regulation that doesn't apply or reliably deliver
elsewhere), and **every other country goes through Twilio** (broad,
reliable international coverage, at a higher per-message cost — but
only non-India traffic pays that premium). The country used for routing
comes from the same validation that normalizes the number, so routing
and validation can never disagree about which country a number belongs
to.

**Why not just use Firebase Phone Auth for OTP too?** Cost, at scale.
Firebase charges per SMS *sent* (not just successful logins) — $0.07 per
SMS in India as of mid-2026, and requires the paid Blaze plan with no
meaningful free tier. MSG91 runs roughly ₹0.18–0.25 (~$0.008) per SMS in
India — about 9x cheaper. Given BSL's existing user base, OTP volume
could get large fast, so this was built as the real cost-effective path
rather than left as a someday-optimization. **Before this works**,
MSG91 needs a DLT-registered sender ID and an approved message template,
and Twilio needs a verified sender number capable of international SMS
— see `.env.example` for the variables needed for each. Swap either
provider's internals in `src/utils/sms.js` for a different one (Vonage,
Gupshup, Sinch, etc.) without touching routes or `otp.js` — they only
call `sendOtpSms(e164Phone, code, country)`.

**Not yet built:** a periodic cleanup job for expired/consumed
`otp_codes` rows — the table will grow unbounded without one. A simple
`DELETE FROM otp_codes WHERE expires_at < now() - interval '1 day'` on a
daily cron is enough. Also not built: WhatsApp-based OTP delivery, which
runs cheaper than SMS in most regions ($0.0014/message in India vs
MSG91's ~$0.008) and tends to have better delivery reliability since
it's app-based rather than carrier-based — worth considering as a
future addition, not a blocker for launch.

## Legacy Express stack

- **Runtime**: Node.js + Express
- **Database**: PostgreSQL with the PostGIS extension (radius/geo search)
- **Auth**: Google sign-in via Firebase Auth (client-side, verified server-side
  against Firebase); phone/OTP via a custom path — MSG91 for SMS, our own
  JWT for sessions. See "Authentication" below for the full picture.
- **Messaging**: table-based reference implementation included
  (`src/routes/messages.routes.js`) — recommended to swap for a managed
  chat SDK (Stream Chat or Sendbird) before launch for reliable delivery,
  push notifications, and typing indicators
- **Media storage**: not included here — wire up S3 or Cloudflare R2 and
  store the resulting URLs on `posts.image_url` / `users.avatar_url`
- **Push notifications**: not included here — wire up Firebase Cloud
  Messaging using the tokens the mobile app registers
- **Email notifications**: included (`src/utils/email.js`, nodemailer over
  SMTP — works with SendGrid, Postmark, AWS SES's SMTP interface, or any
  standard provider). Sent on: a new booking request (to the provider),
  a booking accepted/declined (to the learner), and a reply to a comment
  (to the original commenter). Failures are logged, never thrown — a
  bounced email never fails the API request that triggered it. Set
  `SMTP_HOST` in `.env` to enable; left unset, sends are skipped with a
  console warning rather than erroring.

## Legacy Express setup

1. Install PostgreSQL with PostGIS available (e.g. via a managed Postgres
   provider that supports the `postgis` extension, or a local install).
2. Copy `.env.example` to `.env` and fill in:
   - `DATABASE_URL` — your Postgres connection string
   - `JWT_SECRET` — any long random string (reserved for future use if you
     add your own session tokens; not currently required since we verify
     Firebase tokens directly)
   - `FIREBASE_SERVICE_ACCOUNT_JSON` — the JSON key for a Firebase service
     account with Auth Admin permissions, as a single-line string
3. Install dependencies:
   ```
   npm install
   ```
4. Create the schema and load seed data:
   ```
   psql "$DATABASE_URL" -f src/db/schema.sql
   psql "$DATABASE_URL" -f src/db/seed.sql
   ```
5. Run the server:
   ```
   npm run dev
   ```
   The API listens on `PORT` (default 4000). Check `GET /health`.

## Public website

Alongside the mobile app, this backend also serves a small public,
server-rendered website — no login required, meant to be indexed by
search engines:

- **`/site/institute/:slug`** — an institute's public landing page. The
  slug is generated automatically (`src/utils/slug.js`) the first time
  an institute publishes their profile (`PUT /profiles/me` in
  `profiles.routes.js`), from their name and city. It's stable once set
  — later profile edits don't change the URL.
- **`/site/teacher/:slug`** — the same pattern for individual teachers.
- **`/site`** — the top-level directory: every city with at least one
  published listing, linking into that city's index page. This is the
  actual entry point a search engine or first-time visitor starts from;
  without it, `/site/city/:city` pages are only reachable if you already
  know the city name.
- **`/site/city/:city`** — the SEO crawl path: lists every published
  teacher and institute in a city (grouped, each linking to their own
  page), the subjects taught there, and a link to the city's feed.
  Without this page, an institute or teacher's page can only be found by
  someone who already has the exact URL — this is what lets a search
  engine actually discover them.
- **`/site/feed/:city`** — a read-only view of that city's feed
  (`src/views/feed.ejs`). Visitors can read offers and requests; liking,
  commenting, and posting all require the app.

All four page types cross-link to each other (institute/teacher pages
link to their city index and feed; the feed links back to the city
index) so a crawler — or a visitor — can navigate between them.

**Public pages intentionally show less than the app.** No phone number,
no WhatsApp link, no instructor names — bio, subjects, ratings, and
badges are the extent of it. The only action on a public page is
"Download the app to message or book." This was a deliberate product
decision: these pages are indexed by anyone, permanently, with none of
the vetting a signed-in app session implies, so they intentionally don't
carry the same contact exposure the in-app profile does.

Views live in `src/views/` (EJS templates) and are wired in
`src/app.js` via `app.set('view engine', 'ejs')`. `/` and `/download`
are currently placeholder stubs — replace with a real marketing
homepage and app-store redirect before launch.

**Deliberately deferred, not forgotten — revisit before wider launch:**
- Fraud/impersonation safeguards for public pages (e.g. someone
  registering a page under a name resembling an existing brand) —
  explicitly punted to let the app grow first; revisit before the
  website gets meaningful traffic
- A "report this page" link on public pages themselves — same reasoning;
  in-app reporting exists today, but a visitor who never opens the app
  currently has no way to flag a page
- Sitemap generation for SEO

## BSL Verified badge

A second, higher-trust badge distinct from the regular Verified badge —
reserved for institutes BSL directly operates. Franchise and MCP partner
institutes are explicitly excluded, even if they hold the regular
Verified badge.

Two-step admin process (`institute_profiles.institute_type` and
`is_bsl_official`, both in `profiles.routes.js`):
1. `PATCH /profiles/institute/:id/type` — classify the institute as
   `independent`, `franchise`, `mcp_partner`, or `bsl_direct`.
2. `PATCH /profiles/institute/:id/bsl-verified` — grants (or revokes) the
   badge. Granting is rejected server-side (400) unless the institute is
   classified `bsl_direct` — this is enforced in the API, not just the
   admin UI, so it can't be bypassed by calling the endpoint directly.

Both steps are available in the admin dashboard's "BSL Verified" tab.

## Deep-link-aware download route

Every "Download the app" link on the public website points at
`GET /download?type=institute|teacher|feed&slug=...` (in `src/app.js`).
It detects the visitor's platform from the User-Agent and:
- **Android**: redirects to `ANDROID_PLAY_STORE_URL` with the deep-link
  target carried through via the Play Install Referrer mechanism
  (`&referrer=deep_link_path=...`) — the app reads this on first open via
  the Play Install Referrer API to land the person on the exact page they
  came from, not just the home screen.
- **iOS**: redirects to `IOS_APP_STORE_URL`. Deep-link-through-install
  isn't wired up yet — iOS has no equivalent to Android's referrer
  mechanism; that needs Universal Links (an `apple-app-site-association`
  file on this domain) or a service like Branch/Firebase Dynamic Links.
  Marked with a `TODO` in `src/app.js` where that integration goes.
- **Desktop / undetected**: shows `src/views/download.ejs`, a simple page
  with both store links, since guessing wrong would be worse than asking.

Every click is logged (see Analytics below) with the detected platform,
so you can see the iOS/Android/desktop split over time.

Set `IOS_APP_STORE_URL` and `ANDROID_PLAY_STORE_URL` in `.env` — both
listings are already live, so this just needs the real URLs dropped in.

## Analytics

Lightweight page-view logging (`page_views` table, `src/utils/analytics.js`)
on every public page and every download click — deliberately simple (one
table, no sessions/cookies) rather than a full analytics stack. Logging
is fire-and-forget: a logging failure never slows down or breaks the
page request it's attached to.

`GET /analytics/summary` (admin-only, `src/routes/analytics.routes.js`)
returns, for a configurable window (`?days=30` by default):
- Total views by page type
- Top institute/teacher pages by views
- Top cities by views
- Download clicks broken down by platform (ios/android/desktop)

Available in the admin dashboard's "Analytics" tab.

**The same `page_views` table also backs in-app view tracking**: every
time a learner opens a teacher or institute profile inside the mobile
app (not just the public website), it's logged as `page_type =
'app_profile'`. `GET /profiles/me/stats` (any logged-in teacher or
institute) reads this back as a real "profile views" count for the
Requests dashboard screen — windowed to the last 30 days by default
(`?days=`, capped at 365) — alongside a real "new requests" count from
pending bookings. Neither of these are placeholder numbers.

## Admin dashboard

The Laravel admin portal is served at `/admin`. Configure `ADMIN_EMAIL`
and `ADMIN_PASSWORD_HASH` in `.env` before granting access. The portal
currently manages subjects, students (`learner` accounts), teachers, and
institutes with add, edit, list, and delete operations. The legacy Express
moderation dashboard is retained at `public/legacy-admin.html` while
its API routes are being ported.

## Legacy Express project layout

```
public/
  legacy-admin.html     Legacy Node admin dashboard (reports, suggestions, verification)
src/
  views/                 EJS templates for the public website (city directory, institute page, teacher page, city index, city feed, 404)
  app.js               Express app, mounts all routes
  server.js             Entry point
  db/
    pool.js             Shared Postgres connection pool
    schema.sql           Full database schema (run once)
    seed.sql              Subject taxonomy seed data, BSL courses flagged
  middleware/
    auth.js              Verifies Firebase ID tokens, loads/creates the user
    admin.js              Gates admin-only routes behind users.is_admin
    language.js            Resolves a visitor's language: ?lang= → cookie → Accept-Language → English
  routes/
    auth.routes.js        Session bootstrap, phone/OTP login, account linking, account type selection
    profiles.routes.js     Teacher/institute profile create & view, BSL Verified classification/grant
    search.routes.js       Radius search (home + search/map screens)
    messages.routes.js     Conversations and messages
    bookings.routes.js     Request / accept / decline / dashboard
    reviews.routes.js      Post-lesson reviews, rating aggregation
    feed.routes.js         City feed: posts, likes, comments
    safety.routes.js       Report and block
    subjects.routes.js     Course catalog (BSL-featured + full taxonomy)
    suggestions.routes.js  Missing course/city/state/country suggestions
    support.routes.js      Contact BSL support tickets
    referrals.routes.js    Referral code generation, redemption, top-referrer admin view
    moderation.routes.js   Photo moderation review queue (admin)
    i18n.routes.js          Translation dictionaries (GET /i18n/languages, GET /i18n/:lang)
  i18n/
    format.js               Locale-aware price/date formatting (Intl-based, all 8 languages)
    website.routes.js      Public institute/teacher pages, city index, and public city feed (server-rendered)
    analytics.routes.js    Admin-only page-view/download summary
  utils/
    email.js               Email notifications (nodemailer over SMTP)
    slug.js                 Public-page URL slug generation
    referral.js             Referral code generation (confusion-safe alphabet)
    age.js                  Age classification and minor/parental-consent thresholds
    phoneReveal.js          Daily quota (5/day) on distinct phone-number reveals
    currency.js             Country → local currency mapping (no universal default)
    analytics.js            Page-view logging and platform detection
    otp.js                  OTP generation, hashing, rate limiting, brute-force cap
    sms.js                  OTP SMS delivery, routed by country (MSG91 for India, Twilio elsewhere)
    phone.js                 International phone validation and E.164 normalization
```

## Notes for whoever picks this up

- **This is a working reference implementation, not a production-hardened
  API.** It covers the core data model and the main flows end to end, but
  before launch it needs: input validation (e.g. with `zod` or `joi`),
  rate limiting, pagination on list endpoints, proper handling of blocked
  users (currently `blocks` table exists but isn't yet enforced in query
  filters), and tests.
- **No background-check / verification flow is implemented.** The
  product now shows only the BSL Verified badge (institute-only, see
  above) — the `is_verified`/`verified_at` fields and their
  `PATCH /:type/:id/verify` endpoint still exist in the schema and API
  but are no longer surfaced anywhere in the app, website, or admin
  dashboard, kept only in case a broader verification tier is
  reintroduced later. A real ID-upload/review-queue verification process
  still needs to be designed and built if you want one. See the product
  brief's flagged risk on this point.
- **Messaging is a minimal reference model.** Swap for a managed chat SDK
  before launch, as noted above.
- Every endpoint that should require login uses the `requireAuth`
  middleware — grep for routes without it if adding new ones, to avoid
  accidentally leaving something open.
