# Nearby Teacher — App team API handoff

Updated: 21 October 2026. Contract checked against the current Laravel routes,
controllers, middleware and serializers. Examples use fictional data; response
examples show relevant fields and are not live server captures.

Share this guide together with the [Postman collection](postman/app-team.postman_collection.json).
Every request includes saved mock success responses, with selected error and empty-state
examples. Expand a request in Postman and select a **Mock** example to preview the
status and JSON without sending a request. For a shareable written version, see
[API mock response examples](API_MOCK_RESPONSES.md). These are fictional examples,
not live captures; importing them does not start a mock server.
The [full API reference](API.md) also covers admin endpoints and website routes.

## Contents

1. [Connection and data conventions](#connection-and-data-conventions)
2. [App integration flow](#app-integration-flow)
3. [Authentication and registration](#authentication-and-registration)
4. [Account and safety settings](#account-and-safety-settings)
5. [Subjects and languages](#subjects-and-languages)
6. [Search and provider profiles](#search-and-provider-profiles)
7. [Bookings](#bookings)
8. [Messages and live chat](#messages-and-live-chat)
9. [Feed and reviews](#feed-and-reviews)
10. [Support, safety, referrals and analytics](#support-safety-referrals-and-analytics)
11. [Pagination, errors and limits](#pagination-errors-and-limits)
12. [Postman and handoff checklist](#postman-and-handoff-checklist)

## Connection and data conventions

| Setting | Value |
|---|---|
| Deployed REST base URL | Deployment team supplies the HTTPS application origin. No production host is assumed here. |
| Local REST base URL | `http://127.0.0.1:8000` |
| Android emulator | `http://10.0.2.2:8000` |
| Physical device | `http://<computer-LAN-IP>:8000`, with device and server on the same network |
| Route prefix | None. Use `/auth/login`, not `/api/auth/login`. |
| Live chat URL | Deployment team supplies the public Socket.IO URL; local default `http://<server>:6001`. |
| Headers | `Accept: application/json`, and `Content-Type: application/json` for JSON bodies |
| Protected requests | `Authorization: Bearer <Laravel-issued token>` |

Access labels below: **Public** requires no token; **Bearer** requires a valid,
unsuspended identity; **Verified** additionally requires a verified phone for
non-admin users. Phone-unverified users can access only `GET /auth/session`,
`GET /auth/onboarding`, and `PATCH /auth/link-phone` among protected routes.

Request properties generally use camelCase; returned database records generally
use snake_case. Keep the documented spelling; there is no global response
wrapper. Successful app API controller responses use HTTP **200**, including
creation requests. IDs are UUID strings except numeric subject IDs. A provider
ID means the **user UUID** (`user_id` in search), not the listing's `profile.id`.

Send international phone numbers as strings with `+` and country code. Send
six-digit codes as strings. Send JSON booleans as `true`/`false`; search
`freeOnly` is the query string `true` or `false`. Prices and decimal fields may
arrive as JSON strings or numbers. Database boolean fields may arrive as `0`/`1`,
while explicitly mapped fields use booleans. `lesson_modes` in a raw profile
record can be JSON-encoded text; normalize it at the client boundary. Optional
values may be null or absent. Parse timestamps per field; raw database records
can use `YYYY-MM-DD HH:mm:ss` without an offset. Confirm the deployment timezone
before displaying these as local times. Use an unambiguous date/time with timezone
for `requestedTime`; the database stores a date/time without a timezone field.

Tokens issued by Laravel last 30 days. There is no dedicated refresh or logout
API in the current routes. On logout, clear the stored bearer and disconnect
Socket.IO; on `401`, sign in again. Password reset revokes earlier tokens.
Store tokens using the app platform's secure credential storage.

## App integration flow

| Screen / action | Requests and next step |
|---|---|
| App launch | Restore bearer, `GET /auth/session`, then `GET /auth/onboarding`. Use `registrationCompleted`, `mobileVerified` and `nextStep` to route. |
| New phone registration | OTP request → OTP verify → retain token → submit one full registration form. |
| Google sign-in | Firebase Google sign-in → `/auth/google` → if required, OTP request → `/auth/link-phone` with the Google response bearer. |
| Returning user | `/auth/login` with email/password → restore onboarding state. |
| Student after registration | Follow `nextStepEndpoint: /auth/date-of-birth`; ages 13–17 require guardian consent for contact actions. |
| Teacher/institute after registration | Follow `nextStepEndpoint: /profiles/me`; save subjects, rates, modes and coordinates for the listing. |
| Home / discovery | Load subjects, search counts and nearby results. Use the result's `profile_type` + `user_id` to open a profile. |
| Contact provider | Review/acknowledge the safety disclaimer; start conversation or create booking. Handle guardian-consent errors. |
| Chat | Load REST history, connect Socket.IO, merge by message ID, recover REST history on reconnect. |

Registration creates the role-specific account fields. It does not automatically
populate listing coordinates, rate or lesson modes. Nearby search requires
listing coordinates; other-city search requires `online` in `lessonModes`.

## Authentication and registration

| Method and endpoint | Access | JSON body | Success response / behavior |
|---|---|---|---|
| `POST /auth/otp/request` | Public | `phone` | `{"ok":true,"expiresInMinutes":10}` |
| `POST /auth/otp/verify` | Public | `phone`, `code` | `{token,user,mobileVerified:true}`; creates a minimal identity for a new phone. |
| `POST /auth/google` | Public | `firebaseIdToken` | `{token,user,mobileVerified,phoneVerificationRequired}`; requires a verified Google email. |
| `PATCH /auth/link-phone` | Bearer | `phone`, `code` | `{user,mobileVerified:true}`; a phone linked to another account returns `409`. |
| `POST /auth/login` | Public | `email`, `password` | `{token,user,mobileVerified,phoneVerificationRequired}`; phone login body field is prohibited. |
| `GET /auth/session` | Bearer | — | `{user,mobileVerified}` |
| `GET /auth/onboarding` | Bearer | — | Onboarding payload below. |
| `POST /auth/register` | Verified | Full registration form below | Onboarding payload plus `nextStepEndpoint`; saves once. |
| `POST /auth/onboarding/complete` | Verified | Same registration form | Alias of `/auth/register`. |
| `PATCH /auth/onboarding` | Verified, registered | Partial registration fields | Onboarding payload. Before registration: `409` with `autosaveEnabled:false`. |
| `POST /auth/forgot-password` | Public | `email` | `{ok:true,message,expiresInMinutes:10}`; same generic response for unknown addresses. |
| `POST /auth/reset-password` | Public | `email`, `code`, `password`, `password_confirmation` | `{ok:true,message}`; log in again. |
| `POST /auth/email/verification/request` | Verified | `{}` | `{ok:true,message,expiresInMinutes:10}` |
| `POST /auth/email/verification/verify` | Verified | `code` | `{"ok":true,"emailVerified":true}` |

Example OTP verification response (user fields abbreviated):

```json
{
  "token": "<Laravel bearer token>",
  "mobileVerified": true,
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "full_name": "New user",
    "account_type": "learner",
    "phone": "+919876543210",
    "phone_verified_at": "2026-10-21 10:00:00",
    "profile_completed_at": null
  }
}
```

Use the Laravel token returned by sign-in for app requests. Google phone-linking
uses `/auth/link-phone`; using `/auth/otp/verify` instead can resolve a separate
phone identity. If Google returns `phoneVerificationRequired:false`, skip linking.

### Registration form

| Field | Validation / roles |
|---|---|
| `accountType` | Required: `student`, `learner`, `teacher`, `institute`. `student` is stored as `learner`. |
| `fullName` | Required, max 160 characters; institute name for an institute. |
| `email` | Required valid email, max 255; must not belong to another account. |
| `password` | Required string, 8–72 characters. No confirmation field required on registration. |
| `termsAccepted` | Required accepted value; send `true`. |
| `acknowledgeTeachersNotBackgroundChecked` | Required accepted value; send `true` after showing the disclaimer. |
| `city` | Required for teacher and institute; max 120. |
| `primarySubjectId` | Required for teacher; existing numeric subject ID from `/subjects`. |
| `contactPerson` | Required for institute; max 160. |

Do not send `phone`, `phoneVerifiedAt` or `mobileVerified` in this form.
The bearer identity supplies the verified phone. Keep form state on the device
until Register is pressed; autosave starts only after successful registration.
For a registered phone, restore onboarding and continue instead of registering
again (`409`). Missing verification returns `403` with
`code:"OTP_VERIFICATION_REQUIRED"`; an invalid registration bearer returns `401`.

Teacher example; use `accountType:"student"` and omit `city`/`primarySubjectId`
for a student. For an institute use `accountType:"institute"`, institute name,
`city` and `contactPerson`, and omit `primarySubjectId`.

```json
{
  "accountType": "teacher",
  "fullName": "Asha Sharma",
  "email": "asha@example.com",
  "password": "ExamplePass123!",
  "city": "Delhi",
  "primarySubjectId": 1,
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

Onboarding response example after successful teacher registration (abbreviated):

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:05:00",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "draft": {
    "accountType": "teacher",
    "role": "teacher",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": "Delhi",
    "primarySubjectId": 1,
    "primarySubjectName": "Mathematics",
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "teacher"
  }
}
```

`nextStepEndpoint` is added on completion, not on every draft read. `canSubmit`
reflects saved server state; before registration the unsaved device form can be
valid even when this is false. Use `mobileVerified` for the verification badge,
local field validation for the submit button, and field errors from `422`.

Phone codes expire in 10 minutes, allow five attempts, and use the newest code.
Local/testing OTP mode can additionally return `testMode:true` and `otp`; use
that actual random value. Production never returns the OTP. Email codes are
single-use, expire after 10 minutes, allow five attempts, and are invalidated
on resend. Email verification is not a separate login gate. More details:
[registration](REGISTRATION.md), [email recovery](EMAIL_AUTH.md).

## Account and safety settings

| Method and endpoint | Access | Body | Success |
|---|---|---|---|
| `POST /auth/account-type` | Verified | `accountType`: `learner`, `teacher`, `institute` | `{ok:true}`; creates an empty provider profile if applicable. This endpoint does not accept `student`. |
| `PATCH /auth/preferred-language` | Verified | `language`: `en`, `hi`, `es`, `fr`, `ar`, `zh`, `pt`, `ru` | `{ok:true,preferredLanguage}` |
| `PATCH /auth/avatar` | Verified | `avatarUrl`: valid URL, max 2048 | `{user:{id,avatar_url,avatar_status:"pending"},message}` |
| `PATCH /auth/link-google` | Verified | `firebaseIdToken` | `{user}`; conflicts return `409`. |
| `POST /auth/date-of-birth` | Verified | `dateOfBirth`: `YYYY-MM-DD`; minors also `guardianName`, `guardianContact` | Adult: `{isMinor:false}`. Minor: `{isMinor:true,parentalConsentPending:true,message}`. |
| `POST /auth/parental-consent/confirm` | Public | `token`: one-time consent token | `{ok:true,confirmedFor}`; invalid/used token returns `404`. |
| `GET /auth/safety-disclaimer` | Public | — | `{text}`; display the server text. |
| `POST /auth/acknowledge-safety-disclaimer` | Verified | `{}` | `{ok:true}` |

Under 13 is rejected (`400`); ages 13–17 need guardian consent before messaging
or booking. Guardian email delivery is supported; guardian phone SMS consent
delivery is currently unimplemented. Prefer guardian email in the app flow.
Refresh `/auth/session` to observe `parental_consent_confirmed_at`.
Avatar updates accept an already-hosted URL; there is no image upload API here.
Public listing images appear only after moderation approval.

## Subjects and languages

| Endpoint | Access | Response |
|---|---|---|
| `GET /subjects` | Public | `{bslFeatured:[{id,name,domain_id}],domains:[{id,name,icon,subjects}]}` |
| `GET /i18n/languages` | Public | `{languages:[{code,name,nativeName,rtl}]}` |
| `GET /i18n/{lang}` | Public | `{requested,served,translations}`; unsupported language falls back to English. |

Subject objects inside normal domains contain `{id,name,domain_id,is_bsl}`.
The first domain is virtual: `id:"instant-teacher-available"`,
`isVirtual:true`, with BSL featured subjects. Domain IDs therefore have mixed
string/integer types. Use subject IDs, not the virtual domain ID, in API requests.

## Search and provider profiles

All search and public profile routes allow 60 requests/minute per IP.
The original search result endpoints are capped at 50 and do not use cursor
pagination. `/search/dashboard` returns all matching providers without that cap.

| Endpoint | Required query / path | Optional query | Response |
|---|---|---|---|
| `GET /search/dashboard` | `lat`, `lng` | `radiusKm` >0..500, default 7 | Home/map: `{center,radiusKm,teacherCount,instituteCount,totalCount,results}`; all eligible nearby providers. |
| `GET /search` | `lat` −90..90, `lng` −180..180 | `radiusKm` >0..500 (default 7), existing `subjectId`, `q` max 200, `freeOnly=true/false` | `{results:[...]}`, nearest first. |
| `GET /search/counts` | `lat`, `lng` | `radiusKm` same bounds/default | `{teacher_count,institute_count}`; does not apply subject/name/free filters. |
| `GET /search/other-city` | `city` max 160 | `subjectId`, `q` | `{results:[...]}`; online providers whose city **or country** contains the text. |
| `GET /search/by-subject/{subjectId}` | Numeric subject ID, `tier` | See tiers below | `{tier,totalCount,results}` or country groups. |
| `GET /profiles/{type}/{id}` | `type=teacher/institute`, provider user UUID | Optional bearer header | `{profile,subjects,...phoneQuotaFields}` |
| `PUT /profiles/me` | Verified bearer, completed registration | Partial JSON listing fields below | `{ok:true}` |
| `GET /profiles/me/stats` | Verified teacher/institute bearer | `days`, default 30, cap 365 | `{sinceDays,newRequests,profileViews}`; `newRequests` counts all currently requested bookings, independent of `days`. |

Subject tiers: `nearby` needs `lat`/`lng` (optional `radiusKm`); `city` needs
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
under **06 Search and profiles → GET /search/dashboard — home and map** in the
Postman collection, and in the [mock response guide](API_MOCK_RESPONSES.md).

### Listing update fields

| Fields | Rules |
|---|---|
| Shared `lessonModes` | Array of distinct `in_person`, `home_tuition`, `online`. |
| Shared `latitude`, `longitude` | Supply together; both null or both set; valid coordinate ranges. |
| Shared `addressText`, `city`, `state`, `country` | Nullable strings; max 1000, 120, 120, 120 respectively. |
| Shared `countryCode`, `currency` | Country code 2 characters; currency 3 characters and non-null when supplied. Stored uppercase. Country can derive currency if currency is omitted. |
| Shared `trialDays`, `subjectIds` | Trial 0–7; up to five distinct existing numeric subject IDs. Sending an array replaces subject assignments; `[]` clears them. |
| Teacher `bio`, `ratePerHour`, `linkedinUrl` | Bio max 14 words and 2000 characters; nullable rate 0..99999999.99; nullable URL max 2048. |
| Institute `description`, `startingRate`, `instructorsText`, `websiteUrl` | Description max 14 words and 2000 characters; same rate limits; instructors max 2000; nullable URL max 2048. |

```json
{
  "bio": "Maths lessons for school students",
  "ratePerHour": 500,
  "currency": "INR",
  "lessonModes": ["in_person", "online"],
  "latitude": 28.6139,
  "longitude": 77.209,
  "city": "Delhi",
  "state": "Delhi",
  "country": "India",
  "countryCode": "IN",
  "trialDays": 1,
  "subjectIds": [1]
}
```

## Bookings

All routes require Verified access. Creating a booking also checks safety
acknowledgement and, for a minor, confirmed guardian consent.

| Method and endpoint | Body / query | Success |
|---|---|---|
| `POST /bookings` | Required `providerId`, `mode`, `requestedTime`; optional nullable `subjectId`, `price` | `{booking}`; initial status `requested`. |
| `GET /bookings/mine` | `perPage`, `cursor` | `{bookings,pagination}`; caller is learner. Rows add `provider_name`, `subject_name`. |
| `GET /bookings/incoming` | `perPage`, `cursor` | `{bookings,pagination}`; caller is provider. Rows add `learner_name`, `subject_name`. |
| `PATCH /bookings/{id}/status` | `status`: `accepted`, `declined`, `cancelled`, `completed` | `{booking}`; caller must be a participant, otherwise `404`. |

```json
{
  "providerId": "10000000-0000-4000-8000-000000000001",
  "subjectId": 1,
  "mode": "online",
  "requestedTime": "2026-11-01T10:00:00+05:30",
  "price": 500
}
```

Booking record fields include `id`, `learner_id`, `provider_id`, `subject_id`,
`mode`, `requested_time`, `price`, `status`, `created_at`, `updated_at`.
Mode is `in_person`, `home_tuition` or `online`. Self-booking returns `400`.
The current status endpoint permits either participant to submit any listed
status; it does not enforce a provider-only accept/decline transition matrix.

## Messages and live chat

| Method and endpoint | Access / input | Success |
|---|---|---|
| `GET /messages` | Verified; `perPage`, `cursor` | `{conversations,pagination}` |
| `POST /messages/start` | Verified learner; `providerId`, optional `body` max 5000 | `{conversationId}`; reuses an existing learner/provider conversation. |
| `GET /messages/{conversationId}` | Verified participant; `perPage`, `cursor` | `{messages,pagination}` |
| `POST /messages/{conversationId}` | Verified participant; `body` required, max 5000 | `{message}` |
| `POST /chat/socket/authorize` | Verified; optional `conversationId` | `{userId,room,expiresInSeconds:900,authVersion}`; used by Socket.IO server. |

Start/send also enforce safety acknowledgement and guardian consent. Inbox rows
include `id`, `learner_id`, `provider_id`, `other_name`, `other_avatar`,
`last_message`, `last_message_at`, `activity_at`. History starts with the newest
page, returned in chronological order; prepend subsequent older pages.

```json
{
  "message": {
    "id": "20000000-0000-4000-8000-000000000001",
    "conversation_id": "30000000-0000-4000-8000-000000000001",
    "sender_id": "10000000-0000-4000-8000-000000000001",
    "body": "Hello, is this lesson slot available?",
    "created_at": "2026-10-21 10:15:00"
  }
}
```

Connect with a Socket.IO 4 compatible client using `auth:{token:bearer}`.
Use the public Socket.IO URL; a raw WebSocket client is not compatible.

| Event | Direction | Payload / acknowledgement |
|---|---|---|
| `conversation.join` | App → server | `{conversationId}`; ack `{ok:true,room}` |
| `chat.ping` | App → server | `{}`; ack `{ok:true,serverTime}`. Optional authenticated round-trip diagnostic; shares socket rate limits. |
| `conversation.leave` | App → server | `{conversationId}` |
| `message.send` | App → server | `{conversationId,body}`; ack `{ok:true,message}` or `{ok:false,status,error,errors?}` |
| `message.sent` | Server → app | `{message}`; both participants' connected devices receive it. |
| `session.expired` | Server → app | Reconnect and restore history via REST. |

Listen before connecting. Deduplicate saved messages by ID because REST replies,
socket acknowledgements and queued events overlap. Avoid sending the same action
through both REST and Socket.IO: each send persists a new message. There is no
client idempotency key, read-receipt or typing endpoint in this contract. Socket
sessions last at most 15 minutes; reconnect to reauthorize and reload REST state.
A send acknowledgement confirms persistence, not delivery/read. Background/closed
apps require separately integrated FCM/APNs. Full client example:
[CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md).

For manual testing, sign in to the developer portal and open
`/developer#realtime`. The live tester supports connection, ping, join/leave,
real message sends, ACK/event logs and copying a connection example without
credentials. Use separate participant tokens in two tabs to check delivery.

## Feed and reviews

| Method and endpoint | Access | Input | Success |
|---|---|---|---|
| `GET /feed` | Verified | Required query `city` max 160; `perPage`, `cursor` | `{posts,pagination}` |
| `POST /feed` | Verified | `city` max 160, `body` max 5000; optional nullable `subjectId`, `imageUrl` (URL max 2048) | `{post}`; one post per 7 days, else `429` with `nextAllowedAt`. |
| `POST /feed/{postId}/like` | Verified | `{}` | `{ok:true}` |
| `DELETE /feed/{postId}/like` | Verified | — | `{ok:true}` |
| `GET /feed/{postId}/comments` | Public | `perPage`, `cursor` | `{comments,pagination}`, oldest first. |
| `POST /feed/{postId}/comments` | Verified | `body` max 5000; optional `parentCommentId` | `{comment}`; parent must belong to this post. |
| `POST /reviews` | Verified | `bookingId`, integer `rating` 1–5; optional `comment` max 5000 | `{review}`; caller must be booking's learner. |
| `GET /reviews/for/{providerId}` | Public | `perPage`, `cursor` | `{reviews,pagination}` |

Feed rows include `id`, `author_id`, `post_type` (`request` for learners, `offer`
for providers), `city`, `subject_id`, `body`, `image_url`, `created_at`, plus
`author_name`, `author_avatar`, `like_count`, `comment_count`, `liked_by_me`.
Comment rows include `id`, `post_id`, `user_id`, `parent_comment_id`, `body`,
`created_at`; list rows add `author_name`. Reviews include `id`, `booking_id`,
`learner_id`, `provider_id`, `rating`, `comment`, `created_at`; lists add
`learner_name`. The review controller checks booking ownership, without a
completed-status gate. No update/delete post, comment or review routes exist.

## Support, safety, referrals and analytics

| Method and endpoint | Access | Input | Success |
|---|---|---|---|
| `POST /safety/reports` | Verified | `targetType`: `user/post/comment/message`; UUID `targetId`; `reasonCategory`: `fraud/fake_profile/inappropriate/spam/other`; optional `reason` max 5000 | `{report}` |
| `POST /safety/blocks/{userId}` | Verified | `{}` | `{ok:true}`; self-block returns `400`. |
| `DELETE /safety/blocks/{userId}` | Verified | — | `{ok:true}` |
| `POST /suggestions` | Verified | `suggestionType`: `course/city/state/country`; `name` max 255; optional `notes` max 5000 | `{suggestion}` |
| `POST /support` | Verified | `subject` max 255, `message` max 10000 | `{ticket}` |
| `GET /support/mine` | Verified | — | `{tickets}`; newest first, no cursor. |
| `GET /referrals/mine` | Verified | — | `{code,link,referredCount,referred:[{id,full_name,created_at}]}` |
| `POST /referrals/redeem` | Verified | `code` max 32 | `{referral}`; unknown code `404`, own code `400`, already credited `409`. |
| `POST /analytics/page-view` | Public | `pageType`; optional nullable `target` max 255, `platform` max 32, `referrer` max 2048 | `{ok:true}` |
| `GET /health` | Public | — | `{ok:true}` |
| `POST /location-lookup/reverse` | Public | `latitude` −90..90, `longitude` −180..180 | `{location}` containing Nominatim location data; OSM coverage varies. |

Page types: `institute`, `teacher`, `city_index`, `city_feed`, `city_directory`,
`download`, `app_profile`. Profile reads already record an `app_profile` view;
avoid recording that same view twice. Reverse lookup sends coordinates to
OpenStreetMap Nominatim. See [full lookup behavior](API.md#public-coordinate-and-locality-lookup).
Block/unblock currently store the relationship; the message/search controllers
do not apply block filtering, so do not promise that blocking stops delivery.

## Pagination, errors and limits

Cursor pagination applies to inbox, messages, incoming/mine bookings, provider
reviews, feed, and comments. `perPage` is 1–100, default 50. Preserve filters
and URL-encode the opaque `nextCursor` as query `cursor`. Stop at null;
deduplicate by record ID. There is no total/page-number field.

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

Bookings/reviews/feed are newest first; comments oldest first; inbox latest
activity first. Messages return newest page first in chronological order.

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
| `401` | Missing/invalid/expired bearer or bad login credentials; return to sign-in as appropriate. |
| `403` | Phone verification, suspension, consent, safety or ownership requirement. Inspect action fields; missing-token registration specifically uses `403`. |
| `404` | Missing record, invalid route ID/type, or inaccessible conversation/booking. |
| `409` | Already registered, account-link conflict, autosave before registration, or referral conflict. |
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

## Postman and handoff checklist

Import [app-team.postman_collection.json](postman/app-team.postman_collection.json).
Set collection `baseUrl` without a trailing slash, then set `phone`, `email`,
`password` and a real `subjectId` from `/subjects`. Public requests explicitly
use no auth; protected requests inherit the collection bearer `{{token}}`.
Successful authentication automatically saves `token`, and local test OTP
requests save `otp`. With real SMS, set `otp` yourself; email codes use the
separate `emailCode` variable. Set `firebaseIdToken` from the client SDK for
Google requests. A successful conversation/booking/post creation stores its ID
for later requests. Set `providerId` to a different teacher/institute user's
UUID; set other IDs from real records.

Run individual requests in the intended flow; the collection contains alternative
registration roles and state-changing actions and is not a single sequential
runner scenario. Keep separate environments/tokens for learner and provider
when testing a full conversation or incoming booking. Password-reset examples
use `newPassword` and clear the cached token after a successful reset; update
`password` before logging in again.

Before app integration, the deployment team should provide the REST HTTPS URL,
public Socket.IO URL, Firebase client configuration, and test learner/provider
accounts. Verify SMS/email delivery, a listing with coordinates and subjects,
and the notifications/broadcasts workers. The app team should verify OTP →
registration for each role, returning login, nearby/profile navigation, paginated
history, booking updates, chat reconnect/deduplication and error display.

Current contract gaps to account for: no server logout/refresh endpoint, no
media upload endpoint, no implemented OS push registration/delivery here, no
read receipts or message idempotency key, and no block enforcement in discovery
or messaging. Backend deployment setup remains in [API.md](API.md),
[PERFORMANCE.md](PERFORMANCE.md) and [CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md).
