# Laravel Mobile API

App team handoff (updated 21 October 2026): [App integration guide](APP_TEAM_API.md)
and [complete mobile Postman collection](postman/app-team.postman_collection.json).
Saved response examples for every mobile request are included in the collection
and in [API mock response examples](API_MOCK_RESPONSES.md).
Start with the handoff for screen flows, request/response examples, validation,
error handling and current integration limitations.

This reference describes the Laravel routes registered in `routes/api.php`.
The legacy Express API has separate routes and is not covered here.

## Base URL and requests

Use the Laravel application's base URL (the `APP_URL` environment setting),
for example `https://api.example.com`. Routes intentionally keep their
existing paths and do not use an `/api` prefix.

Send JSON request bodies with:

```http
Content-Type: application/json
Accept: application/json
```

For authenticated routes, send the Laravel-issued token as a bearer token:

```http
Authorization: Bearer <token>
```

Phone numbers must be valid international numbers including country code, such
as `+919876543210`. User, profile, booking, post, conversation, and comment
identifiers are UUIDs unless a route specifies a numeric subject ID. Provider
paths and `providerId` fields take the provider **user UUID** (`user_id` from
search), not the listing row ID. Creation endpoints return `200`. Request fields
usually use camelCase; raw record response fields usually use snake_case.

## Authentication and account setup

OTP codes are returned only when `OTP_TEST_MODE=true` in local/testing environments. With `OTP_DELIVERY_CHANNELS=sms,whatsapp`,
the same code is submitted to both channels. Responses include `channels` statuses:
`200` when all are accepted, `202` with `partialDelivery:true` when one succeeds,
and `503` when neither succeeds. Accepted means gateway acknowledgement, not confirmed
handset delivery. Local/testing `OTP_TEST_MODE=true` skips delivery and includes `testMode:true` and `otp` in JSON. Production never exposes
the code. See [dual-channel setup](OTP_DELIVERY.md).

| Method | Path | Access | Request | Result / notes |
|---|---|---|---|---|
| `POST` | `/auth/google` | Public | `firebaseIdToken` | First step for Google login: verifies a Firebase Google sign-in and verified email; returns `token`, `user`, `phoneVerificationRequired`. |
| `POST` | `/auth/otp/request` | Public | `phone` (string) | After Google sign-in, requests an OTP for phone verification. Returns `ok`, `expiresInMinutes`. Up to 3 codes per phone per 10 minutes; codes expire after 10 minutes. |
| `PATCH` | `/auth/link-phone` | Google bearer token | `phone`, `code` (6 digits) | Verifies and links the OTP phone to the Google account. |
| `POST` | `/auth/otp/request` | Public | `phone` (string) | Registration phone verification: generates/sends an OTP; returns `ok`, `expiresInMinutes`. |
| `POST` | `/auth/otp/verify` | Public | `phone`, `code` (6 digits) | Registration phone verification: verifies the code and returns `token`, `user`, `mobileVerified`. Up to 5 code attempts. |
| `POST` | `/auth/login` | Public | `email`, `password` (phone prohibited) | Email-only password login. Registered accounts use this instead of mobile OTP. Returns `token`, `user`, `mobileVerified`. |
| `GET` | `/auth/session` | Bearer token | — | Returns the authenticated `user` and `mobileVerified`. Allowed before phone verification so Google users can complete linking. |
| `GET` | `/auth/onboarding` | Bearer token | — | Welcome / complete-profile draft. Returns `mobileVerified`; `canSubmit` stays false until that tag is true. Allowed before phone verification so the client can show the verified-phone gate. |
| `PATCH` | `/auth/onboarding` | Registered, verified bearer | Partial profile fields below | Autosave only after registration. Returns `409` before registration. |
| `POST` | `/auth/register` | Verified bearer | Full registration form below | Saves registration once. Returns `409` if already registered. |
| `POST` | `/auth/onboarding/complete` | Verified bearer | Full registration form below | Alias of `/auth/register`; same validation and behavior. |
| `POST` | `/auth/account-type` | Verified bearer | `accountType`: `learner`, `teacher`, or `institute` | Sets account type; creates an empty provider profile for teachers/institutes. |
| `PATCH` | `/auth/preferred-language` | Verified bearer | `language`: `en`, `hi`, `es`, `fr`, `ar`, `zh`, `pt`, or `ru` | Updates the account language. |
| `PATCH` | `/auth/avatar` | Verified bearer | `avatarUrl` (URL) | Updates the photo URL and marks it pending moderation. |
| `PATCH` | `/auth/link-phone` | Bearer token | `phone`, `code` (6 digits) | Verifies and links the phone for an account; this route is available before phone verification. |
| `PATCH` | `/auth/link-google` | Verified bearer | `firebaseIdToken` | Links a verified Google identity to the signed-in account. |
| `POST` | `/auth/date-of-birth` | Verified bearer | `dateOfBirth` (`YYYY-MM-DD`); for ages 13–17 also `guardianName`, `guardianContact` | Rejects users under 13. Minors need guardian consent before messaging/booking. Guardian email delivery must be configured; phone-contact SMS consent delivery is not implemented. |
| `POST` | `/auth/parental-consent/confirm` | Public, one-time token | `token` | Confirms guardian consent; returns `ok`, `confirmedFor`. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |

### Email password recovery

`POST /auth/forgot-password` accepts `{email}` and returns a generic success
for known and unknown addresses. `POST /auth/reset-password` accepts
`{email, code, password, password_confirmation}`. Codes are emailed, hashed
in the OTP table, expire after 10 minutes, permit five attempts and are
single-use. Resending invalidates the previous code. The mobile
`OTP_TEST_MODE` never exposes email recovery codes.

After reset, prior Laravel tokens are revoked, including legacy OTP tokens.
Existing Socket.IO sessions are disconnected through the broadcasts queue;
newly authenticated sessions are retained. Log in again using email and the
new password. Stale Firebase authentication sessions also require reauthentication.

Authenticated email verification endpoints:
`POST /auth/email/verification/request` with `{}` and
`POST /auth/email/verification/verify` with `{code}`. A successful password
reset also verifies the email. Changing the profile email clears verification.
Existing users may continue email/password login before verification;
verification is not a new login gate. See [EMAIL_AUTH.md](EMAIL_AUTH.md).

### Google sign-in followed by phone OTP

Call these requests in order. Obtain the Firebase ID token from the mobile
client's Firebase Google sign-in SDK.

```json
// 1. POST /auth/google
{
  "firebaseIdToken": "<Firebase Google ID token>"
}

// 2. POST /auth/otp/request
{
  "phone": "+919876543210"
}

// 3. PATCH /auth/link-phone
// Authorization: Bearer <token returned by /auth/google>
{
  "phone": "+919876543210",
  "code": "123456"
}
```

If step 1 returns `phoneVerificationRequired: false`, skip steps 2 and 3.
Otherwise, use the Google response's `token` as the bearer token for step 3.
`POST /auth/otp/verify` also links the phone when called with the existing
Google bearer. It returns no replacement token; retain the Google bearer.
Without an Authorization header it performs normal signup and issues a new token.

### Phone OTP verification for registration

Normal signup sends no Authorization header to OTP request or verification.
Save the token returned by verification as the single active bearer.
An invalid supplied bearer returns `403`; a non-Google bearer at OTP verification
returns `409` (`OTP_BEARER_NOT_GOOGLE`) without issuing a token.

```json
// 1. POST /auth/otp/request
{"phone":"+919876543210"}

// 2. POST /auth/otp/verify
{"phone":"+919876543210","code":"123456"}
```

Example Google sign-in response shape:

```json
{
  "token": "<bearer-token>",
  "user": {},
  "phoneVerificationRequired": true
}
```

When `phoneVerificationRequired` is `true`, obtain an OTP and call
`PATCH /auth/link-phone` with the token returned by Google sign-in.
This implementation supports Google sign-in with phone verification,
phone-number verification during registration, and email/password login after the user
registers through `POST /auth/register`.

### Registration page (Student / Teacher / Institute)

Complete run instructions, curl requests for every role, and expected errors:
[Registration API guide](REGISTRATION.md).
Importable Postman collection: [Registration collection](postman/registration.postman_collection.json).

Keep the form in client state before registration. The **Send OTP** button
below the phone input calls `POST /auth/otp/request`. Verify the code on the
same page with `POST /auth/otp/verify`; retain the returned bearer token.
If the user changes the phone input, clear verification in the UI and verify
the new number before submitting. The number shown must match the verified
`user.phone` returned by the server. Do not include `phone` in the registration
body: registration uses the verified phone belonging to the bearer token.

OTP verification creates a minimal phone identity for new numbers; it does
not save the registration form or enable autosave. For an already registered
phone, restore the account using `GET /auth/onboarding` and continue to the
app instead of submitting registration again. Google users use
`PATCH /auth/link-phone` with their Google bearer token for inline verification.

On **Register / Continue**, send the full form to `POST /auth/register`.
The existing `POST /auth/onboarding/complete` is an alias. Required fields:

| Field | Student | Teacher | Institute |
|---|---|---|---|
| `accountType` | `student` (stored as `learner`) | `teacher` | `institute` |
| `fullName` | Your name | Your name | Institute name |
| `email` | Required | Required | Required |
| `password` | 8–72 characters | 8–72 characters | 8–72 characters |
| `termsAccepted` | `true` | `true` | `true` |
| `acknowledgeTeachersNotBackgroundChecked` | `true` | `true` | `true` |
| `city` | — | Required | Required |
| `primarySubjectId` | — | Required; from `GET /subjects` | — |
| `contactPerson` | — | — | Required |

Passwords are hashed and never returned. Successful registration returns
`registrationCompleted: true`, `profileCompleted: true`, and
`autosaveEnabled: true`. Students continue to `POST /auth/date-of-birth`;
teachers/institutes receive `nextStep: "home"`.

For accounts linked to Google, `email` and `password` are optional when creating
the account (`POST /auth/register` or `/auth/onboarding/complete`). Omit them to
keep the verified Google email and continue without setting a password. Supplied
values still undergo validation. Mobile verification and the other required
registration fields still apply. Regular signup requires email and password.

After registration, `PATCH /auth/onboarding` accepts partial profile changes.
Omitted fields are preserved; null clears text fields; an empty password
leaves the existing password unchanged. Teacher/institute city, contact and
subject details survive account-type changes; name/email/password/checkboxes
are shared. Provider listing fields use `PUT /profiles/me` after registration.
Debounce autosave by about 500ms and serialize requests. Restore saved values
using `GET /auth/onboarding`. `draft.passwordSet` indicates a saved password.

Before registration, PATCH returns `409` with `registrationRequired: true`
and does not save form values. Without phone verification, writes return
`403`. Registration without a bearer token returns `403` with
`code: "OTP_VERIFICATION_REQUIRED"` and a message to verify OTP first;
the response includes public OTP request/verification endpoints. Invalid
registration tokens return `401` with the same code. Show the message below
the mobile input and verify OTP on the same page before retrying Register.
Failed registration returns `422` with field-level `errors` and saves
no registration fields. Repeated registration returns `409`. `phone`,
`phoneVerifiedAt`, and `mobileVerified` cannot be set through the form.

`canSubmit` describes whether the server's stored profile has all registration
fields and is not already complete. Since no form is saved before registration,
validate the local form to enable Register; use `mobileVerified` for the OTP
indicator and handle `422` after submission. `autosaveEnabled` controls whether
the client should send automatic updates.

Database tests require a disposable database ending in `_test`:

```bash
ONBOARDING_TEST_DATABASE=nearby_onboarding_test php artisan test --filter=OnboardingPersistenceTest
```

Tests migrate only that specified database and roll back test records.

## Catalog, localization, discovery, and profiles

Home/map endpoint: `GET /search/dashboard?lat=28.6139&lng=77.2090&radiusKm=7`.
Returns all active, registered, phone-verified nearby providers with public listing
details, numeric coordinates, subjects and teacher/institute/total counts. Default
radius 7 km, maximum 500 km; no 50-result cap. See the
[dashboard contract and map example](APP_TEAM_API.md#home-and-map-dashboard).

| Method | Path | Access | Query/body | Result / notes |
|---|---|---|---|---|
| `GET` | `/subjects` | Public | — | Subject domains with their subjects and BSL catalog data. |
| `GET` | `/i18n/languages` | Public | — | Supported language metadata. |
| `GET` | `/i18n/{lang}` | Public | Language code in path | Returns translations; unsupported language codes serve English and identify the requested/served language. |
| `GET` | `/search` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7, max 500), `subjectId`, `q`, `freeOnly` (`true`/`false`) | Up to 50 nearby teacher/institute results with `distance_km`; uses MySQL `ST_Distance_Sphere`. |
| `GET` | `/search/counts` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7) | Teacher and institute counts in the radius. |
| `GET` | `/search/other-city` | Public | Required `city`; optional `subjectId`, `q` | Up to 50 online providers matching city/country text. |
| `GET` | `/search/by-subject/{subjectId}` | Public | Required `tier`: `nearby`, `city`, `state`, `country`, or `other-countries`; tier-specific location parameters | Subject results with tier/count. Nearby requires `lat` and `lng`; `city` tier requires `city`; `state` tier requires `state`; `country` tier requires `country`. `other-countries` returns matching countries unless a `country` is supplied. |
| `GET` | `/profiles/{type}/{id}` | Public, optional bearer | `type`: `teacher` or `institute`; optional auth affects phone quota | Public listing profile. Suspended or not-yet-phone-verified optional tokens are treated as anonymous. |
| `PUT` | `/profiles/me` | Verified bearer | Provider fields described below | Updates the signed-in teacher/institute listing and its subject assignments. |
| `GET` | `/profiles/me/stats` | Verified bearer | Optional `days` (default 30, maximum 365) | Provider listing views and new booking-request counts. |

`GET /search` applies subject, name and free-only filters before taking the
nearest 50 from each provider type, then returns the global nearest 50.
Location indexes narrow candidates before exact distance checks; profile and
user details load after shortlisting. Response fields remain unchanged.
The `Server-Timing: search;dur=...` response header reports milliseconds spent
building/executing the search and mapping results after input validation. It
excludes application startup, validation, JSON serialization and network time.

`PUT /profiles/me` accepts partial updates. Shared fields are `currency`,
`lessonModes` (array of `in_person`, `home_tuition`, `online`), `latitude`,
`longitude`, `addressText`, `city`, `state`, `country`, `countryCode`,
`trialDays` (0–7), and `subjectIds` (up to 5 existing subject IDs). Latitude
and longitude must be supplied together and must both be null or both be set.
Teacher-only fields: `bio` (maximum 14 words) and `linkedinUrl`. Institute-only
fields: `description` (maximum 14 words), `instructorsText`, and `websiteUrl`.
Rates use `ratePerHour` for teachers and `startingRate` for institutes.

All discovery endpoints are limited to 60 requests/minute per IP. Public
profile reads have the same limit.

## Bookings, conversations, reviews, and community

| Method | Path | Access | Request | Result / notes |
|---|---|---|---|---|
| `POST` | `/bookings` | Verified bearer | `providerId`, `mode`, `requestedTime`; optional `subjectId`, `price` | Creates a booking request. `mode`: `in_person`, `home_tuition`, `online`. Safety acknowledgement and minor-consent gates apply. |
| `GET` | `/bookings/incoming` | Verified bearer | `perPage`, `cursor` | Provider's incoming bookings. |
| `GET` | `/bookings/mine` | Verified bearer | `perPage`, `cursor` | Learner's bookings. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `POST` | `/messages/start` | Verified bearer, learner | `providerId`; optional `body` | Starts/reuses a provider conversation and optionally sends the first message. Safety and guardian-consent gates apply. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name` | Legacy channel authorization; Socket.IO clients use the events below. |
| `POST` | `/reviews` | Verified bearer | `bookingId`, `rating` (1–5); optional `comment` | Adds a review for a booking belonging to the caller. |
| `GET` | `/reviews/for/{providerId}` | Public | `perPage`, `cursor` | Provider reviews. |
| `GET` | `/feed` | Verified bearer | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts and the caller's like state. |
| `POST` | `/feed` | Verified bearer | `city`, `body`; optional `subjectId`, `imageUrl` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |

### Cursor pagination

`GET /messages`, `/messages/{conversationId}`, `/bookings/incoming`,
`/bookings/mine`, `/reviews/for/{providerId}`, `/feed`, and
`/feed/{postId}/comments` return bounded pages. The existing result array
(`messages`, `conversations`, `bookings`, `reviews`, `posts`, or `comments`)
is preserved, with an additional `pagination` object:

```json
{
  "messages": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

Use query `perPage` (1–100, default 50). Pass `pagination.nextCursor` as
query `cursor` to fetch the next page, keeping all filters unchanged; stop
when it is null. URL-encode the opaque cursor and deduplicate by record ID.
Invalid cursors/page sizes return `422`. These lists no longer return the
entire history in one response and do not execute full-table total counts.

Messages fetch the **latest** page first and return that page in chronological
order. The next cursor fetches older messages; prepend those pages in chat.
Comments are oldest first; bookings, reviews and feed posts are newest first.
Inbox is ordered by latest message time (creation time for an empty conversation).
Concurrent new messages can move conversations between inbox pages; refresh
the first page and deduplicate by conversation ID. Equal timestamps use IDs
as a deterministic tie-breaker.

Email notifications (booking, reply, support and guardian consent) run on the
`notifications` queue. Chat broadcasts run on the separate `broadcasts` queue.
Run persistent queue workers as described in [PERFORMANCE.md](PERFORMANCE.md).

### Real-time chat (Socket.IO)

Chat uses the Node.js Socket.IO 4 server in `realtime/` with Laravel handling
bearer authentication, membership, persistence and queued broadcasts. Connect
using a Socket.IO client with `auth: {token: "<Laravel bearer>"}`. The server
calls protected `POST /chat/socket/authorize` to authorize each connection and
room join. Do not use Pusher, Laravel Echo's Reverb connector, or a raw
WebSocket client for this protocol. `/broadcasting/auth` remains a legacy
channel-authorization route; new mobile clients do not call it directly.

Events are `conversation.join`, `conversation.leave`, `message.send`,
`message.sent`, and `session.expired`. Join/leave payload: `{conversationId}`.
Send payload: `{conversationId, body}`; acknowledgement contains the Laravel
saved `message` or an error/status. The existing REST send endpoints remain
supported and use the same delivery path. Message history uses paginated
`GET /messages/{conversationId}`.

Every authenticated socket is automatically placed in its own server-managed
user room. Both participants receive `message.sent` with `{message}` even
when not viewing that conversation; outsiders receive nothing. Joining a
conversation requires membership. Deduplicate on message ID because queue
retries, REST acknowledgements and socket events may overlap. Sessions expire
after at most 15 minutes; reconnect to reauthorize. Recover missed events from
REST after reconnect. Closed/background apps still need FCM/APNs for OS push.

Run `npm run chat:start` and a `broadcasts` queue worker. Setup, mobile client
code, reverse-proxy and multi-node Redis instructions are in
[CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md).

## Safety, support, referrals, and analytics

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `POST` | `/safety/reports` | Verified bearer | `targetType` (`user`, `post`, `comment`, `message`), `targetId`, `reasonCategory` (`fraud`, `fake_profile`, `inappropriate`, `spam`, `other`); optional `reason` | Creates a report. |
| `POST` | `/safety/blocks/{userId}` | Verified bearer | — | Blocks a user. |
| `DELETE` | `/safety/blocks/{userId}` | Verified bearer | — | Unblocks a user. |
| `POST` | `/suggestions` | Verified bearer | `suggestionType` (`course`, `city`, `state`, `country`), `name`; optional `notes` | Submits a suggestion. |
| `POST` | `/support` | Verified bearer | `subject`, `message` | Creates a support ticket. |
| `GET` | `/support/mine` | Verified bearer | — | Lists the caller's support tickets. |
| `GET` | `/referrals/mine` | Verified bearer | — | Returns or creates the caller's referral code and referred users. |
| `POST` | `/referrals/redeem` | Verified bearer | `code` | Redeems a referral code. Self-referrals are rejected; an already-credited account receives `409`. |
| `POST` | `/analytics/page-view` | Public | `pageType`; optional `target`, `platform`, `referrer` | Records a view. `pageType`: `institute`, `teacher`, `city_index`, `city_feed`, `city_directory`, `download`, `app_profile`. Limited to 120 requests/minute per IP. |

## Public website and app downloads

| Method | Path | Access | Behavior |
|---|---|---|---|
| `GET` | `/` | Public | Laravel website landing page linking to the public city directory. |
| `GET` | `/site` | Public | City directory of published teacher/institute listings. |
| `GET` | `/site/institute/{slug}` | Public | Institute landing page and assigned subjects; returns a localized 404 page if unavailable. |
| `GET` | `/site/teacher/{slug}` | Public | Teacher landing page and assigned subjects; returns a localized 404 page if unavailable. |
| `GET` | `/site/city/{city}` | Public | Published city listings and subjects. |
| `GET` | `/site/feed/{city}` | Public | Read-only city feed, limited to the latest 50 posts. |
| `GET` | `/download` | Public | Redirects Android/iOS visitors to configured app-store URLs; desktop visitors see store links. Supports `type`, `slug`, and `ref` query parameters. Android carries the destination and referral through Play Install Referrer. |
| `GET` | `/sitemap.xml` | Public | Generates sitemap entries for directory, published cities, city feeds, and published teacher/institute pages. |
| `GET` | `/robots.txt` | Public | Allows public pages, disallows `/admin`, and advertises the sitemap URL. |

Website pages support `?lang=en|hi|es|fr|ar|zh|pt|ru`, the `nt_lang` cookie,
and the `Accept-Language` header, in that order. A supported `lang` query
parameter is stored in a one-year cookie. English is the fallback language.

## Admin-only API

These endpoints require both a valid bearer token and an authenticated user
whose database `users.is_admin` is true. They are distinct from the browser
admin portal's `ADMIN_EMAIL`/`ADMIN_PASSWORD_HASH` login.

| Method | Path | Request / query | Result |
|---|---|---|---|
| `PATCH` | `/profiles/{type}/{id}/verify` | `verified` boolean; type `teacher` or `institute` | Sets provider verification. |
| `PATCH` | `/profiles/institute/{id}/type` | `institute_type`: `independent`, `franchise`, `mcp_partner`, `bsl_direct` | Classifies an institute. |
| `PATCH` | `/profiles/institute/{id}/bsl-verified` | `verified` boolean | Sets BSL official status; enabling requires `bsl_direct`. |
| `GET` | `/safety/reports` | Optional `status`: `open`, `reviewed`, `dismissed` | Lists reports (defaults to open). |
| `PATCH` | `/safety/reports/{id}/status` | `status`: `open`, `reviewed`, `dismissed` | Updates report status. |
| `GET` | `/suggestions` | Optional `status`: `open`, `added`, `declined` | Lists suggestions (defaults to open). |
| `PATCH` | `/suggestions/{id}/status` | `status`: `open`, `added`, `declined` | Updates suggestion status. |
| `GET` | `/support` | Optional `status`: `open`, `in_progress`, `resolved` | Lists support tickets (defaults to open). |
| `PATCH` | `/support/{id}/status` | `status`: `open`, `in_progress`, `resolved` | Updates ticket status. |
| `GET` | `/referrals/top` | — | Up to 50 top referrers. |
| `GET` | `/analytics/summary` | Optional `days` | Page-view summary for the requested period (default 30 days). |
| `GET` | `/moderation/photos/pending` | — | Lists photos awaiting review. |
| `PATCH` | `/moderation/photos/{userId}` | `status`: `approved` or `rejected` | Reviews a pending photo. |
| `GET` | `/moderation/conversations` | — | Lists conversations and message counts. |
| `GET` | `/moderation/conversations/{id}/messages` | — | Reads and audit-logs admin access to a conversation. |
| `GET` | `/moderation/bookings` | — | Lists bookings and audit-logs the admin access. |
| `GET` | `/moderation/contact-stats` | — | Contact activity by provider and learner. |
| `POST` | `/moderation/users/{userId}/suspend` | Optional `reason` | Suspends a user. |
| `POST` | `/moderation/users/{userId}/unsuspend` | — | Restores a suspended user. |
| `GET` | `/moderation/users/suspended` | — | Lists suspended users. |

## Errors and operational requirements

- `401`: missing bearer token or incorrect login credentials.
- `403`: invalid/expired session token (`Invalid or expired token`); sign in again.
- `403`: phone verification, admin permission, safety acknowledgement, or
  guardian-consent requirement not satisfied; suspended account.
- `404`: record not found or not accessible to the caller.
- `409`: conflicting account/referral operation.
- `422`: request validation failed; Laravel returns an `errors` object keyed
  by invalid input field.
- `429`: rate limit or feature-specific limit (for example, posting frequency).
- `5xx`: unexpected server/integration failure. Configure application logs,
  database credentials, Firebase verification, SMS, and email before launch.

Laravel's only enabled database connection is MySQL 8 and its schema is
`database/sql/schema.mysql.sql`; search relies on MySQL spatial functions and
JSON support. The old Express
source tree remains as a reference and its schema is still PostgreSQL/PostGIS;
do not run it against Laravel's MySQL database. Nearby Teacher's 23 tables
are created by ordered, individual Laravel migrations. These migrations are
intended for a fresh database; back up and inspect any existing database and
its migration history before running them. Existing schemas are not
automatically transformed by this migration set.

## Developer portal

The browser developer workbench is available at `/developer/login`. Its
credentials are separate from the admin portal account. Set them with:

```sh
php artisan developer:setup-login
```

The command prompts for a developer email and a password of at least 16
characters, stores only a password hash in `.env`, and clears cached
configuration. The developer portal login is rate limited and requires its
own authenticated session.

The API explorer builds its catalog from registered Laravel API routes,
including the public health and reverse-location JSON endpoints, groups
endpoints by category, and can send requests to this application. It does not
list HTML website pages or the legacy Express routes, which are not served by
this Laravel application. The overview and Google → OTP workflow show the
intended sign-in sequence:

1. `POST /auth/google` with a Firebase Google ID token.
2. `POST /auth/otp/request` with the international phone number.
3. `PATCH /auth/link-phone` with the delivered code and the token from step 1.

If Google sign-in says phone verification is not required, skip steps 2 and 3.
Registration phone verification uses OTP request → OTP verify → Register.
Returning users use email/password; forgot/reset-password and email-verification
shortcuts are included. The workbench can copy a token from a successful JSON
response into its in-page authentication field for the next request.

The sidebar filters the API catalog by category. Selecting an endpoint
prefills its available query string, path parameters, and JSON example based
on the API's validation rules; sample UUIDs and one-time values must be
replaced with real IDs, codes, or credentials before sending. The workbench
shows access requirements and endpoint-specific notes, then displays the
response status, duration, content type, and body. Authentication values stay
in the page and are not saved in browser storage. Requests are restricted to
the current application origin; the workbench does not forward credentials to
arbitrary external hosts. Endpoints still enforce their regular API
authentication, user verification, admin, rate-limit, and validation rules.

The WebSockets section summarizes Socket.IO setup and private conversation
rooms. Configuration values for Socket.IO and other project services are
managed in the Configuration tab.

The Configuration tab can edit the project's documented application, MySQL,
runtime, Socket.IO, email, authentication, and integration `.env` settings.
Existing secret values are never rendered into the page. Leave a value blank
to keep it unchanged; use the explicit clear option to remove a value. Saving
requires a confirmation and writes only allowlisted keys, then runs
`php artisan config:clear`. Changes may also require restarting queue workers,
Socket.IO, Octane, or other long-running processes. Changing `APP_KEY` invalidates
encrypted cookies and sessions. Do not expose this portal publicly without
HTTPS, strong unique credentials, and access controls at the network layer;
configuration edits can interrupt the live application.

Developer login credentials (`DEVELOPER_EMAIL` and
`DEVELOPER_PASSWORD_HASH`) are intentionally not editable through the
Configuration tab. Change them with `php artisan developer:setup-login`.

## System health checks

| Method | Path | Access | Result |
|---|---|---|---|
| `GET` | `/health` | Public | Returns `{"ok":true}`. |
| `GET` | `/up` | Public | Laravel application health response. |

Both checks are listed in the developer portal's **System** category.

## Public coordinate and locality lookup

The unauthenticated web page at `/location-lookup` accepts latitude and
longitude, can optionally fill them using the browser's geolocation permission,
and shows the reverse-geocoded locality and available address components.
Its browser posts coordinates to `/location-lookup/reverse`:

```http
POST /location-lookup/reverse
Content-Type: application/json
Accept: application/json

{"latitude":28.6139,"longitude":77.2090}
```

Latitude must be between -90 and 90; longitude must be between -180 and 180.
The endpoint is public, limited to 30 requests per minute per IP, and enforces
at most one lookup per second per IP. It requests address details from
OpenStreetMap Nominatim; coordinates are sent to that service. Results depend
on available OSM data and may identify the nearest mapped address rather than
an exact property. The page displays OpenStreetMap attribution and links to the
map. The endpoint returns the Nominatim `jsonv2` location object under
`location`, including its address, place metadata, names, and tags. This
request is also available in the developer portal's **Location** category.
