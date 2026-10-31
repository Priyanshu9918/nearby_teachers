# Nearby Teacher API reference

Updated: 27 October 2026. This reference covers every route in `routes/api.php`
and the health, reverse-location and broadcasting compatibility surfaces. The
legacy Express API is separate. All examples are fictional; token placeholders
cannot authenticate.

The reference, [app integration guide](APP_TEAM_API.md), [saved response examples](API_MOCK_RESPONSES.md),
[Postman collection](postman/app-team.postman_collection.json) and developer
explorer use exactly five parts:
[Auth](#auth), [Learner](#learner), [Teacher](#teacher),
[Institute](#institute), [Common](#common).

For referral codes, the joined-user list, referral-ranked search/feed results
and the admin leaderboard, import the dedicated
[Referral Points Postman collection](postman/referral-points.postman_collection.json).

These categories organize app responsibilities; they do not add role permissions.
Provider listing URLs appear in both Teacher and Institute. Shared chat, feed,
comments, replies, booking status and safety APIs appear in each app role and
in Common. Settings and admin-only APIs remain in Common, with their real access
requirements. Provider contact lookup appears in Learner, Teacher and Institute.

For live feed comments, replies and likes, see the [Socket.IO event guide](FEED_SOCKET_IO.md)
and import the [Feed Realtime Postman collection](postman/feed-realtime.postman_collection.json).
For chat connection, ping, history and actions, import the [Chat Realtime Postman collection](postman/chat-realtime.postman_collection.json)
and see the [chat event contract](CHAT_EVENTS.md). To test actual Socket.IO
connect/ping inside Postman, follow the [Postman Socket.IO request guide](postman/chat-socketio-postman.md).

Deployed base URL: **`http://157.245.125.79/nearby_teachers/public`**.
Local: `http://127.0.0.1:8000`; Android emulator: `http://10.0.2.2:8000`.
There is **no `/api` prefix**. Append `/auth/login`, for example, to the full
base URL including `/nearby_teachers/public`.

Send `Accept: application/json` and `Content-Type: application/json` for JSON
bodies. Protected requests send `Authorization: Bearer <Laravel-issued token>`.
Public requests omit Authorization, except an explicitly documented optional
bearer. **Bearer** means valid, unsuspended identity. **Verified** additionally
requires verified phone for non-admins. Before phone verification, protected
access is limited to session, GET onboarding, link-phone and logout. Logout is
also permitted for suspended users. Google OTP APIs perform their own bearer checks.

Request properties generally use camelCase; raw record responses generally use
snake_case. IDs are UUIDs except numeric subject IDs. Provider IDs always mean
user UUID (`user_id` from discovery), not listing `profile.id`. Most successful
API actions, including creation, return `200`; partial OTP delivery returns `202`.
There is no single response wrapper. International phones include `+` and country
code; six-digit codes are strings. Raw decimals/booleans can be strings or `0/1`;
raw listing `lesson_modes` can be JSON text. Normalize these at the client boundary.
Raw timestamps may lack timezone offsets; use the configured server timezone.

## Auth

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `POST` | `/auth/google` | Public | `firebaseIdToken` | First step for Google login: verifies a Firebase Google sign-in and verified email; returns `token`, `user`, `phoneVerificationRequired`. |
| `POST` | `/auth/otp/request` | Google bearer | `phone`, `authMethod:"google"` | After Google sign-in, requests an OTP for phone verification. Returns `ok`, `expiresInMinutes`. Up to 3 codes per phone per 10 minutes; codes expire after 10 minutes. |
| `POST` | `/auth/otp/request` | Public | `authMethod:"phone_otp"` (or omitted), `phone` | Registration phone verification: generates/sends an OTP; returns `ok`, `expiresInMinutes`. |
| `POST` | `/auth/otp/verify` | Public | `authMethod:"phone_otp"` (or omitted), `phone`, `code` (6 digits) | Registration phone verification: verifies the code and returns `token`, `user`, `authMethod:"phone_otp"`, routing fields. Up to 5 code attempts. |
| `POST` | `/auth/login` | Public | `email`, `password` (phone prohibited) | Email-only password login. Completed registration required; incomplete accounts receive `409 REGISTRATION_INCOMPLETE` without a token. Returns `token`, `user`, `mobileVerified` on success. |
| `POST` | `/auth/logout` | Bearer token | `{}` | Revokes all existing API sessions for this user; returns `{ok:true,message,loggedOutAllDevices:true}`. Available before mobile verification and for suspended users. |
| `GET` | `/auth/session` | Bearer token | — | Returns safe user fields, the account-type profile (`profile`), subjects, registration/onboarding flags, `profileComplete`, and `profileCompletion`. Teacher profile uses `is_profile_complete`; institute completion is derived from required onboarding fields; learner completion follows registration/age/consent gates. Allowed before phone verification. |
| `GET` | `/auth/onboarding` | Bearer token | — | Saved DB fields under `draft`, completion flags and routing; no new autosave. Available before phone verification. |
| `PATCH` | `/auth/onboarding` | Registered, verified bearer | Partial profile fields below | Autosave only after registration. Returns `409` before registration. |
| `POST` | `/auth/register` | Verified bearer | Full registration form below | Saves the full form; student DOB is a separate step. Pending-DOB retries return `200`; completed registration returns `409`. |
| `POST` | `/auth/onboarding/complete` | Verified bearer | Full registration form below | Alias of `/auth/register`; same validation and behavior. |
| `POST` | `/auth/account-type` | Verified bearer | `accountType`: `learner`, `teacher`, or `institute` | Sets account type; creates an empty provider profile for teachers/institutes. |
| `PATCH` | `/auth/link-phone` | Bearer token | `phone`, `code` (6 digits) | Verifies and links the phone for an account; this route is available before phone verification. |
| `PATCH` | `/auth/link-google` | Verified bearer | `firebaseIdToken` | Links a verified Google identity to the signed-in account. |
| `POST` | `/auth/forgot-password` | Public | `email` | Generic `{ok:true,message,expiresInMinutes:10}`, including unknown addresses. |
| `POST` | `/auth/reset-password` | Public | `email`, `code`, `password`, `password_confirmation` | `{ok:true,message}`; revokes earlier API sessions; log in again. |
| `POST` | `/auth/email/verification/request` | Verified bearer | `{}` | `{ok:true,message,expiresInMinutes:10}`; uses saved email. |
| `POST` | `/auth/email/verification/verify` | Verified bearer | `code` | `{ok:true,emailVerified:true}`. |
| `POST` | `/auth/otp/verify` | Google bearer | `authMethod:"google"`, `phone`, `code` | Fresh token for the same Google user, `authMethod:"google"` and routing fields. |

### Signup token flows

| Step | Normal signup | Google signup |
|---|---|---|
| Identity | Open signup | Firebase Google SDK sign-in, then `POST /auth/google` with `firebaseIdToken`; save its Laravel token |
| OTP request | `POST /auth/otp/request`, `authMethod:"phone_otp"`, phone; no bearer | Same endpoint, `authMethod:"google"`, phone and Google bearer |
| OTP verification | `POST /auth/otp/verify`, phone/code; no bearer | Same endpoint, `authMethod:"google"`, phone/code and Google bearer |
| Token after verification | Save returned new token | Save returned fresh token; the user ID and Google identity stay the same |
| Registration | Verification bearer; email/password required | Verification bearer; email/password optional |
| Later APIs | Active saved bearer | Active saved bearer |

Normal `authMethod` may be omitted for older clients. Google requests require
its bearer at both OTP endpoints. `authMethod` does not establish identity; the
saved user's `google_id` does. Missing Google bearer returns `401
GOOGLE_AUTH_REQUIRED`; invalid/suspended sessions return `403`; a non-Google
bearer, or explicit `authMethod:"phone_otp"` with bearer, returns `409 OTP_BEARER_NOT_GOOGLE`. A Google OTP is bound to its user:
omitting its bearer at verification cannot switch to normal signup, and another
Google account receives `403 OTP_GOOGLE_ACCOUNT_MISMATCH`.

`PATCH /auth/link-phone` is the supported alternative to Google OTP verification
and also returns a fresh token for the same Google user. A phone already linked
to another user returns `409` without silently merging accounts or consuming the
OTP. Logout does not unlink that phone. If Google sign-in returns
`phoneVerificationRequired:false`, skip OTP and retain its token.

OTP codes expire after 10 minutes and allow five incorrect attempts. Request
limits are three codes per phone per 10 minutes. Responses include delivery
`channels`; `200` means all configured channels accepted, `202` adds
`partialDelivery:true`, `503` means none accepted. Acceptance is a gateway
acknowledgement, not confirmed handset delivery. `OTP_TEST_MODE=true` exposes
`testMode:true` and `otp` only in local/testing; production never returns codes.
See [OTP delivery](OTP_DELIVERY.md).

### Registration form and saved-data resume

`POST /auth/register` and its alias accept the same full form. The bearer
supplies the verified phone: `phone`, `phoneVerifiedAt` and `mobileVerified` are
prohibited body fields. **DOB is never a registration-form requirement**; the
student submits it on the separate Learner DOB API/page.

| Field | Student | Teacher | Institute |
|---|---|---|---|
| `accountType` | `student` or `learner`, stored as `learner` | `teacher` | `institute` |
| `fullName` | Name, max 160 | Name, max 160 | Institute name, max 160 |
| `email` | Normal: required; Google: optional | Same | Same |
| `password` | Normal: required, 8–72 characters; Google: optional | Same | Same |
| `termsAccepted` | `true` | `true` | `true` |
| `acknowledgeTeachersNotBackgroundChecked` | `true` | `true` | `true` |
| `city` | — | Required, max 120 | Required, max 120 |
| `primarySubjects` | — | Required array of 1–5 distinct existing subject IDs; no separate primary subject | — |
| `district`, `subDistrict`, `locality`, `localityType`, `state`, `country`, `postalCode` | — | Optional manual address fields; see [contract and agreement labels](TEACHER_REGISTRATION_ADDRESS.md) | — |
| `latitude`, `longitude` | — | Optional paired GPS coordinates; private reverse-geocoding enrichment when configured | — |
| `contactPerson` | — | — | Required, max 160 |

Omit optional Google email/password to preserve the verified Google email and
avoid setting a password. Supplied values still undergo validation. Passwords
are hashed and never returned; registration does not require confirmation.

Normal student example (for Google omit email/password):

```json
{
  "accountType":"student",
  "fullName":"Student Example",
  "email":"student@example.com",
  "password":"ExamplePass123!",
  "termsAccepted":true,
  "acknowledgeTeachersNotBackgroundChecked":true
}
```

Teacher example:

```json
{
  "accountType":"teacher",
  "fullName":"Teacher Example",
  "email":"teacher@example.com",
  "password":"ExamplePass123!",
  "city":"Delhi",
  "primarySubjects":[1],
  "termsAccepted":true,
  "acknowledgeTeachersNotBackgroundChecked":true
}
```

Institute example:

```json
{
  "accountType":"institute",
  "fullName":"Example Academy",
  "email":"institute@example.com",
  "password":"ExamplePass123!",
  "city":"Delhi",
  "contactPerson":"Contact Example",
  "termsAccepted":true,
  "acknowledgeTeachersNotBackgroundChecked":true
}
```

Student form success is `200`, `registrationSubmitted:true`,
`registrationCompleted:false`, `profileCompleted:false`, `missingFields:[]`,
`autosaveEnabled:false`, `nextScreen:"age_verification"`. A retry while the saved
form awaits DOB is also `200` and resumes that step; it does not resave form
fields. Valid student DOB later completes registration. Teacher/institute form
success completes immediately and routes home; no DOB required. Repeating an
already completed registration returns `409`.

Keep unsaved form values on the device. `GET /auth/onboarding` restores only
already saved DB fields in `draft`: role, name, email, phone, city,
primary subject/contact, passwordSet and acceptance flags. No new autosave is
introduced. `PATCH /auth/onboarding` returns `409` until registration is complete,
including while student DOB is pending. After completion it accepts partial
registration fields; omitted values remain, null clears nullable text, and an
empty password keeps the saved password. Provider listing fields use
`PUT /profiles/me`. `canSubmit` describes stored server state; local validation
controls an unsaved form's submit button. Password values and guardian-consent
tokens are never returned.

Missing/invalid registration bearer or an unverified phone returns `403
OTP_VERIFICATION_REQUIRED`, with OTP request/verification endpoints; an
authenticated unverified Google user is directed to `/auth/link-phone`.
Validation errors are `422` and save no form fields.

### Completion flags and login routing

`registrationSubmitted` means the form is saved (`registration_submitted_at`,
with legacy `profile_completed_at` fallback). `registrationCompleted` means
registration completion is saved and a learner has DOB. `profileCompleted` is
an alias of `registrationCompleted`, not provider-listing completeness.
`onboardingRequired` is its inverse. Route by `nextScreen`, then `nextStep`.

| Saved state | Submitted | Completed | `nextScreen` / action |
|---|---|---|---|
| Phone pending | false | false | `onboarding` / `mobile_verification` |
| Phone verified, form pending | false | false | `onboarding` / `complete_profile` |
| Student form saved, DOB pending | true | false | `age_verification` / `/auth/date-of-birth` |
| Adult student complete | true | true | `home` |
| Minor complete, consent pending | true | true | `guardian_consent` / refresh `/auth/session` |
| Minor consent confirmed | true | true | `home` |
| Teacher/institute form saved | true | true | `home`, then manage listing |

Phone verification takes priority when it is pending. Guardian consent is a
separate contact gate; a complete minor can still be routed to consent.
Google sign-in can issue an onboarding token before completion: follow the
returned flags. Returning complete Google users route directly home when no
other gate remains. App launch uses saved bearer + `GET /auth/session`.

Password login checks credentials first. Correct credentials with incomplete
registration return `409 REGISTRATION_INCOMPLETE`, `registrationRequired:true`
and current routing fields, **without a token**. Wrong credentials stay `401`.
Resume normal signup through OTP or Google signup through Google auth; restore
saved form fields with GET onboarding. A completed normal account cannot use
registration OTP as an alternative login; verification returns `409
loginRequired:true`. Use email/password or linked Google sign-in.

### Logout and email recovery

Tokens last 30 days; no token-refresh API exists. `POST /auth/logout` returns
`{ok:true,message:"Logged out successfully.",loggedOutAllDevices:true}` and
invalidates all existing API sessions for that user using `auth_version` and a
Firebase authentication-time cutoff. Another user's sessions are unaffected.
Clear the app's bearer and disconnect chat; call Firebase SDK signOut locally
for Google clients. The backend does **not** globally revoke Firebase-provider
refresh tokens. A Firebase session older than the local cutoff cannot restore
API access; authenticate afresh. Logout works before phone verification and
for suspended users. Missing bearer is `401`; revoked bearer is `403`.
Queued chat revocation runs after commit on `broadcasts`.

Forgot-password returns the same generic success for eligible/unknown accounts.
Email codes expire in 10 minutes, are single-use, allow five attempts, and are
invalidated on resend; never exposed by OTP test mode. Reset requires matching
password confirmation, revokes prior API tokens and stale Firebase sessions,
and verifies email ownership. Log in again after reset. Email verification
uses the account's saved email and is not a new login gate. See
[EMAIL_AUTH.md](EMAIL_AUTH.md) and [FIREBASE_GOOGLE_AUTH.md](FIREBASE_GOOGLE_AUTH.md)
for configuration and detailed failures.

## Learner

Includes student age/guardian flow, discovery, public provider details,
booking creation/history and reviews. Public discovery is available without
sign-in. Category placement does not imply an additional learner-only guard:
`POST /messages/start` explicitly requires a learner; booking creation instead
checks authenticated identity, contact gates and a teacher/institute target.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `POST` | `/auth/date-of-birth` | Verified bearer | `dateOfBirth` (`YYYY-MM-DD`); for ages 13–17 also `guardianName`, `guardianContact` | Rejects users under 13. Minors need guardian consent before messaging/booking. Returns parentalConsent.link, expiresAt and parent name/phone; app team sends the link. See [consent contract](PARENTAL_CONSENT.md). |
| `POST` | `/auth/parental-consent/confirm` | Public, one-time token | `token`, optional `decision` (`approve`/`reject`) | Saves a one-time decision; returns `ok`, `parentalConsentStatus`. |
| `GET` | `/search` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7, max 500), `subjectId`, `q`, `freeOnly` (`true`/`false`) | Up to 50 nearby teacher/institute results with `distance_km`; uses MySQL `ST_Distance_Sphere`. |
| `GET` | `/search/counts` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7) | Teacher and institute counts in the radius. |
| `GET` | `/search/other-city` | Public | Required `city`; optional `subjectId`, `q` | Up to 50 online providers matching city/country text. |
| `GET` | `/search/by-subject/{subjectId}` | Public | Required `tier`: `nearby`, `city`, `state`, `country`, or `other-countries`; tier-specific location parameters | Subject results with tier/count. Nearby requires `lat` and `lng`; `city` tier requires `city`; `state` tier requires `state`; `country` tier requires `country`. `other-countries` returns matching countries unless a `country` is supplied. |
| `GET` | `/profiles/{type}/{id}` | Public, optional bearer | `type`: `teacher` or `institute`; optional auth affects phone quota | Public listing profile. Suspended or not-yet-phone-verified optional tokens are treated as anonymous. |
| `POST` | `/bookings` | Verified bearer | `providerId`, `mode`, `requestedTime`; optional `subjectId`, `price` | Creates a booking request. `mode`: `in_person`, `home_tuition`, `online`. Safety acknowledgement and minor-consent gates apply. |
| `GET` | `/bookings/mine` | Verified bearer | `perPage`, `cursor` | Learner's bookings. |
| `POST` | `/messages/start` | Verified bearer, learner | `providerId`; optional `body` | Starts/reuses a provider conversation and optionally sends the first message. Safety and guardian-consent gates apply. |
| `POST` | `/reviews` | Verified bearer | `bookingId`, `rating` (1–5); optional `comment` | Adds a review for a booking belonging to the caller. |
| `GET` | `/reviews/for/{providerId}` | Public | `perPage`, `cursor` | Provider reviews. |
| `GET` | `/search/dashboard` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7, maximum 500) | `{center,radiusKm,teacherCount,instituteCount,totalCount,results}`; all eligible nearby providers. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `POST` | `/feed/socket/authorize` | Verified bearer | `postId` | Confirms the feed post exists and returns its Socket.IO room. |
| `GET` | `/feed` | Verified bearer | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts and the caller's like state. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `POST` | `/feed/comments/{commentId}/like` | Verified bearer | Optional `postId` | Likes a comment or reply; a supplied postId must match the comment. |
| `DELETE` | `/feed/comments/{commentId}/like` | Verified bearer | Optional `postId` | Removes the caller's comment/reply like. |
| `GET` | `/broadcasting/auth` | Verified bearer, conversation participant | Same inputs as query parameters | Legacy broadcasting compatibility; not new mobile chat setup. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name`; `socket_id` as required by configured broadcaster | Legacy channel authorization; Socket.IO clients use the events below. |

### Separate DOB and guardian-consent page

After student form success, call DOB with the active verification bearer:

```json
{"dateOfBirth":"2000-01-01"}
```

An adult response includes `isMinor:false`, `registrationSubmitted:true`,
`registrationCompleted:true`, `profileCompleted:true`,
`ageVerificationRequired:false`, `nextScreen:"home"`. DOB alone does not submit
an unsubmitted registration form or fill `registration_submitted_at`.

For ages 13–17:

```json
{
  "dateOfBirth":"2010-01-01",
  "guardianName":"Guardian Example",
  "guardianContact":"+919876543211"
}
```

The response includes `parentalConsentStatus:"pending"`, `parentalConsentRequired:true`,
`nextScreen:"guardian_consent"` and `parentalConsent` containing `link`, `expiresAt`,
and `parent:{name,phone}`. `guardianContact` must be an international phone number.
The app team sends this link; the backend does not send consent SMS/email.
Parents open the hosted page and explicitly approve or reject. Links expire in
48 hours and are single use. Login/session expose `parentalConsentStatus`:
`pending`, `approved`, `rejected`, `expired`, or `not_required`. Only approval
unlocks existing consent gates. See [complete contract](PARENTAL_CONSENT.md).
Under-13 or age over 120 is `400`; invalid/future date is `422`. Students require
DOB to complete; teachers/institutes do not need this page/API.

### Discovery and provider phone visibility

All search and public profile routes allow 60 requests/minute per IP.
The original search result endpoints are capped at 50 and do not use cursor
pagination. `/search/dashboard` returns all matching providers without that cap.

For subject tiers, `nearby` needs `lat`/`lng` (optional `radiusKm`); `city` needs
`city`; `state` needs `state`; `country` needs `country`. Geographic names match
case-insensitively and exactly for these tiers. `other-countries` without a
country returns `{tier,countries:[{country,trainer_count}]}`; with `country`,
returns `{tier,country,totalCount,results}` for that exact country. It does not
automatically exclude the viewer's home country. `totalCount` can exceed 50.
City/state/country results rank by weighted rating, then referral count.

```bash
curl 'http://127.0.0.1:8000/search?lat=28.6139&lng=77.2090&radiusKm=7&freeOnly=false' \
  -H 'Accept: application/json'
```

Nearby response example:

```json
{
  "results": [{
    "profile_type": "teacher",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "price": "500.00",
    "rating_avg": "4.50",
    "rating_count": 10,
    "is_verified": 0,
    "trial_days": 1,
    "distance_km": 1.25
  }]
}
```

`/search/other-city` adds `city` and `country` instead of distance. Geographic
subject tiers include `city`, `state`, `country` where selected; nearby includes
`distance_km`. Search cards do not include currency; obtain it from the profile
before showing a currency-specific price. A zero price matches `freeOnly=true`;
a null price does not. Empty searches return `results:[]` with `200`.

Profile response contains raw listing columns, such as `id`, `user_id`,
`bio`/`description`, `rate_per_hour`/`starting_rate`, `currency`, `lesson_modes`,
`latitude`, `longitude`, `city`, `rating_avg`, `is_verified`, plus `full_name`,
`avatar_url`, `lessons_completed`. `subjects` contains `{id,name,is_bsl}`.

Anonymous requests omit `profile.phone`. A verified signed-in viewer can reveal
up to five distinct providers per server day. Exhaustion still returns `200`,
omits phone and includes `phoneLimitReached:true,phoneRevealsRemainingToday:0`.
Profile reads can consume this quota; do not assume they are a separate reveal
button API. Repeated reads of the same provider on the same day do not consume
another distinct reveal. Invalid/unverified/suspended optional identities may
be treated as anonymous. Treat quota metadata as optional and do not assume it
exists on anonymous reads.

### Home and map dashboard

`GET /search/dashboard?lat=28.6139&lng=77.2090&radiusKm=7`

Public; no bearer required. `lat` and `lng` are required numeric coordinates
(latitude −90..90, longitude −180..180). `radiusKm` is optional, default 7,
greater than zero and at most 500. Unknown/missing coordinates and invalid
radius return `422` field errors. Rate limit: 60 requests/minute per IP.

The response contains **all** eligible teachers and institutes within the exact
circular radius, sorted nearest first (ties by type and user UUID). There is
no 50-result cap and no pagination. Only accounts with completed registration,
a verified phone, a current matching provider role, valid listing coordinates
and no suspension are included. Provider verification badges are not required.
Counts describe these returned listings and can differ from `/search/counts`,
which does not apply these publication checks.

| Response field | Meaning |
|---|---|
| `center` | `{latitude,longitude}` from the input, as numbers. |
| `radiusKm` | Applied radius in kilometres. |
| `teacherCount`, `instituteCount`, `totalCount` | Counts of returned listings. |
| `results` | Combined provider array; empty array when no matches exist. |
| `results[].profile_type` | `teacher` or `institute`; choose marker style using this field. |
| `results[].id`, `user_id` | Listing UUID and provider user UUID; use `user_id` for profile navigation and bookings. |
| `results[].latitude`, `longitude`, `distance_km` | Numbers ready for map markers and distance labels. |
| `results[].full_name`, `avatar_url` | Display name and approved image URL (otherwise null). |
| `results[].price`, `currency` | Numeric rate (or null) and currency. |
| `results[].lesson_modes`, `subjects` | Arrays. Subject entries have numeric `id`, `name`, boolean `is_bsl`. |
| `results[].address_text`, `city`, `state`, `country`, `country_code`, `slug` | Public location and listing link fields. |
| `results[].rating_avg`, `rating_count`, `trial_days`, `is_verified`, `verified_at` | Numeric ratings/counts/trial days, boolean verification flag and nullable verification date. |
| Teacher fields | `bio`, numeric/null `rate_per_hour`, `linkedin_url`, numeric/null `primary_subject_id`. |
| Institute fields | `description`, numeric/null `starting_rate`, `contact_person`, `instructors_text`, `website_url`, `institute_type`, boolean `is_bsl_official`, `bsl_official_since`. |
| `results[].created_at`, `updated_at` | Listing timestamps. |
| `POST` | `/chat/events/send` | chat:send · Send message |
| `POST` | `/chat/events/delivered` | chat:delivered · Confirm delivery |
| `POST` | `/chat/events/read` | chat:read · Mark message read |
| `POST` | `/chat/events/typing` | chat:typing · User typing |
| `POST` | `/chat/events/stop_typing` | chat:stop_typing · User stopped typing |
| `POST` | `/chat/events/delete` | chat:delete · Delete message |
| `POST` | `/chat/events/edit` | chat:edit · Edit message |
| `POST` | `/chat/events/reply` | chat:reply · Reply to message |
| `POST` | `/chat/events/reaction` | chat:reaction · React to message |
| `POST` | `/chat/events/history` | chat:history · Fetch chat history |
| `POST` | `/chat/events/unread_count` | chat:unread_count · Unread message count |

This endpoint normalizes coordinates, prices and flags; it does not require the
raw-profile JSON normalization described earlier. Phone, email and private
account fields are omitted, and this lookup does not consume phone-reveal quota.
Use the existing profile endpoint for contact access. Restrict `radiusKm` to the
visible area where possible: all matches are returned, so broad dense searches
can produce a large response. No subject/name/free filters are applied here.

```js
const response = await fetch(
  `${baseUrl}/search/dashboard?${new URLSearchParams({lat, lng, radiusKm})}`,
  {headers: {Accept: 'application/json'}}
);
const data = await response.json();
if (!response.ok) throw data;
const markers = data.results.map(provider => ({
  id: `${provider.profile_type}:${provider.user_id}`,
  latitude: provider.latitude,
  longitude: provider.longitude,
  title: provider.full_name,
  provider,
}));
```

Full teacher/institute success, empty and validation JSON examples are saved
under **Learner → GET /search/dashboard — home and map** in the
Postman collection, and in the [mock response guide](API_MOCK_RESPONSES.md).

### Learner booking, review and contact rules

Bookings require `providerId`, mode (`in_person`, `home_tuition`, `online`) and
`requestedTime` with an explicit timezone; `subjectId` and nonnegative `price`
are optional. Self-booking is `400`. Success returns `{booking}` with initial
`status:"requested"`; rows include learner/provider IDs, mode, requested time,
price, status and timestamps. Booking prices are not payments or revenue.
`GET /bookings/mine` filters by caller as learner and adds provider/subject names;
it has no separate role guard. Cursor pagination is described in Common.

`POST /messages/start` requires learner role, provider user UUID and optional
`body` (max 5,000); it returns `{conversationId}` and reuses the existing pair.
Booking creation and message start/send enforce safety acknowledgement and
minor guardian consent. Conversation APIs listed here and in Common provide
inbox, history and replies for the signed-in participant.

Reviews require caller ownership as the booking's learner, rating 1–5 and
optional comment (max 5,000). There is no completed-booking status gate.
Public review lists add learner name and use cursors. No review update/delete
endpoint exists.

### Learner posts, comments, replies and contact actions

Learners use the shared feed endpoints listed above to read city posts, create
a request post, like/unlike, comment and reply. A reply uses the comment API with
`parentCommentId`; the parent must belong to that post. Posting is limited to
once per seven days. Teacher/institute posts use the same APIs as offer posts.
Call and WhatsApp buttons use the phone returned by authenticated provider
profile lookup; see [WhatsApp and call actions](#whatsapp-and-call-actions).

## Teacher

Teacher signup uses Auth `/auth/register` with `accountType:"teacher"`, city
and primary subject. Form success completes registration; DOB is not required.
The category includes listing management, existing-conversation chat, city
posts/comments/replies, booking status and provider contact lookup. Use the
teacher's bearer for its own data. Shared URLs also appear in Institute and
Common where applicable; each endpoint retains its access rules.

To add a teacher post in the developer explorer or Postman, open
**Teacher → Feed / Posts → POST /feed — Add post**. The same subsection includes
feed reading, likes, comments and replies.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `GET` | `/profiles/me` | Verified teacher/institute bearer | — | Shared profile read with saved account, profile, subjects, `profileComplete`, `profileDraft` and provider page URLs. |
| `PATCH` | `/profiles/me/steps/{step}` | Verified teacher/institute bearer, completed registration | Steps 1–5; account-specific fields below | Saves a section and draft progress atomically. Account type comes from the bearer, never the payload. |
| `POST` | `/profiles/me/photo` | Verified teacher/institute bearer | Multipart `image`, JPEG/PNG/WebP up to 5 MB | Submits photo for moderation; no step progress change. |
| `POST` | `/profiles/me/publish` | Verified teacher/institute bearer, completed registration | `agreeToTerms:true` | Step 6: validates required fields, records terms and completion, returns provider page links. |
| `PUT` | `/profiles/me` | Verified provider bearer, completed registration | Provider fields described below | Updates the signed-in teacher/institute listing and its subject assignments. |
| `GET` | `/profiles/me/stats` | Verified bearer | Optional `days` (default 30, maximum 365) | Provider listing views and new booking-request counts. |
| `GET` | `/bookings/incoming` | Verified bearer | `perPage`, `cursor` | Provider's incoming bookings. |
| `GET` | `/profiles/{type}/{id}` | Public, optional bearer | `type`: `teacher` or `institute`; optional auth affects phone quota | Public listing profile. Suspended or not-yet-phone-verified optional tokens are treated as anonymous. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `GET` | `/feed` | Verified bearer | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts and the caller's like state. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `GET` | `/broadcasting/auth` | Verified bearer, conversation participant | Same inputs as query parameters | Legacy broadcasting compatibility; not new mobile chat setup. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name`; `socket_id` as required by configured broadcaster | Legacy channel authorization; Socket.IO clients use the events below. |

Teacher GPS inputs also populate the private `location_details` JSON column when Google Geocoding is configured. See [teacher geocoding setup, privacy and storage scope](TEACHER_LOCATION_GEOCODING.md).

### Teacher listing example and field rules

```json
{
  "bio":"Maths lessons for school students",
  "ratePerHour":500,
  "currency":"INR",
  "lessonModes":["in_person","online"],
  "latitude":28.6139,
  "longitude":77.2090,
  "city":"Delhi",
  "state":"Delhi",
  "country":"India",
  "countryCode":"IN",
  "trialDays":1,
  "primarySubjects":[1],
  "linkedinUrl":"https://example.com/teacher"
}
```

PUT is partial and returns `{ok:true}`. The account must be teacher/institute
and registration must be complete. Subject assignment uses existing numeric IDs
and replaces the set when supplied. Teachers send `primarySubjects` with 1–5 IDs; institutes retain `subjectIds` and can clear their set using `[]`. Registration itself does
not populate listing rates, modes or coordinates. Nearby publication needs
valid coordinates and the normal publication checks in Learner discovery.

| Teacher/shared field | Validation |
|---|---|
| `bio` | Nullable, max 14 words and 2,000 characters |
| `ratePerHour` | Nullable, 0..99999999.99 |
| `linkedinUrl` | Nullable URL, max 2048 |
| `lessonModes` | Distinct `in_person`, `home_tuition`, `online` array |
| `latitude`, `longitude` | Supply together, both null or both set; valid ranges |
| `addressText`, `city`, `state`, `country` | Nullable, max 1000/120/120/120 |
| `countryCode`, `currency` | 2/3 characters; stored uppercase; supplied currency non-null |
| `trialDays` | 0–7 days |
| `primarySubjects` (teacher), `subjectIds` (institute) | Teacher: 1–5 distinct existing subject IDs; institute: up to 5 |
| `POST` | `/chat/events/send` | chat:send · Send message |
| `POST` | `/chat/events/delivered` | chat:delivered · Confirm delivery |
| `POST` | `/chat/events/read` | chat:read · Mark message read |
| `POST` | `/chat/events/typing` | chat:typing · User typing |
| `POST` | `/chat/events/stop_typing` | chat:stop_typing · User stopped typing |
| `POST` | `/chat/events/delete` | chat:delete · Delete message |
| `POST` | `/chat/events/edit` | chat:edit · Edit message |
| `POST` | `/chat/events/reply` | chat:reply · Reply to message |
| `POST` | `/chat/events/reaction` | chat:reaction · React to message |
| `POST` | `/chat/events/history` | chat:history · Fetch chat history |
| `POST` | `/chat/events/unread_count` | chat:unread_count · Unread message count |

Supplying `countryCode` derives currency when `currency` is omitted. Stats accepts `days` 1–365 (default
30) and returns `{sinceDays,newRequests,profileViews}`. `newRequests` counts
all currently requested bookings, independent of days. Stats requires provider
role but does not have a separate registration-completion gate.

Incoming bookings filter caller as provider and add learner/subject names;
there is no additional role guard on that list. Reply/chat and booking status
updates are listed here and in Common and require participation. Either booking participant
can submit any documented status; there is no provider-only transition matrix.

### Teacher chat, posts, comments and replies

Load `GET /messages` for the teacher's inbox, open
`GET /messages/{conversationId}`, and reply with
`POST /messages/{conversationId}` and `{body:"Yes, this lesson slot is available."}`.
The teacher must already be a participant. `POST /messages/start` remains
learner-only; provider categorization does not add a new initiation permission.
The Socket.IO authorization and legacy broadcasting routes above retain the
same participant checks. Safety acknowledgement is required for sending.

Use `GET /feed?city=Delhi` for city posts. `POST /feed` creates a teacher
`post_type:"offer"`, at most once per seven days:

```json
{"city":"Delhi","body":"Maths lesson slots are available this week."}
```

Like/unlike use the post like endpoints. Comment with
`POST /feed/{postId}/comments` and `{body:"Please share the available timings."}`.
Reply through that same endpoint with the parent comment UUID:

```json
{"body":"Evening slots are available.","parentCommentId":"40000000-0000-4000-8000-000000000001"}
```

The parent must belong to the same post; these are existing comment/reply APIs.

### Teacher WhatsApp and call

For another teacher/institute's contact, read `GET /profiles/{type}/{id}` with
the teacher's bearer and use returned `profile.phone`. Allow up to five distinct
provider reveals per day and hide contact actions when phone is missing. Launch
WhatsApp/call from the frontend as described in [contact actions](#whatsapp-and-call-actions).
Conversation responses do not expose the learner's phone; these APIs do not
provide a teacher-to-student phone lookup from chat.

## Institute

Institute signup uses Auth `/auth/register` with institute name, city and
`contactPerson`. Form success completes registration; DOB is not required.
This category includes the institute's listing, participant chat, city posts,
comments/replies, booking status and provider contact lookup. Shared URLs have
the same access rules as in Teacher/Common, with institute-specific listing fields.

To add an institute post in the developer explorer or Postman, open
**Institute → Feed / Posts → POST /feed — Add post**. The same subsection includes
feed reading, likes, comments and replies.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `GET` | `/profiles/me` | Verified teacher/institute bearer | — | Shared profile read with saved account, profile, subjects, `profileComplete`, `profileDraft` and provider page URLs. |
| `PATCH` | `/profiles/me/steps/{step}` | Verified teacher/institute bearer, completed registration | Steps 1–5; account-specific fields below | Saves a section and draft progress atomically. Account type comes from the bearer, never the payload. |
| `POST` | `/profiles/me/photo` | Verified teacher/institute bearer | Multipart `image`, JPEG/PNG/WebP up to 5 MB | Submits photo for moderation; no step progress change. |
| `POST` | `/profiles/me/publish` | Verified teacher/institute bearer, completed registration | `agreeToTerms:true` | Step 6: validates required fields, records terms and completion, returns provider page links. |
| `PUT` | `/profiles/me` | Verified provider bearer, completed registration | Provider fields described below | Updates the signed-in teacher/institute listing and its subject assignments. |
| `GET` | `/profiles/me/stats` | Verified bearer | Optional `days` (default 30, maximum 365) | Provider listing views and new booking-request counts. |
| `GET` | `/bookings/incoming` | Verified bearer | `perPage`, `cursor` | Provider's incoming bookings. |
| `GET` | `/profiles/{type}/{id}` | Public, optional bearer | `type`: `teacher` or `institute`; optional auth affects phone quota | Public listing profile. Suspended or not-yet-phone-verified optional tokens are treated as anonymous. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `GET` | `/feed` | Verified bearer | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts and the caller's like state. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `GET` | `/broadcasting/auth` | Verified bearer, conversation participant | Same inputs as query parameters | Legacy broadcasting compatibility; not new mobile chat setup. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name`; `socket_id` as required by configured broadcaster | Legacy channel authorization; Socket.IO clients use the events below. |

### Institute listing example and field rules

```json
{
  "description":"Science and language classes for all levels",
  "startingRate":750,
  "currency":"INR",
  "lessonModes":["in_person","online"],
  "latitude":28.6139,
  "longitude":77.2090,
  "addressText":"Example learning centre",
  "city":"Delhi",
  "state":"Delhi",
  "country":"India",
  "countryCode":"IN",
  "trialDays":1,
  "subjectIds":[1],
  "instructorsText":"Experienced subject instructors",
  "websiteUrl":"https://example.com/academy"
}
```

| Institute-only field | Validation |
|---|---|
| `description` | Nullable, max 14 words and 2,000 characters |
| `startingRate` | Nullable, 0..99999999.99 |
| `instructorsText` | Nullable, max 2,000 characters |
| `websiteUrl` | Nullable URL, max 2048 |
| `POST` | `/chat/events/send` | chat:send · Send message |
| `POST` | `/chat/events/delivered` | chat:delivered · Confirm delivery |
| `POST` | `/chat/events/read` | chat:read · Mark message read |
| `POST` | `/chat/events/typing` | chat:typing · User typing |
| `POST` | `/chat/events/stop_typing` | chat:stop_typing · User stopped typing |
| `POST` | `/chat/events/delete` | chat:delete · Delete message |
| `POST` | `/chat/events/edit` | chat:edit · Edit message |
| `POST` | `/chat/events/reply` | chat:reply · Reply to message |
| `POST` | `/chat/events/reaction` | chat:reaction · React to message |
| `POST` | `/chat/events/history` | chat:history · Fetch chat history |
| `POST` | `/chat/events/unread_count` | chat:unread_count · Unread message count |

Shared field validation, listing-completion requirements, subject replacement,
stats semantics and incoming-booking pagination are the same as Teacher.
The endpoint selects the current account's provider table; teacher and institute
field names are not interchangeable. Verification/classification is admin-only
under Common; institutes cannot set their own verification badge through PUT.
Chat/replies and participant booking status updates are listed here and in Common.

### Institute chat, posts, comments and replies

Use the institute's bearer with `GET /messages` to load existing conversations,
`GET /messages/{conversationId}` for history, and
`POST /messages/{conversationId}` with a message body to reply. Membership is
required; `POST /messages/start` remains learner-only. Shared socket routes use
the same identity/room checks, and sending requires safety acknowledgement.

The institute can read city feed, create an offer post, like/unlike posts, add
comments and reply using the feed routes listed above. `POST /feed` creates
`post_type:"offer"` and remains limited to one post per seven days. Example:

```json
{"city":"Delhi","body":"New science classes start this month."}
```

Comment/reply both use `POST /feed/{postId}/comments`. Send `{body:"Message"}`
for a comment or add `parentCommentId` for a reply to a comment on the same post.

### Institute WhatsApp and call

Read another provider's `GET /profiles/{type}/{id}` with the institute's bearer
to obtain eligible `profile.phone`, then launch the external WhatsApp/call
action. The same five-provider daily quota and missing-phone behavior apply.
See [contact actions](#whatsapp-and-call-actions). Chat responses do not supply
learner phone numbers; they are not a student phone lookup API.

## Common

Shared account settings, catalog/localization, conversations, community,
operations, admin-only APIs and compatibility/system endpoints belong here.
Admin endpoints require an admin API identity and remain restricted even when
shown in this shared documentation category.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `PATCH` | `/auth/preferred-language` | Verified bearer | `language`: `en`, `hi`, `es`, `fr`, `ar`, `zh`, `pt`, or `ru` | Updates the account language. |
| `PATCH` | `/auth/avatar` | Verified bearer | `avatarUrl` (URL) | Updates the photo URL and marks it pending moderation. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `GET` | `/subjects` | Public | — | Subject domains with their subjects and BSL catalog data. |
| `GET` | `/geo/countries`, `/geo/states`, `/geo/cities` | Public | Optional `search`; states require `country_id`; cities require `state_id` and accept `page`, `perPage` | Imported SQL country list, country-scoped states and state-scoped paginated cities. See [Geography API](GEOGRAPHY_API.md). |
| `GET` | `/i18n/languages` | Public | — | Supported language metadata. |
| `GET` | `/i18n/{lang}` | Public | Language code in path | Returns translations; unsupported language codes serve English and identify the requested/served language. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name`; `socket_id` as required by configured broadcaster | Legacy channel authorization; Socket.IO clients use the events below. |
| `GET` | `/broadcasting/auth` | Verified bearer, conversation participant | Same inputs as query parameters | Legacy broadcasting compatibility; not new mobile chat setup. |
| `GET` | `/feed` | Verified bearer | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts and the caller's like state. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `POST` | `/safety/reports` | Verified learner, teacher or institute bearer | Required `reasonCategory`; optional `targetType`/`targetId`, legacy `reason` (max 5000) or `additionalDetails` (max 500), `evidenceLink` (HTTP/HTTPS), `screenshot` (JPEG/PNG/WebP, max 5 MB) | Returns 201 and creates an open report. Missing, unsupported or unavailable targets are saved unlinked. Use JSON without an image or multipart/form-data with one. Limited to 10/minute. |
| `POST` | `/reports` | Verified learner, teacher or institute bearer | Required `reasonCategory`; optional `targetType`/`targetId`, `additionalDetails` (max 500), `evidenceLink` (HTTP/HTTPS), `screenshot` (JPEG/PNG/WebP, max 5 MB) | Returns 201 and creates an open confidential report. Missing, unsupported or unavailable targets are saved unlinked. Use JSON without an image or multipart/form-data with one. Limited to 10/minute. |
| `POST` | `/presence/update` | Verified bearer | `deviceId`, `event` (`foreground`, `activity`, `heartbeat`, `background`) | Returns `{isActive}`. Five-minute inactivity and 90-second heartbeat expiry; [presence contract](PRESENCE.md). |
| `POST` | `/safety/blocks/{userId}` | Verified bearer | — | Blocks a user. |
| `DELETE` | `/safety/blocks/{userId}` | Verified bearer | — | Unblocks a user. |
| `POST` | `/suggestions` | Verified bearer | `suggestionType` (`course`, `city`, `state`, `country`), `name`; optional `notes` | Submits a suggestion. |
| `POST` | `/support` | Verified bearer | `subject`, `message` | Creates a support ticket. |
| `GET` | `/support/mine` | Verified bearer | — | Lists the caller's support tickets. |
| `GET` | `/download?ref=CODE` | Public referral link | `ref` | Opening a valid referral link awards one point per browser and code owner; repeat opens from the same browser are deduplicated. |
| `GET` | `/referrals/mine` | Verified bearer | — | Returns or creates the caller's referral code, points and referred users. Opening a valid `/download?ref=CODE` link awards one point per browser and code owner; repeat opens from the same browser do not add points. |
| `POST` | `/referrals/redeem` | Verified bearer | `code` | Records the referred account after link open and does not award an additional point. Self-referrals are rejected; an already-credited account receives `409`. |
| `POST` | `/analytics/page-view` | Public | `pageType`; optional `target`, `platform`, `referrer` | Records a view. `pageType`: `institute`, `teacher`, `city_index`, `city_feed`, `city_directory`, `download`, `app_profile`. Limited to 120 requests/minute per IP. |

### Catalog and settings details

Provider search results add boolean `isActive` without changing inclusion/rank.
Only real interaction resets the five-minute idle clock; send foreground
heartbeats every 30 seconds. Presence requires shared Redis and explicit enablement.
See [API, app lifecycle and deployment](PRESENCE.md).

| Endpoint | Response |
|---|---|
| `GET /subjects` | `{bslFeatured:[{id,name,domain_id}],domains:[{id,name,icon,subjects}]}` |
| `GET /i18n/languages` | `{languages:[{code,name,nativeName,rtl}]}` |
| `GET /i18n/{lang}` | `{requested,served,translations}`; unsupported languages serve English |

Subject objects inside normal domains contain `{id,name,domain_id,is_bsl}`.
The first domain is virtual: `id:"instant-teacher-available"`,
`isVirtual:true`, with BSL featured subjects. Domain IDs therefore have mixed
string/integer types. Use subject IDs, not the virtual domain ID, in API requests.

Avatar updates accept an already-hosted URL and mark it pending moderation;
there is no image-upload API. Public listing images require approval. Safety
acknowledgement is a contact gate; display the text returned by the API.
Block/unblock is idempotent and enforces messaging restrictions in both
directions through REST and Socket.IO. Responses add `userId`, `blocked` and
`chatStatus:{otherUserId,blockedByMe,canMessage}`. History responses also expose
`chatStatus`; inbox rows add `other_user_id`, `blocked_by_me` and `can_message`.
Show Block/Unblock using these fields and disable sending when unavailable.
Old history remains readable; a sender can delete their own old content.
Blocked chat writes return `403` with `code:CHAT_BLOCKED`; unknown block targets
return `404`, self-targets `400`. Discovery, phone reveal and bookings retain
their existing behavior. See [chat blocking](CHAT_EVENTS.md#block-and-unblock-messages).

### Shared bookings, feed and support

Booking status updates require the caller to be learner/provider participant,
otherwise `404`. Either participant can set `accepted`, `declined`, `cancelled`
or `completed`. There is no stricter transition matrix at this endpoint.

Feed requires city, supports one post per account per seven days, and returns
`429` with `nextAllowedAt` when too soon. Post types are `request` for learners
and `offer` for providers. Comments support a `parentCommentId` belonging to the
same post. No edit/delete post or comment API exists. Support-mine is newest
first and has no cursor. Referral redemption rejects own code (`400`), unknown
code (`404`) and an already credited account (`409`). Page-view types are
`institute`, `teacher`, `city_index`, `city_feed`, `city_directory`, `download`,
`app_profile`; public profile reads already record their view, so avoid doubles.

| Shared request field | Validation |
|---|---|
| Chat `body` | Required for send, optional for start; max 5,000 characters |
| Feed `city`, `body`, `imageUrl` | City max 160; body max 5,000; optional URL max 2048 |
| Comment `body`, `parentCommentId` | Body max 5,000; optional parent UUID must belong to the same post |
| Safety `targetType`, `targetId` | Optional target link. A valid user/post/comment/message ID is linked when available; absent, unsupported or unavailable targets save as an unlinked report. |
| Safety `reasonCategory`, `reason` | Accepts the API codes (`fraud`, `fake_profile`, `inappropriate`, `spam`, `other`) or the matching screen labels (`Fraud or scam`, `Fake profile / not who they claim`, `Inappropriate behavior`, `Spam`, `Other`); saved as the API code. Optional legacy reason max 5,000. |
| Suggestion `suggestionType`, `name`, `notes` | course/city/state/country; name max 255; optional notes max 5,000 |
| Support `subject`, `message` | Max 255 / 10,000 |
| Referral `code` | Required, max 32 |
| Analytics `target`, `platform`, `referrer` | Optional nullable strings, max 255 / 32 / 2048 |
| Avatar `avatarUrl` | URL, max 2048 |

Inbox rows include id, learner_id, provider_id, other_name, other_avatar,
last_message, last_message_at and activity_at. Feed rows include author data,
like_count, comment_count and liked_by_me. Comments include parent_comment_id;
list rows add author_name. See the saved examples for full record illustrations.

### WhatsApp and call actions

There is no dedicated REST call or WhatsApp-contact API. Learner, teacher and
institute clients first call `GET /profiles/{type}/{id}` with an eligible
signed-in bearer and provider user UUID. When allowed, `profile.phone` contains
that provider's international phone. Anonymous, unverified, suspended or
quota-exhausted reads can omit it; a saved provider can also have no number.
Hide/disable both contact actions when phone is absent or empty.

Verified viewers can reveal up to five distinct provider phones per server day.
Opening a profile can consume a reveal; rereading the same provider that day
does not consume another distinct reveal. Quota exhaustion still returns `200`,
omits phone and adds `phoneLimitReached:true,phoneRevealsRemainingToday:0`.

The frontend launches external links from the returned phone, for example:

| Action | Fictional international phone | Frontend URI |
|---|---|---|
| Call | `+919876543210` | `tel:+919876543210` |
| WhatsApp | `+919876543210` | `https://wa.me/919876543210` |

WhatsApp uses country-code digits without `+`, spaces or punctuation. These
links launch the device dialer/WhatsApp flow; the API does not place a call,
send a WhatsApp chat message or confirm delivery. WhatsApp OTP delivery is a
separate service. Only provider profile phones are exposed here; conversation
records do not include learner phone numbers.

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

### Real-time chat and broadcasting compatibility

Both `GET /broadcasting/auth` and `POST /broadcasting/auth` remain available for
legacy channel authorization. With the default Socket.IO broadcaster, a valid
participant request returns `{authorized:true,channel:"private-conversation.<id>"}`;
other configured broadcasters can return their own signed authorization format.
Use `channel_name` (and `socket_id` when required by that driver). New Socket.IO
clients use the server's protected `/chat/socket/authorize` integration.

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
retries, REST acknowledgements and socket events may overlap. Active sockets
reauthorize with Laravel every 15 minutes and stay connected while the bearer
remains valid. Reconnect after expiry/revocation and recover missed events from
REST. Closed/background apps still need FCM/APNs for OS push.

Run `npm run chat:start` and a `broadcasts` queue worker. Setup, mobile client
code, reverse-proxy and multi-node Redis instructions are in
[CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md).

### Admin-only API

These endpoints require both a valid bearer token and an authenticated user
whose database `users.is_admin` is true. They are distinct from the browser
admin portal's `ADMIN_EMAIL`/`ADMIN_PASSWORD_HASH` login.

| Method | Path | Request / query | Result |
|---|---|---|---|
| `PATCH` | `/profiles/{type}/{id}/verify` | `verified` boolean; type `teacher` or `institute` | Sets provider verification. |
| `PATCH` | `/profiles/institute/{id}/type` | `institute_type`: `independent`, `franchise`, `mcp_partner`, `bsl_direct` | Classifies an institute. |
| `PATCH` | `/profiles/institute/{id}/bsl-verified` | `verified` boolean | Sets BSL official status; enabling requires `bsl_direct`. |
| `GET` | `/safety/reports` | Admin bearer; optional `status`, `page` (default 1), `perPage` (default 30, max 100) | Lists reports with details, evidence link and screenshot availability; private paths are omitted. Returns `pagination.hasMore`. Access logged, no-store. |
| `PATCH` | `/safety/reports/{id}/status` | Admin bearer | `status`: `open`, `reviewed`, `dismissed` | Updates status and records the change in business activity history. |
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

The browser report registry is separate from these admin JSON APIs. Use the
admin session and access-logged, bounded/streamed reports described in
[REPORTING_ARCHITECTURE.md](REPORTING_ARCHITECTURE.md); API-admin status alone
does not replace that browser session. Report filters and exports share the
allowlisted registry contract.

### Health and reverse-location JSON endpoints

| Method | Path | Access | Result |
|---|---|---|---|
| `GET` | `/health` | Public | `{ok:true}` |
| `GET` | `/up` | Public | Laravel health HTML response; not the `/health` JSON contract |
| `POST` | `/location-lookup/reverse` | Public | `{location}` with Nominatim `jsonv2` address/place metadata |

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
request is also available in the developer portal's **Common** category.

### Website and download compatibility

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

### Developer portal and collection usage

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
including the public health, health-HTML, broadcasting compatibility and reverse-location endpoints, groups
endpoints into the five categories above, and can send requests to this application. It does not
list HTML website pages or the legacy Express routes, which are not served by
this Laravel application. The overview and Google → OTP workflow show the
intended sign-in sequence:

1. `POST /auth/google` with a Firebase Google ID token.
2. `POST /auth/otp/request` with `authMethod:"google"`, phone and the Google bearer.
3. `POST /auth/otp/verify` with `authMethod:"google"`, phone/code and that bearer.
4. Save the verification response's new token, then submit registration.

If Google sign-in says phone verification is not required, skip OTP request and verification.
Registration phone verification uses OTP request → OTP verify → Register.
Returning users use email/password or linked Google sign-in and follow saved
routing flags; forgot/reset-password and email-verification shortcuts are
included. The workbench can copy a token from a successful JSON
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

Select the normal or Google OTP example to match the active flow. Normal OTP
requests omit Authorization even when the workbench's token field is populated;
Google OTP examples require the saved Google bearer. Registration examples
include student, Google student, teacher and institute variants. Provider listing
examples are available for each provider role on their shared URLs.

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

The explorer's top-level API categories are Auth, Learner, Teacher, Institute
and Common, matching this reference and the existing app-team collection.
Admin-only endpoints are nested in Common and visibly labelled; their bearer
permissions are unchanged. The application base path stays
`/nearby_teachers/public` when composing request URLs. Portal login is a browser
session, not an API token or admin API privilege. Use the developer explorer
for individual calls; the collection contains alternative signup roles and
state-changing requests and is not one sequential runner scenario.

### Errors, limits and operations

Validation (`422`):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Enter a valid email address."]
  }
}
```

Laravel's exact `message` text can vary. Business/auth errors usually use
`{"error":"..."}` and may add action fields. Use `errors` for field errors,
then `error` or `message` for the display message; do not assume every error
has the same wrapper.

| Status | Client handling |
|---|---|
| `400` | Invalid business action, such as self-booking or disallowed age. |
| `401` | Missing bearer or bad login credentials; return to sign-in as appropriate. |
| `403` | Invalid/expired bearer (`Invalid or expired token`: sign in again), phone verification, suspension, consent, safety or ownership requirement. Inspect action fields; missing-token registration specifically uses `403`. |
| `404` | Missing record, invalid route ID/type, or inaccessible conversation/booking. |
| `409` | Incomplete password-login registration, already registered, account-link conflict, autosave before registration, or referral conflict. |
| `422` | Show field validation errors; no automatic retry with unchanged input. |
| `429` | Respect `Retry-After` when provided; feed frequency uses `nextAllowedAt`. |
| `5xx` | Temporary backend/integration failure; show retry state. Avoid blind retry of creation/send requests after ambiguous failures. |

| Action | Current limit |
|---|---|
| OTP request | 20/min/IP plus 3 codes/phone/10 min |
| OTP verify | 30/min/IP |
| Google sign-in | 20/min/IP |
| Email/password login | 10/min/IP |
| `/auth/register` | 10/min/IP (the completion alias has no equivalent route throttle) |
| Forgot-password / email verification request | 20/min/IP plus 3/10 min per email (forgot) or bearer (verification), separated by request path |
| Reset-password / email verification verify | 20/min/IP |
| Search / public profile | 60/min/IP |
| REST message send | 120/min/bearer |
| Socket authorization | 180/min/bearer; bridge also enforces socket limits |
| Page-view analytics | 120/min/IP |
| Reverse lookup | 30/min/IP, with at least one second between lookups per IP |
| `POST` | `/chat/events/send` | chat:send · Send message |
| `POST` | `/chat/events/delivered` | chat:delivered · Confirm delivery |
| `POST` | `/chat/events/read` | chat:read · Mark message read |
| `POST` | `/chat/events/typing` | chat:typing · User typing |
| `POST` | `/chat/events/stop_typing` | chat:stop_typing · User stopped typing |
| `POST` | `/chat/events/delete` | chat:delete · Delete message |
| `POST` | `/chat/events/edit` | chat:edit · Edit message |
| `POST` | `/chat/events/reply` | chat:reply · Reply to message |
| `POST` | `/chat/events/reaction` | chat:reaction · React to message |
| `POST` | `/chat/events/history` | chat:history · Fetch chat history |
| `POST` | `/chat/events/unread_count` | chat:unread_count · Unread message count |

Laravel uses MySQL 8 with spatial and JSON functions. The legacy Express tree
uses a separate PostgreSQL/PostGIS schema. Apply the project's pending Laravel
migrations during deployment, then clear/rebuild route/config caches and restart
long-running workers. Test migrations only on explicitly disposable databases
whose names end in `_test`; never migrate/clear application data for tests.
Notifications (including guardian consent) use `notifications`; chat broadcasts
and revocation use `broadcasts`. Run persistent workers as documented in
[PERFORMANCE.md](PERFORMANCE.md). Live chat setup is in [CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md).
No refresh, media upload, read-receipt or message-idempotency API exists here;
OS push delivery/registration is separate.

Institute address and GPS enrichment support is described in [INSTITUTE_REGISTRATION_ADDRESS.md](INSTITUTE_REGISTRATION_ADDRESS.md). No subject selection is required or accepted in institute registration.

Teacher SEO URLs now identify one `/teacher/{name-city}` page; `teacherSeoUrl` and `teacherProfileUrl` point to the same URL, or are null when publication requirements (including a supported country) are missing. See [international SEO](TEACHER_SEO_PAGES.md).
