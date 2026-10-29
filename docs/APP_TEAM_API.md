# Nearby Teacher — App integration guide

Updated: 22 October 2026. Read this with the [full endpoint reference](API.md),
[saved response examples](API_MOCK_RESPONSES.md) and existing
[app-team Postman collection](postman/app-team.postman_collection.json) and
[Student profile collection](postman/student-profile.postman_collection.json).
Examples are fictional and response fragments omit unrelated fields; they are
not live captures. Tokens are placeholders.

The guide, developer explorer and collections use these parts:
[Auth](#auth), [Learner](#learner), [Student profile](#student-profile), [Teacher](#teacher),
[Institute](#institute), [Common](#common). Categories organize workflows;
they do not grant permissions or introduce role guards. Shared chat, feed,
comments/replies, booking status and safety APIs appear in every app role and
Common. Teacher/institute categories also include provider contact lookup for
frontend WhatsApp and call actions.

| Connection setting | Value |
|---|---|
| Deployed base URL | `http://157.245.125.79/nearby_teachers/public` |
| Local | `http://127.0.0.1:8000` |
| Android emulator | `http://10.0.2.2:8000` |
| Route prefix | None; use `/auth/login`, never `/api/auth/login` |
| JSON headers | `Accept: application/json`, `Content-Type: application/json` |
| Protected calls | `Authorization: Bearer <Laravel-issued token>` |
| Live chat | Separate deployment Socket.IO URL; local default port 6001 |

Provider IDs mean user UUIDs from `user_id`, not the listing row UUID. Subject
IDs are numeric. Request fields usually use camelCase; raw records usually
snake_case. Decimals/booleans can arrive as strings or `0/1`, and raw listing
`lesson_modes` can be JSON text. Normalize these on the client. Raw timestamps
may have no offset; confirm the configured timezone. No global response wrapper
exists; most successful actions return `200`, including creation.

**Public** omits bearer. **Bearer** means a valid, unsuspended user.
**Verified** also requires phone verification for non-admins. Before verification,
protected session, GET onboarding, link-phone and logout remain available.
Logout also works while suspended. **Admin bearer** additionally requires
`users.is_admin`; developer/admin browser sessions are separate.

## Auth

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `POST` | `/auth/google` | Public | `firebaseIdToken` | First step for Google login: verifies a Firebase Google sign-in and verified email; returns `token`, `user`, `phoneVerificationRequired`. |
| `POST` | `/auth/otp/request` | Google bearer | `phone`, `authMethod:"google"` | After Google sign-in, requests an OTP for phone verification. Returns `ok`, `expiresInMinutes`. Up to 3 codes per phone per 10 minutes; codes expire after 10 minutes. |
| `POST` | `/auth/otp/request` | Public | `authMethod:"phone_otp"` (or omitted), `phone` | Registration phone verification: generates/sends an OTP; returns `ok`, `expiresInMinutes`. |
| `POST` | `/auth/otp/verify` | Public | `authMethod:"phone_otp"` (or omitted), `phone`, `code` (6 digits) | Registration phone verification: verifies the code and returns `token`, `user`, `authMethod:"phone_otp"`, routing fields. Up to 5 code attempts. |
| `POST` | `/auth/login` | Public | `email`, `password` (phone prohibited) | Email-only password login. Completed registration required; incomplete accounts receive `409 REGISTRATION_INCOMPLETE` without a token. Returns `token`, `user`, `mobileVerified` on success. |
| `POST` | `/auth/logout` | Bearer token | `{}` | Revokes all existing API sessions for this user; returns `{ok:true,message,loggedOutAllDevices:true}`. Available before mobile verification and for suspended users. |
| `GET` | `/auth/session` | Bearer token | — | Returns safe user fields, the account-type profile (`profile`), subjects, registration/onboarding flags, `profileComplete`, and `profileCompletion`. Teacher and institute profiles use `is_profile_complete` and return `profileDraft`; learner completion follows registration/age/consent gates. Teacher links: `webPageUrl`, `teacherProfileUrl`, `teacherSeoUrl` (null until published). Institute links: `webPageUrl`, `instituteProfileUrl` (null until published). Allowed before phone verification. |
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

### Normal signup

1. Open the registration form and retain unsaved input on the device.
2. OTP request: `{authMethod:"phone_otp",phone:"+919876543210"}`, **no bearer**.
3. OTP verify: same mode/phone plus `code`, **no bearer**. Save response `token`.
4. Submit `/auth/register` with that bearer and full role-specific form.
5. Student continues to Learner DOB; teacher/institute continue home/listing setup.

A completed normal account cannot use registration OTP to log in; verification
returns `409 loginRequired:true`. Use password or linked Google login. An
incomplete account can resume through OTP even if a password was already saved.

### Google signup

1. Firebase Google SDK returns the Firebase user's ID token.
2. Exchange it at `/auth/google` as `{firebaseIdToken:"<Firebase ID token>"}`.
   Save the returned Laravel token; `user.google_id` is linked.
3. If phone is pending, send `authMethod:"google"` + phone with the Google bearer
   to `/auth/otp/request`, then same mode/phone/code/bearer to `/auth/otp/verify`.
4. Save the **fresh verification response token**. It identifies the same user.
5. Submit `/auth/register` using the active token. Email/password are optional.
6. Continue by returned `nextScreen`; subsequent APIs use the active saved token.

`PATCH /auth/link-phone` is an alternative verification route. Google OTP must
include its bearer at request and verification. Missing bearer is `401
GOOGLE_AUTH_REQUIRED`; a Google OTP remains bound to its user even if the mode
flag is omitted at verification. Another Google user's bearer is rejected.
A non-Google bearer, or explicit `phone_otp` with bearer, is `409
OTP_BEARER_NOT_GOOGLE`. Phone conflict with another account is `409`; no automatic
merge occurs. If sign-in already says `phoneVerificationRequired:false`, skip
OTP. Use the Laravel bearer on backend APIs, not the Firebase ID token.

### Role-specific registration form

Shared required fields are `accountType`, `fullName`, `termsAccepted:true` and
`acknowledgeTeachersNotBackgroundChecked:true`. Normal signup requires email and
password (8–72 characters); Google may omit them to preserve Google email and
continue without a password. Supplied fields still validate.

| Role | `accountType` | Additional required fields |
|---|---|---|
| Student | `student` or `learner` | — |
| Teacher | `teacher` | `city`, `primarySubjects` (1–5 distinct existing IDs) from Common subjects |
| Institute | `institute` | `city`, `contactPerson`; fullName is institute name |

Do not put phone, mobileVerified or DOB in the registration form. The bearer
supplies verified phone; DOB belongs to a separate student page/API.

Student form success, abbreviated:

```json
{
  "mobileVerified":true,
  "registrationSubmitted":true,
  "registrationCompleted":false,
  "profileCompleted":false,
  "ageVerificationRequired":true,
  "autosaveEnabled":false,
  "canSubmit":false,
  "missingFields":[],
  "nextScreen":"age_verification",
  "nextStep":"age_verification",
  "nextStepEndpoint":"/auth/date-of-birth"
}
```

This is `200`: the form saved successfully. A pending-DOB registration retry is
also `200` and resumes DOB without resaving the form. Completed registration
retry is `409`. Teacher/institute form success immediately sets both completion
flags true and routes home. Their DOB is not required.

### Saved-data autofill, completion and returning login

Call `GET /auth/onboarding` using an OTP/Google signup bearer. Prefill `draft`
from saved DB details only: accountType/role, fullName/email/phone, city,
primarySubjects (saved subject IDs), contactPerson, passwordSet and saved acceptance flags.
Passwords are never returned. No new autosave is added; unsaved input remains
on the device. PATCH onboarding requires completed registration, so it is `409`
while a student is waiting for DOB. After completion, partial field updates
remain available. Provider listing edits use provider PUT instead.

| Flags / action | Meaning |
|---|---|
| `registrationSubmitted` | Registration form saved; timestamp is not the DOB timestamp |
| `registrationCompleted` | Complete registration; student also has saved DOB |
| `profileCompleted` | Alias of registrationCompleted; not listing completeness |
| `nextScreen` | App routing decision: onboarding, age_verification, guardian_consent, home |
| `nextStep` / `nextStepEndpoint` | Immediate action and its endpoint |

At app launch use saved bearer + `GET /auth/session`. A complete returning
Google user receives a token and home routing if no other gate remains.
Incomplete Google sign-in still returns a usable onboarding token; route to
the pending step. Correct password with incomplete registration returns:

```json
{
  "error":"Complete your registration before logging in.",
  "code":"REGISTRATION_INCOMPLETE",
  "registrationRequired":true,
  "registrationSubmitted":true,
  "registrationCompleted":false,
  "profileCompleted":false,
  "nextScreen":"age_verification",
  "nextStep":"age_verification",
  "nextStepEndpoint":"/auth/date-of-birth"
}
```

That is `409` and has **no token**; resume via signup verification. Incorrect
password is `401`. Successful password login provides a new token plus the same
routing flags. Minor guardian consent remains a separate contact gate even when
registration is complete; follow nextScreen rather than a token alone.

### Logout, recovery and token storage

`POST /auth/logout` invalidates all existing API sessions for this user. Clear
saved bearer and disconnect chat. Google clients also sign out of Firebase
locally and reauthenticate next time. Backend cutoff blocks old Firebase sessions
from API access; global Firebase-provider refresh-token revocation is not
implemented. Account/profile/phone/Google identity are not deleted by logout.
Tokens last 30 days; no refresh endpoint exists. Store them in platform secure
storage. Password reset also revokes old sessions and requires login again.
See [email recovery](EMAIL_AUTH.md) for code requirements.

## Learner

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
| `POST` | `/franchise/enquiries` | Verified bearer; learner and teacher supported (institute also currently accepted) | `name`, `phone`, `location` | Submits a franchise enquiry for admin review; the signed-in account is linked automatically. |
| `POST` | `/jobs/applications` | Verified bearer; learner and teacher supported (institute also currently accepted) | `name`, `phone`, `expectedSalary`, `courseIds` (1–5 BSL course IDs) | Submits a teaching job application for admin review; the signed-in account is linked automatically. |
| `GET` | `/search/dashboard` | Public | Required `lat`, `lng`; optional `radiusKm` (default 7, maximum 500) | `{center,radiusKm,teacherCount,instituteCount,totalCount,results}`; all eligible nearby providers. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `GET` | `/feed` | Public; bearer optional | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts; `liked_by_me` is false for guests. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `GET` | `/broadcasting/auth` | Verified participant bearer | `channel_name` query; `socket_id` for broadcaster auth when applicable | Legacy broadcaster authorization; Socket.IO clients use `/chat/socket/authorize`. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name` | Legacy channel authorization; Socket.IO clients use the events below. |

### Student profile

Student profile requests also appear under the dedicated **Student** category in
the developer explorer and in `student-profile.postman_collection.json`.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `GET` | `/student/profile` | Verified student bearer | — | Returns name, email, DOB, phone, `phone_pin`, profile image/status and `profileComplete` for the signed-in student. |
| `PATCH` | `/student/profile` | Verified student bearer | Optional `name`, `email`, `phone`, `phone_pin` | Updates the signed-in student's fields directly without OTP in this profile flow. A changed phone is marked unverified; email changes require re-verification. |
| `POST` | `/student/profile/photo` | Verified student bearer | Multipart `image` or `profile_image`, JPEG/PNG/WebP, max 5 MB | Uploads the profile image for moderation; returns `profileImage` and `profileImageStatus`. |
| `POST` | `/auth/date-of-birth` | Verified bearer | `dateOfBirth` (`YYYY-MM-DD`) | Sets learner DOB through age/guardian checks; DOB is read-only in profile PATCH. |

The saved request and response examples for these APIs are under **Student** in
the Postman collection. Profile images use multipart upload; base64 image data
is not required.

### Student DOB page and guardian consent

After form success call `/auth/date-of-birth` using the active bearer:

```json
{"dateOfBirth":"2000-01-01"}
```

For ages 13–17 send guardian details on the same DOB API:

```json
{"dateOfBirth":"2010-01-01","guardianName":"Guardian Example","guardianContact":"+919876543211"}
```

| Student state | Submitted | Completed | Next screen |
|---|---|---|---|
| Mobile/form pending | false | false | `onboarding` |
| Form saved, DOB pending | true | false | `age_verification` |
| Adult DOB saved after form | true | true | `home` |
| Minor DOB saved, guardian pending | true | true | `guardian_consent` |
| Minor consent confirmed | true | true | `home` |
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

Under 13 is rejected. `guardianContact` is an international parent phone number.
DOB returns `parentalConsent:{status,link,expiresAt,parent:{name,phone}}`; the app
team sends the link. The hosted page offers Approve/Reject. Login/session return
`parentalConsentStatus` (`pending`, `approved`, `rejected`, `expired`,
`not_required`) and `parentalConsentRequired`. Pending, rejected and expired
learners remain on `guardian_consent`; approval routes to home. See
[full contract and integration](PARENTAL_CONSENT.md).

### Discovery, booking and review integration

Load Common subjects/languages, then search/dashboard for map markers. Use
provider `profile_type` and `user_id` for the public profile. Dashboard returns
all eligible nearby providers with no 50-result cap; other searches cap results
at 50. See [dashboard fields](API.md#home-and-map-dashboard) and
[discovery/phone quota](API.md#discovery-and-provider-phone-visibility).

Anonymous provider reads omit phone. Verified signed-in viewers can reveal up
to five distinct providers per server day; a profile read can consume quota.
Quota exhaustion still returns `200` with phone omitted and quota metadata.
Invalid/unverified optional bearer can be treated as anonymous.

A booking has mode in_person/home_tuition/online, requestedTime with timezone,
provider user UUID and optional subject/price. Safety and guardian gates apply;
price is not a payment. Mine filters caller's learner_id without a separate
role gate. Message start explicitly requires learner role; shared reply APIs
are listed in each role and Common.
Review creation requires booking ownership as learner and rating 1–5; no
completed-status gate exists. No review update/delete API exists.

Learners can use shared feed APIs for request posts, comments, replies and likes.
A reply adds parentCommentId to the comment body and must reference a comment
on the same post. Call/WhatsApp actions use returned provider profile.phone;
see [contact action integration](#whatsapp-and-call-integration).

### Home and map dashboard

`GET /search/dashboard` requires `lat` and `lng`, with optional `radiusKm`
(default 7, maximum 500). It returns numeric coordinates/prices, subjects arrays,
teacher/institute/total counts and all eligible nearest-first providers. Phones
and private account fields are omitted; this lookup does not consume phone quota.
See the [complete map field reference](API.md#home-and-map-dashboard) and saved
Learner dashboard examples in the collection.

## Teacher

Teacher registration is under Auth; select teacher and provide city/subject.
No DOB is required. This category includes listing, chat, posts/comments/replies,
booking status and provider contact lookup. Use the teacher's bearer.

Add-post shortcut: **Teacher → Feed / Posts → POST /feed — Add post** in the
developer explorer and Postman. Feed, likes, comments and replies are grouped
in that same subsection.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `GET` | `/profiles/me` | Verified teacher/institute bearer | — | Shared profile read with saved account, profile, subjects, `profileComplete`, `profileDraft` and provider page URLs. |
| `PATCH` | `/profiles/me/steps/{step}` | Verified teacher/institute bearer, completed registration | Steps 1–5; account-specific fields below | Saves a section and draft progress atomically. Account type comes from the bearer, never the payload. |
| `POST` | `/profiles/me/photo` | Verified teacher/institute bearer | Multipart `image`, JPEG/PNG/WebP up to 5 MB | Submits photo for moderation; no step progress change. |
| `POST` | `/profiles/me/publish` | Verified teacher/institute bearer, completed registration | `agreeToTerms:true` | Step 6: validates required fields, records terms and completion, returns provider page links. |
| `PUT` | `/profiles/me` | Verified provider bearer, completed registration | Provider fields described below | Updates the signed-in teacher/institute listing and its subject assignments. |
| `GET` | `/teacher/profile` | Verified teacher bearer | — | Returns the signed-in teacher's account, profile fields, subjects, `profileComplete` flag and `webPageUrl` (null until publish). |
| `PATCH` | `/teacher/profile/steps/{step}` | Verified teacher bearer, completed registration | Step 1 `fullName`, `listingName`; 2 subjects; 3 lesson modes/location; 4 rate/trial; 5 story/LinkedIn | Saves one allowlisted profile section. Account email is returned by `GET /teacher/profile` and is read-only. Step 5 bio must contain at least 100 characters when supplied. |
| `POST` | `/teacher/profile/photo` | Verified teacher bearer | Multipart `image`, JPEG/PNG/WebP, max 5 MB | Stores the image and submits it for moderation; returns `user.avatar_url`, `user.avatar_status` and a moderation message. |
| `POST` | `/teacher/profile/publish` | Verified teacher bearer | `agreeToTerms:true` | Requires subject, city, district, country, teaching mode and 100-character bio; sets `is_profile_complete`, records acceptance and creates the teacher page plus location/subject SEO listings. Returns `profileComplete:true` and the teacher page URL keys. |
| `GET` | `/profiles/me/stats` | Verified bearer | Optional `days` (default 30, maximum 365) | Provider listing views and new booking-request counts. |
| `GET` | `/bookings/incoming` | Verified bearer | `perPage`, `cursor` | Provider's incoming bookings. |
| `GET` | `/profiles/{type}/{id}` | Public, optional bearer | `type`: `teacher` or `institute`; optional auth affects phone quota | Public listing profile. Suspended or not-yet-phone-verified optional tokens are treated as anonymous. |
| `POST` | `/franchise/enquiries` | Verified bearer; learner and teacher supported (institute also currently accepted) | `name`, `phone`, `location` | Submits a franchise enquiry for admin review; the signed-in account is linked automatically. |
| `POST` | `/jobs/applications` | Verified bearer; learner and teacher supported (institute also currently accepted) | `name`, `phone`, `expectedSalary`, `courseIds` (1–5 BSL course IDs) | Submits a teaching job application for admin review; the signed-in account is linked automatically. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `GET` | `/feed` | Public; bearer optional | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts; `liked_by_me` is false for guests. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `GET` | `/broadcasting/auth` | Verified participant bearer | `channel_name` query; `socket_id` for broadcaster auth when applicable | Legacy broadcaster authorization; Socket.IO clients use `/chat/socket/authorize`. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name` | Legacy channel authorization; Socket.IO clients use the events below. |
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

Teacher profile drafts are saved automatically by each successful
`PATCH /teacher/profile/steps/{step}` request; no extra draft endpoint or local
storage is required. On the next login/app launch, use `GET /teacher/profile`
(or `GET /auth/session`) to restore saved fields/subjects and read `profileDraft`.
Step-save and publish responses also include the same object. After saving steps
1–3, for example:

```json
{
  "profileDraft": {
    "status": "draft",
    "totalSteps": 6,
    "completedSteps": [1, 2, 3],
    "nextStep": 4,
    "lastSavedAt": "2026-10-28 10:00:00"
  }
}
```

Open `profileDraft.nextStep` and prefill with the existing `user`, `profile` and
`subjects` response data. `completedSteps` tracks successfully saved sections,
not a substitute for publish validation: existing partial-section validation
still applies (including the 100-character rule when sending a bio). Send the section
payload when the user taps Next. Invalid requests do not advance progress.
Revisiting a saved step keeps later progress; out-of-order saves resume at the
first unsaved step. Once steps 1–5 are saved, `nextStep` is 6: submit
`POST /teacher/profile/publish` with `agreeToTerms:true`. Successful publish
returns `status:published`, `completedSteps:[1,2,3,4,5,6]`, `nextStep:null`.
Draft saves do not complete or publish an incomplete profile. Drafts have no
expiry and remain tied to the authenticated account across devices/sessions.
`lastSavedAt` is the last section-save timestamp in the application's timezone;
GET/session reads do not change it. Photo upload is separate from step progress.

Existing unpublished profiles keep their data but start with empty step tracking
(`nextStep:1`, `lastSavedAt:null`); no historical steps or save dates are invented.
Existing completed profiles return published progress immediately. Updating a
completed profile preserves its existing publication behavior. Generic
`PUT /profiles/me` updates do not advance this editor's step tracking.

Successful response examples for the teacher profile read, all five editor steps,
photo upload and publish are saved under **Teacher → Profile** in the Postman
collection. Teacher/institute listing updates, public profile reads and stats have
examples in their respective collection sections.

PUT is partial. Example:

```json
{
  "bio":"Maths lessons for school students",
  "ratePerHour":500,
  "currency":"INR",
  "lessonModes":["in_person","online"],
  "latitude":28.6139,
  "longitude":77.2090,
  "city":"Delhi",
  "country":"India",
  "countryCode":"IN",
  "trialDays":1,
  "primarySubjects":[1]
}
```

Teacher-only fields are bio (max 14 words), ratePerHour and linkedinUrl.
Coordinates are a pair, both set or both null. Subject IDs replace the set
when supplied (up to five). See [all listing validation](API.md#teacher-listing-example-and-field-rules).
PUT requires completed provider registration. Stats requires provider role;
newRequests counts all currently requested bookings, independently of days.
Incoming lists filter provider_id; they have no additional role guard.
Booking status changes and chat replies are participant APIs listed here and
in Common.

### Teacher chat, posts, comments and replies

1. `GET /messages` loads the teacher's existing inbox.
2. `GET /messages/{conversationId}` loads a conversation where it participates.
3. `POST /messages/{conversationId}` with `{body:"Yes, this slot is available."}`
   replies. Sending requires safety acknowledgement. Socket.IO uses the same
   participant checks through the listed authorization endpoint.

`POST /messages/start` remains learner-only. Teacher access here lets the
provider read/reply to an existing conversation; it does not open new chats.

For city posts use `GET /feed?city=Delhi`, then `POST /feed` to create an offer:

```json
{"city":"Delhi","body":"Maths lesson slots are available this week."}
```

Provider post_type is offer, with one post per seven days. Use like/unlike
endpoints for reactions. Both comments and replies use
`POST /feed/{postId}/comments`; send body for a comment and add parentCommentId
for a reply. The parent must belong to the same post:

```json
{"body":"Evening slots are available.","parentCommentId":"40000000-0000-4000-8000-000000000001"}
```

### Teacher WhatsApp and call

`GET /profiles/{type}/{id}` with the teacher's bearer can return another
provider's profile.phone under the daily phone quota. The frontend builds
WhatsApp/call links only when phone is present. See [contact action integration](#whatsapp-and-call-integration).
Conversation responses do not expose the learner's phone, so this does not add
teacher-to-student phone lookup from chat.

### Shared six-step provider profile editor

Both teacher and institute accounts use `/profiles/me` and its `/steps/{step}`,
`/photo` and `/publish` endpoints. Existing `/teacher/profile` routes remain
teacher-only aliases of the shared implementation. No account type or target
user ID is accepted from the client. Email and verification/classification badges
cannot be changed through the editor.

| Step | Teacher payload | Institute payload |
|---|---|---|
| 1 | `fullName`, `listingName` | `fullName` (institute name), `listingName`, `contactPerson` |
| 2 | `primarySubjects` (1–5 subject IDs) | `subjectIds` (1–5 subject IDs) |
| 3 | `lessonModes`, coordinates/address/geography | Same fields |
| 4 | `ratePerHour`, `currency`, `trialDays` | `startingRate`, `currency`, `trialDays` |
| 5 | `bio`, `linkedinUrl` | `description`, `websiteUrl`, `instructorsText` |
| 6 | `agreeToTerms:true` to `/profiles/me/publish` | Same request |

A supplied bio/description must contain 100 characters and remain within 2,000
characters. A section can be partially updated; progress records successful
section saves. Publication separately requires subjects, city, district, country,
teaching modes and the 100-character story; institutes also require `contactPerson`.
Rates, trial, website, instructors and listing name remain optional.

`GET /profiles/me`, `GET /auth/session`, step saves and publish return the same
`profileDraft` shape documented above. After steps 1–3 are saved, reopen step 4
using `profileDraft.nextStep` and prefill saved `user`, `profile`, `subjects`.
Publishing returns `completedSteps:[1,2,3,4,5,6]`, `nextStep:null` and
`profileComplete:true`. Teacher response URLs retain their names; institutes
return `instituteProfileUrl` and `webPageUrl`. Incomplete institute drafts have
null page links and cannot be opened through the public institute website route.

Institute completion in `/auth/session` now follows explicit listing publication,
separately from registration. Existing institute profiles with a nonempty slug
retain their accessible pages through a migration compatibility flag. No past
step completion, terms acceptance or save dates are fabricated. Existing profiles
without a slug begin in draft state. General `PUT /profiles/me` updates keep their
existing partial-update semantics and do not advance step progress.

Postman: import `docs/postman/teacher-profile-geography.postman_collection.json`
or `docs/postman/app-team.postman_collection.json`, then open **Teacher / Institute
→ Shared Profile API** for all steps, photo, publish and the restored-draft GET
response example. Set `token` to the corresponding provider account.

## Institute

Dedicated Postman collection: `docs/postman/institute-profile.postman_collection.json`
contains all six profile steps, photo upload, draft save/resume examples, subjects
and geography lookups. Set `baseUrl` and an institute bearer in `token`.

Institute registration is under Auth; provide institute name, city and contact.
No DOB is required. This category includes the institute's listing,
participant chat, posts/comments/replies, booking status and provider contacts.
Shared URLs keep the same access rules as Teacher/Common.

Add-post shortcut: **Institute → Feed / Posts → POST /feed — Add post** in the
developer explorer and Postman. Feed, likes, comments and replies are grouped
in that same subsection.

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
| `GET` | `/feed` | Public; bearer optional | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts; `liked_by_me` is false for guests. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `GET` | `/broadcasting/auth` | Verified participant bearer | `channel_name` query; `socket_id` for broadcaster auth when applicable | Legacy broadcaster authorization; Socket.IO clients use `/chat/socket/authorize`. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name` | Legacy channel authorization; Socket.IO clients use the events below. |
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

PUT is partial. Example:

```json
{
  "description":"Science and language classes for all levels",
  "startingRate":750,
  "currency":"INR",
  "lessonModes":["in_person","online"],
  "latitude":28.6139,
  "longitude":77.2090,
  "city":"Delhi",
  "country":"India",
  "countryCode":"IN",
  "subjectIds":[1],
  "instructorsText":"Experienced subject instructors",
  "websiteUrl":"https://example.com/academy"
}
```

Institute-only fields are description (max 14 words), startingRate,
instructorsText and websiteUrl. Shared listing validation, pagination and stats
semantics match Teacher. See [all institute fields](API.md#institute-listing-example-and-field-rules).
Verification/classification is admin-only under Common. Institute users cannot
set their own verification badge through profile PUT.

### Institute chat, posts, comments and replies

The institute loads `GET /messages`, opens `GET /messages/{conversationId}` and
replies with `POST /messages/{conversationId}` using its own bearer. It must be
a conversation participant. `POST /messages/start` remains learner-only. Listed
Socket.IO authorization and safety APIs support the same existing chat flow.

The institute can read city feed, publish an offer, like/unlike, comment and
reply using the feed routes above. Posts have post_type offer, accept subjectId (the subject catalog ID), and remain
limited to once per seven days. Feed responses return the subject name in `subject`; the database ID is used only when creating a post. Example POST /feed body:

```json
{"city":"Delhi","subjectId":3,"body":"New science classes start this month."}
```

Use `POST /feed/{postId}/comments` with body for a comment or body plus
parentCommentId for a reply; the parent comment must belong to that post.

### Institute WhatsApp and call

Read another provider's `GET /profiles/{type}/{id}` with the institute bearer,
then use eligible profile.phone for external call/WhatsApp actions. Daily quota
and missing-phone rules apply. See [contact action integration](#whatsapp-and-call-integration).
Inbox/history responses do not provide learner phone numbers.

## Common

Settings, catalog/localization, shared chat/feed/operations, admin-only and
compatibility/system endpoints are here. Classification does not widen access.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `PATCH` | `/auth/preferred-language` | Verified bearer | `language`: `en`, `hi`, `es`, `fr`, `ar`, `zh`, `pt`, or `ru` | Updates the account language. |
| `PATCH` | `/auth/avatar` | Verified bearer | `avatarUrl` (URL) | Updates the photo URL and marks it pending moderation. |
| `GET` | `/auth/safety-disclaimer` | Public | — | Returns the disclaimer text. |
| `POST` | `/auth/acknowledge-safety-disclaimer` | Verified bearer | — | Records acknowledgement required before messaging or booking. |
| `GET` | `/subjects` | Public | — | Subject domains with their subjects and BSL catalog data. |
| `GET` | `/i18n/languages` | Public | — | Supported language metadata. |
| `GET` | `/i18n/{lang}` | Public | Language code in path | Returns translations; unsupported language codes serve English and identify the requested/served language. |
| `PATCH` | `/bookings/{id}/status` | Verified bearer, participant | `status`: `accepted`, `declined`, `cancelled`, or `completed` | Updates a booking in which the caller participates. |
| `GET` | `/messages` | Verified bearer | `perPage`, `cursor` | Conversations for the signed-in participant. |
| `GET` | `/messages/{conversationId}` | Verified bearer, participant | `perPage`, `cursor` | Conversation messages. |
| `POST` | `/messages/{conversationId}` | Verified bearer, participant | `body` (maximum 5,000 characters) | Sends a message; safety and guardian-consent gates apply. |
| `POST` | `/chat/socket/authorize` | Verified bearer | Optional `conversationId` | Socket.IO server checks connection identity or private room membership. |
| `POST` | `/broadcasting/auth` | Verified bearer, conversation participant | `channel_name` | Legacy channel authorization; Socket.IO clients use the events below. |
| `GET` | `/feed` | Public; bearer optional | Required query `city`; optional `perPage`, `cursor` | Paginated city posts, with like/comment counts; `liked_by_me` is false for guests. |
| `POST` | `/feed` | Verified bearer | `city`, `body` | Creates a city post; one post per account every 7 days. |
| `POST` | `/feed/{postId}/like` | Verified bearer | — | Likes a post. |
| `DELETE` | `/feed/{postId}/like` | Verified bearer | — | Removes the caller's like. |
| `GET` | `/feed/{postId}/comments` | Public | `perPage`, `cursor` | Lists comments. |
| `POST` | `/feed/{postId}/comments` | Verified bearer | `body`; optional `parentCommentId` | Adds a comment or reply. |
| `POST` | `/safety/reports` | Verified bearer | `targetType` (`user`, `post`, `comment`, `message`), `targetId`, `reasonCategory` (`fraud`, `fake_profile`, `inappropriate`, `spam`, `other`); optional `reason` | Creates a report. |
| `POST` | `/safety/blocks/{userId}` | Verified bearer | — | Blocks a user. |
| `POST` | `/presence/update` | Verified bearer | `deviceId`, `event` (`foreground`, `activity`, `heartbeat`, `background`) | Returns `{isActive}`. Five-minute inactivity and 90-second heartbeat expiry; [integration details](PRESENCE.md). |
| `DELETE` | `/safety/blocks/{userId}` | Verified bearer | — | Unblocks a user. |
| `POST` | `/suggestions` | Verified bearer | `suggestionType` (`course`, `city`, `state`, `country`), `name`; optional `notes` | Submits a suggestion. |
| `POST` | `/support` | Verified bearer | `subject`, `message` | Creates a support ticket. |
| `GET` | `/support/mine` | Verified bearer | — | Lists the caller's support tickets. |
| `GET` | `/referrals/mine` | Verified bearer | — | Returns or creates the caller's referral code and referred users. |
| `POST` | `/referrals/redeem` | Verified bearer | `code` | Redeems a referral code. Self-referrals are rejected; an already-credited account receives `409`. |
| `POST` | `/analytics/page-view` | Public | `pageType`; optional `target`, `platform`, `referrer` | Records a view. `pageType`: `institute`, `teacher`, `city_index`, `city_feed`, `city_directory`, `download`, `app_profile`. Limited to 120 requests/minute per IP. |
| `GET` | `/health` | Public | — | `{ok:true}`. |
| `GET` | `/up` | Public | — | Laravel health HTML; use `/health` for JSON. |
| `POST` | `/location-lookup/reverse` | Public | `latitude`, `longitude` | `{location}` with reverse-geocoded Nominatim metadata; OSM coverage varies. |
| `GET` | `/broadcasting/auth` | Verified participant bearer | `channel_name` query; `socket_id` for broadcaster auth when applicable | Legacy broadcaster authorization; Socket.IO clients use `/chat/socket/authorize`. |

### Common app behavior

| Endpoint | Access | Response |
|---|---|---|
| `GET /subjects` | Public | `{bslFeatured:[{id,name,domain_id}],domains:[{id,name,icon,subjects}]}` |
| `GET /i18n/languages` | Public | `{languages:[{code,name,nativeName,rtl}]}` |
| `GET /i18n/{lang}` | Public | `{requested,served,translations}`; unsupported language falls back to English. |

Subject objects inside normal domains contain `{id,name,domain_id,is_bsl}`.
The first domain is virtual: `id:"instant-teacher-available"`,
`isVirtual:true`, with BSL featured subjects. Domain IDs therefore have mixed
string/integer types. Use subject IDs, not the virtual domain ID, in API requests.

Booking status is participant-scoped; either participant can submit any allowed
status. Conversation inbox/history/send are shared participant routes. Start/send
contact actions enforce safety/guardian gates. Cursor lists use perPage 1–100,
default 50, plus opaque nextCursor; preserve filters, deduplicate IDs and stop
at null. Message pages arrive newest-page first, each page chronological.
See [cursor semantics](API.md#cursor-pagination).

Feed supports posts/likes/comments, with one post per account every seven days;
no post/comment edit/delete APIs exist. Avatar accepts a hosted URL, not upload.
Blocking stops messaging in both directions, including existing REST/socket
conversations. Use `POST /safety/blocks/{userId}` and
`DELETE /safety/blocks/{userId}` with the other participant's user ID.
Both return `{ok,userId,blocked,chatStatus:{otherUserId,blockedByMe,canMessage}}`.
History adds `chatStatus`; inbox rows add `other_user_id`, `blocked_by_me` and
`can_message`. Show Block/Unblock and disable the composer from these fields.
Blocked writes return `403` / `CHAT_BLOCKED`; old history remains readable.
Unblock removes only the caller's block. Discovery/contact-number/booking
behavior is unchanged. See [chat blocking](CHAT_EVENTS.md#block-and-unblock-messages).
Referral/support/suggestion bodies and error shapes are in the endpoint
reference and saved examples.

Provider search results expose boolean `isActive`. Integrate app lifecycle and
real interaction events through `POST /presence/update`; automatic heartbeats
never reset the idle clock. Multiple devices are independent. See
[presence integration, errors and deployment](PRESENCE.md).

### WhatsApp and call integration

There is no dedicated call or WhatsApp-contact API. For a teacher/institute
contact, use signed-in `GET /profiles/{type}/{id}` and its returned profile.phone.
Only eligible verified viewers can reveal provider phones, with a quota of five
distinct providers per server day. Repeated reads of the same provider that day
do not use another distinct reveal. Quota exhaustion still returns `200` with
phone omitted, phoneLimitReached:true and phoneRevealsRemainingToday:0.
Anonymous/unverified/suspended viewers and profiles without a number may also
have no phone; hide/disable call and WhatsApp buttons when absent/empty.

For fictional returned phone `+919876543210`, the frontend launches:

| Button | External URI |
|---|---|
| Call | `tel:+919876543210` |
| WhatsApp | `https://wa.me/919876543210` |

The WhatsApp number contains country-code digits without plus/spaces/punctuation.
These are frontend link launches, not API calls; the server does not place a
call/send a WhatsApp chat message or confirm external delivery. WhatsApp OTP
delivery is a separate integration. Conversation APIs do not expose learner
phones, so no student-phone lookup is provided by the inbox/reply endpoints.

### Live chat

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

### Admin only

These requests require a valid API bearer for an identity with
`users.is_admin=true`; developer portal login and browser admin login do not
provide this API permission. Keep admin tests in a separate admin environment.

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `PATCH` | `/profiles/{type}/{id}/verify` | Admin bearer | `verified` boolean; type `teacher` or `institute` | Sets provider verification. |
| `PATCH` | `/profiles/institute/{id}/type` | Admin bearer | `institute_type`: `independent`, `franchise`, `mcp_partner`, `bsl_direct` | Classifies an institute. |
| `PATCH` | `/profiles/institute/{id}/bsl-verified` | Admin bearer | `verified` boolean | Sets BSL official status; enabling requires `bsl_direct`. |
| `GET` | `/safety/reports` | Admin bearer | Optional `status`: `open`, `reviewed`, `dismissed` | Lists reports (defaults to open). |
| `PATCH` | `/safety/reports/{id}/status` | Admin bearer | `status`: `open`, `reviewed`, `dismissed` | Updates report status. |
| `GET` | `/suggestions` | Admin bearer | Optional `status`: `open`, `added`, `declined` | Lists suggestions (defaults to open). |
| `PATCH` | `/suggestions/{id}/status` | Admin bearer | `status`: `open`, `added`, `declined` | Updates suggestion status. |
| `GET` | `/support` | Admin bearer | Optional `status`: `open`, `in_progress`, `resolved` | Lists support tickets (defaults to open). |
| `PATCH` | `/support/{id}/status` | Admin bearer | `status`: `open`, `in_progress`, `resolved` | Updates ticket status. |
| `GET` | `/referrals/top` | Admin bearer | — | Up to 50 top referrers. |
| `GET` | `/analytics/summary` | Admin bearer | Optional `days` | Page-view summary for the requested period (default 30 days). |
| `GET` | `/moderation/photos/pending` | Admin bearer | — | Lists photos awaiting review. |
| `PATCH` | `/moderation/photos/{userId}` | Admin bearer | `status`: `approved` or `rejected` | Reviews a pending photo. |
| `GET` | `/moderation/conversations` | Admin bearer | — | Lists conversations and message counts. |
| `GET` | `/moderation/conversations/{id}/messages` | Admin bearer | — | Reads and audit-logs admin access to a conversation. |
| `GET` | `/moderation/bookings` | Admin bearer | — | Lists bookings and audit-logs the admin access. |
| `GET` | `/moderation/contact-stats` | Admin bearer | — | Contact activity by provider and learner. |
| `POST` | `/moderation/users/{userId}/suspend` | Admin bearer | Optional `reason` | Suspends a user. |
| `POST` | `/moderation/users/{userId}/unsuspend` | Admin bearer | — | Restores a suspended user. |
| `GET` | `/moderation/users/suspended` | Admin bearer | — | Lists suspended users. |


Browser admin reports use a separate admin session and access-logged report
registry; see [reporting architecture](REPORTING_ARCHITECTURE.md).

### Developer workbench and Postman

Open `/developer/login`, then choose Auth, Learner, Student, Teacher, Institute or Common.
The explorer uses the complete deployment base path and same-origin request
restriction. Common admin-only calls retain admin checks. Request examples
use fictional UUIDs/codes; supply real records for manual testing.
Normal OTP examples omit Authorization even if the explorer token field is
populated. Google OTP examples require the saved Google bearer. Use the role
selector for student, Google student, teacher or institute registration examples
and the matching teacher/institute listing example on shared provider URLs.

Import the existing [app-team collection](postman/app-team.postman_collection.json).
Set baseUrl, phone, email/password, firebaseIdToken and existing subjectId.
Keep separate tokens/environments for learner, teacher, institute and admin.
Authentication scripts save returned token; public normal OTP requests send no
Authorization header. Google OTP variants require the saved Google bearer.
Real mobile OTP uses received code; email codes use a separate emailCode.
Choose one signup role/flow rather than running the complete collection in
sequence. Saved response examples can be previewed without sending; importing
a collection does not start a mock server. See [mock response guide](API_MOCK_RESPONSES.md).

### Errors, rate limits and deployment

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

Ensure migrations, Firebase project configuration, SMS/email delivery and queue
workers are deployed. Notifications use notifications queue; chat broadcast and
logout disconnects use broadcasts queue. See [operations](PERFORMANCE.md).
Reverse lookup sends coordinates to Nominatim and is throttled; health HTML
`/up` is a different contract from JSON `/health`. Legacy broadcasting is
compatibility-only; Socket.IO integration uses chat socket authorization.
There is no refresh/media-upload/read-receipt/message-idempotency endpoint here,
and OS push delivery requires separate integration.

Teacher registration and profile edits accept optional paired `latitude` / `longitude`, with private Google reverse-geocoding enrichment when enabled. See [TEACHER_LOCATION_GEOCODING.md](TEACHER_LOCATION_GEOCODING.md) for setup, responses, migration and village/thana limitations.

Institute registration and edits accept address and GPS fields: [Institute address API](INSTITUTE_REGISTRATION_ADDRESS.md). Institute registration requests omit subjects.

Teacher page links now use the single canonical `/teacher/{name-city}` URL on the configured SEO subdomain. Both teacher URL response keys are retained and equal when eligible. Subject/city and online listings use short country paths without a language segment; pages can display translated copy through the language selector: [international SEO](TEACHER_SEO_PAGES.md).

### Step-wise data in profile drafts

All teacher/institute owner responses that return `profileDraft` now also include
`profileDraft.steps`: six ordered entries with `step`, `completed` and `data`.
This applies to GET `/profiles/me`, teacher GET aliases, GET `/auth/session`,
step saves and successful publish. Existing progress keys stay unchanged.

`data` uses the same camelCase field names accepted by that step. Step 1 includes
name/listing name and read-only email (plus institute contact person); step 2
contains `primarySubjects` or `subjectIds`; step 3 contains teaching modes and
address; step 4 contains rate/currency/trial; step 5 contains story and links;
step 6 contains `agreeToTerms`. The latter is true only for completed profiles
with a saved terms-acceptance timestamp, not merely registered accounts.

For example, these are the first two entries (the API always returns all six):

```json
{
  "steps": [
    {"step": 1, "completed": true, "data": {"fullName": "Example Academy", "listingName": "Example Academy", "email": "academy@example.test", "contactPerson": "Asha Sharma"}},
    {"step": 2, "completed": true, "data": {"subjectIds": [1]}}
  ]
}
```

Each entry reflects current saved values, including registration/default values
in steps the user has not saved yet. Unset fields are null or empty arrays;
`completed` follows step-save tracking, separately from field presence. Email is
returned for display and remains read-only. Photo upload is a separate endpoint.
No new migration is required for this response addition. The teacher and institute
Postman collections include the new step-wise response examples.

### Country → state → city dropdowns

| Method | Path | Access | Request / query | Result / notes |
|---|---|---|---|---|
| `GET` | `/geo/countries` | Public | Optional `search` | All imported countries: `id`, `sortname`, `name`, `phonecode`. Unpaginated. |
| `GET` | `/geo/states` | Public | Required `country_id`; optional `search` | All states belonging to that country: `id`, `name`, `country_id`. |
| `GET` | `/geo/cities` | Public | Required `state_id`; optional `search`, `page`, `perPage` | Cities belonging to that state: `id`, `name`, `state_id`. Default 50 per page, maximum 100. |

Example: `/geo/countries` → `/geo/states?country_id=101` →
`/geo/cities?state_id=1&perPage=50&page=1`. Use IDs from the preceding response.
Missing/invalid parent IDs return 422; unknown IDs return 404. Cities responses
include `state_id`, `cities` and `pagination`. Clear dependent selections when
country/state changes. These replace the previous country-code geography URLs.
See [Geography API](GEOGRAPHY_API.md) and the dedicated
[Postman collection](postman/geography.postman_collection.json).
