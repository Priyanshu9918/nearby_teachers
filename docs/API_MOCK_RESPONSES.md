# API request and mock response examples

Updated: 22 October 2026. Generated from the saved request/response examples in the
[existing app-team Postman collection](postman/app-team.postman_collection.json).
Use with the [full API reference](API.md) and [app integration guide](APP_TEAM_API.md).

The five sections match the developer explorer and collection: Auth, Learner,
Teacher, Institute and Common. Shared chat, feed, comments/replies, booking status
and safety examples appear in each app role and Common. Provider lookup for
frontend call/WhatsApp actions appears in Learner, Teacher and Institute.
Provider listing examples remain in both provider sections. Categories do not
change permissions: providers reply in existing conversations, while message
start remains learner-only. Common → Admin only requires an API-admin bearer.

**All examples are fictional contract illustrations, not live API captures.**
IDs, names, counts and dates are illustrative. Mock bearer/consent values cannot
authenticate. Some response metadata can vary by deployment; raw database decimals,
booleans and timestamps follow the conventions in the integration guide.

Base URL: `http://157.245.125.79/nearby_teachers/public`, without `/api`.
Collection placeholders such as `{{token}}`, `{{phone}}` and `{{subjectId}}` must
be configured before a real request. Public normal OTP uses No Auth. Google OTP
uses authMethod google and a Google-linked bearer at both request and verify;
save the fresh verification token for the same user. Student registration saves
the form before the separate DOB API completes registration; teachers/institutes
do not need DOB. Guardian consent is a separate gate.

In Postman, expand a request and select a saved **Mock** response to preview it
without sending a request. Importing the collection does not create a mock server.
Success/error examples are representative, not an exhaustive failure catalog.

In the authenticated developer portal, selecting an endpoint displays a saved
example in the Response panel, labelled **Mock response**. The portal reuses this
collection, choosing the closest saved example for the category and signup/role
variant. Registration completion aliases share registration examples. These are
illustrations; editing inputs does not simulate server behavior. Mock tokens
cannot be applied with the response-token button.

**Send request** removes the preview immediately and displays the actual API
response, including errors. Switching endpoints shows that endpoint's mock and
prevents an older pending response from replacing it. **Copy cURL** generates the
current request with its URL, query, headers, bearer and JSON body without
sending it. Import it in Postman with **Import → Raw text**. The generated command
remains selectable when browser clipboard access is unavailable.

The [13 named chat events](CHAT_EVENTS.md) have additional fictional examples
in the developer WebSockets sidebar and the app-team collection. Client actions
have REST equivalents; receive/new are server-only notifications.

## Auth

### POST /auth/otp/request — Normal signup (No Auth)

Normal signup: authMethod=phone_otp (or omit authMethod), No Auth. Do not send Authorization. Request issues a code, not a bearer. OTP verification returns the registration bearer. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/otp/request
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "authMethod": "phone_otp",
  "phone": "{{phone}}"
}
```

<details>
<summary>Fictional example — 200 OTP delivery accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "channels": {
    "sms": "accepted"
  }
}
```

</details>

<details>
<summary>Fictional example — 202 One configured delivery channel failed — HTTP 202</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "channels": {
    "sms": "accepted",
    "whatsapp": "failed"
  },
  "partialDelivery": true
}
```

</details>

<details>
<summary>Fictional example — 200 Local/testing only; fictional code — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "channels": {
    "sms": "simulated"
  },
  "testMode": true,
  "otp": "123456"
}
```

</details>

<details>
<summary>Fictional example — 429 OTP request limit — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "Too many codes requested. Try again in a few minutes."
}
```

</details>

<details>
<summary>Fictional example — 503 Delivery unavailable — HTTP 503</summary>

Content-Type: `application/json`

```json
{
  "error": "Unable to send a verification code right now. Please try again later."
}
```

</details>

### POST /auth/otp/request — Google signup (bearer required)

Google signup: send authMethod=google and the Laravel bearer from /auth/google. The OTP is bound to that same user. Verification returns a fresh bearer for this account; save it for registration. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/otp/request
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "authMethod": "google",
  "phone": "{{phone}}"
}
```

<details>
<summary>Fictional example — 200 OTP delivery accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "channels": {
    "sms": "accepted"
  }
}
```

</details>

<details>
<summary>Fictional example — 202 One configured delivery channel failed — HTTP 202</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "channels": {
    "sms": "accepted",
    "whatsapp": "failed"
  },
  "partialDelivery": true
}
```

</details>

<details>
<summary>Fictional example — 401 Google bearer required — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Google signup requires the bearer token returned by /auth/google.",
  "code": "GOOGLE_AUTH_REQUIRED"
}
```

</details>

<details>
<summary>Fictional example — 429 OTP request limit — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "Too many codes requested. Try again in a few minutes."
}
```

</details>

<details>
<summary>Fictional example — 503 Delivery unavailable — HTTP 503</summary>

Content-Type: `application/json`

```json
{
  "error": "Unable to send a verification code right now. Please try again later."
}
```

</details>

### POST /auth/otp/verify — Normal signup (No Auth; saves new token)

Normal signup only: send no Authorization header. A successful verification returns a new token; save it as the active bearer for registration and later calls. For Google signup use the Google OTP verification request or PATCH /auth/link-phone with the existing Google token. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/otp/verify
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "authMethod": "phone_otp",
  "phone": "{{phone}}",
  "code": "{{otp}}"
}
```

<details>
<summary>Fictional example — 200 Normal signup verified; new token — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_NEW_OTP_VERIFIED_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "New user",
    "email": null,
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "phone_otp",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 422 Incorrect code — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Incorrect code",
  "errors": {
    "code": [
      "Incorrect code"
    ]
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Already registered; use login — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "This account is already registered. Log in with your email and password.",
  "loginRequired": true,
  "loginEndpoint": "/auth/login"
}
```

</details>

### POST /auth/otp/verify — Google signup (saves fresh token)

Bearer required: use the token from Google sign-in and authMethod=google. Verifies the phone on that same user and returns a fresh token. Save that token for registration and later APIs; email/password are optional for the Google-linked user. Missing/mismatched Google bearer is rejected. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/otp/verify
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "authMethod": "google",
  "phone": "{{phone}}",
  "code": "{{otp}}"
}
```

<details>
<summary>Fictional example — 200 Same Google user; fresh token — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_NEW_OTP_VERIFIED_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 422 Incorrect code — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Incorrect code",
  "errors": {
    "code": [
      "Incorrect code"
    ]
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Google bearer required — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Google signup requires the bearer token returned by /auth/google.",
  "code": "GOOGLE_AUTH_REQUIRED"
}
```

</details>

<details>
<summary>Fictional example — 403 OTP belongs to another Google user — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Verify this OTP with the same Google bearer used to request it.",
  "code": "OTP_GOOGLE_ACCOUNT_MISMATCH"
}
```

</details>

<details>
<summary>Fictional example — 409 Phone belongs to another account — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "This phone number is already linked to a different account. Log in with that account to manage it."
}
```

</details>

### POST /auth/google

Public. Exchange the Firebase ID token from a verified Google sign-in for a Laravel bearer. Save token, follow nextScreen/nextStep. Pending registration still returns a bearer for onboarding. Firebase ID token and Laravel bearer are different credentials. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/google
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "firebaseIdToken": "{{firebaseIdToken}}"
}
```

<details>
<summary>Fictional example — 200 First Google signup; mobile pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": null,
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": null,
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "mobile_verification",
  "nextStepEndpoint": "/auth/link-phone",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Returning Google user; DOB pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Returning complete Google user; open home — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2000-01-01",
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Returning minor; guardian consent pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2010-01-01",
    "is_minor": 1,
    "guardian_name": "Example Guardian",
    "guardian_contact": "+919876543211",
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": true,
  "nextScreen": "guardian_consent",
  "nextStep": "guardian_consent",
  "nextStepEndpoint": "/auth/session",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 400 Google sign-in not verified — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "That Google sign-in could not be verified. Try again."
}
```

</details>

<details>
<summary>Fictional example — 503 Firebase unavailable — HTTP 503</summary>

Content-Type: `application/json`

```json
{
  "error": "Google sign-in is temporarily unavailable. Please try again later.",
  "code": "FIREBASE_AUTH_UNAVAILABLE"
}
```

</details>

### PATCH /auth/link-phone

Authenticated alternate phone verification endpoint. Send phone and code with the Google bearer used for OTP request. Preserves user.id and Google identity; Google user receives a fresh token that the existing script saves. Normal authenticated linking returns user/state without a new token. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/auth/link-phone
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "phone": "{{phone}}",
  "code": "{{otp}}"
}
```

<details>
<summary>Fictional example — 200 Google phone linked; fresh token for same user — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_NEW_OTP_VERIFIED_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "authMethod": "google",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 422 Incorrect OTP — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Incorrect code",
  "errors": {
    "code": [
      "Incorrect code"
    ]
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Phone belongs to another account — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "This phone number is already linked to a different account. Log in with that account to manage it."
}
```

</details>

### POST /auth/login

Public email/password login. Complete student registration includes the separate valid DOB step; teacher/institute do not require DOB. Incorrect credentials return 401. Correct credentials with incomplete registration return 409 REGISTRATION_INCOMPLETE and no token. Complete minors still follow guardian_consent until consent is confirmed. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/login
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "email": "{{email}}",
  "password": "{{password}}"
}
```

<details>
<summary>Fictional example — 200 Complete student; open home — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2000-01-01",
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "authMethod": "password",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Complete minor; guardian consent pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "token": "FICTIONAL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2010-01-01",
    "is_minor": 1,
    "guardian_name": "Example Guardian",
    "guardian_contact": "+919876543211",
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "authMethod": "password",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": true,
  "nextScreen": "guardian_consent",
  "nextStep": "guardian_consent",
  "nextStepEndpoint": "/auth/session",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 409 Form submitted; separate DOB step pending — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Complete your registration before logging in.",
  "code": "REGISTRATION_INCOMPLETE",
  "registrationRequired": true,
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 409 Registration form still pending — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Complete your registration before logging in.",
  "code": "REGISTRATION_INCOMPLETE",
  "registrationRequired": true,
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 401 Incorrect credentials — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Incorrect email or password."
}
```

</details>

### GET /auth/session

Authenticated current user and routing flags. Route by nextScreen. registrationCompleted/profileCompleted require student DOB; registrationSubmitted only records form submission. A completed minor can still require guardian consent. This endpoint does not issue a new token. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/session
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Mobile verification pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": null,
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": null,
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "mobile_verification",
  "nextStepEndpoint": "/auth/link-phone",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Registration form pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "New user",
    "email": null,
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Form saved; DOB pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Complete student; home — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2000-01-01",
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Complete minor; consent pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2010-01-01",
    "is_minor": 1,
    "guardian_name": "Example Guardian",
    "guardian_contact": "+919876543211",
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": true,
  "nextScreen": "guardian_consent",
  "nextStep": "guardian_consent",
  "nextStepEndpoint": "/auth/session",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Complete teacher; no DOB required — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "20000000-0000-4000-8000-000000000001",
    "account_type": "teacher",
    "full_name": "Example Teacher",
    "email": "teacher@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 403 Invalid or revoked token — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Invalid or expired token"
}
```

</details>

### POST /auth/logout

Authenticated. Invalidates all existing API sessions for this user and queues chat-session revocation. The script clears the collection bearer on success. Account/profile/phone/Google linkage remains. Firebase global refresh-token revocation is not performed; the app must sign out its local Firebase SDK separately. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/logout
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Logout — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "message": "Logged out successfully.",
  "loggedOutAllDevices": true
}
```

</details>

<details>
<summary>Fictional example — 401 Missing bearer — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

<details>
<summary>Fictional example — 403 Invalid or expired bearer — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Invalid or expired token"
}
```

</details>

### GET /auth/onboarding

Authenticated. Restore only details already saved in the database as draft; no new pre-registration autosave is introduced. Unverified users may read this endpoint. Actual passwords, password hashes and consent tokens are never returned. Follow nextScreen to the form or separate DOB page. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/onboarding
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Normal signup; saved database draft — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [
    "fullName",
    "email",
    "password",
    "termsAccepted",
    "acknowledgeTeachersNotBackgroundChecked"
  ],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "New user",
    "email": null,
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": false,
    "teachersNotBackgroundCheckedAcknowledged": false,
    "acknowledgeTeachersNotBackgroundChecked": false
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "New user",
    "email": null,
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Google signup; password optional — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [
    "mobile_not_verified"
  ],
  "missingFields": [
    "termsAccepted",
    "acknowledgeTeachersNotBackgroundChecked",
    "mobileVerified"
  ],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": true,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "mobile_verification",
  "nextStepEndpoint": "/auth/link-phone",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": null,
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": false,
    "teachersNotBackgroundCheckedAcknowledged": false,
    "acknowledgeTeachersNotBackgroundChecked": false
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": null,
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": null,
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Student form saved; resume separate DOB page — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Completed student; home — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2000-01-01",
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /auth/register — Student

Shared registration endpoint; Choose one role variant. Verified mobile is required. Normal signup requires email/password; Google-linked signup may omit both. Student form saves without DOB and returns 200 with nextScreen=age_verification; submit DOB only through the separate DOB API. Teacher/institute completes immediately without DOB. Retrying a saved student form while DOB is pending returns 200 and the same pending state. Completed registration returns 409; use PATCH /auth/onboarding for later changes. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/register
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "student",
  "fullName": "Asha Sharma",
  "email": "{{email}}",
  "password": "{{password}}",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

<details>
<summary>Fictional example — 200 Student form saved; separate DOB pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Retry while DOB pending; resume DOB page — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Google student form saved without email/password — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Registration already complete — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 403 Verify mobile first — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/link-phone"
}
```

</details>

<details>
<summary>Fictional example — 422 Normal signup password missing — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The password field is required.",
  "errors": {
    "password": [
      "The password field is required."
    ]
  }
}
```

</details>

### POST /auth/register — Google student (email/password optional)

Google-linked, mobile-verified bearer required. Email and password may be omitted because identity comes from the authenticated Google account, never from request email. Form submission returns 200 submitted=true/completed=false until the separate DOB API succeeds. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/register
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "student",
  "fullName": "Asha Sharma",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

<details>
<summary>Fictional example — 200 Google student form saved; separate DOB pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Mobile verification still required — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/link-phone"
}
```

</details>

### POST /auth/register — Teacher

Shared registration endpoint; Choose one role variant. Verified mobile is required. Normal signup requires email/password; Google-linked signup may omit both. Student form saves without DOB and returns 200 with nextScreen=age_verification; submit DOB only through the separate DOB API. Teacher/institute completes immediately without DOB. Retrying a saved student form while DOB is pending returns 200 and the same pending state. Completed registration returns 409; use PATCH /auth/onboarding for later changes. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/register
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "teacher",
  "fullName": "Asha Sharma",
  "email": "{{email}}",
  "password": "{{password}}",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true,
  "city": "{{city}}",
  "primarySubjectId": "{{subjectId}}"
}
```

<details>
<summary>Fictional example — 200 Teacher registration complete; no DOB step — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "teacher",
    "role": "teacher",
    "fullName": "Example Teacher",
    "email": "teacher@example.com",
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
    "id": "20000000-0000-4000-8000-000000000001",
    "account_type": "teacher",
    "full_name": "Example Teacher",
    "email": "teacher@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Registration already complete — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 403 Verify mobile first — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/link-phone"
}
```

</details>

<details>
<summary>Fictional example — 422 Normal signup password missing — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The password field is required.",
  "errors": {
    "password": [
      "The password field is required."
    ]
  }
}
```

</details>

### POST /auth/register — Institute

Shared registration endpoint; Choose one role variant. Verified mobile is required. Normal signup requires email/password; Google-linked signup may omit both. Student form saves without DOB and returns 200 with nextScreen=age_verification; submit DOB only through the separate DOB API. Teacher/institute completes immediately without DOB. Retrying a saved student form while DOB is pending returns 200 and the same pending state. Completed registration returns 409; use PATCH /auth/onboarding for later changes. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/register
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "institute",
  "fullName": "Example Learning Centre",
  "email": "{{email}}",
  "password": "{{password}}",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true,
  "city": "{{city}}",
  "contactPerson": "Asha Sharma"
}
```

<details>
<summary>Fictional example — 200 Institute registration complete; no DOB step — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "institute",
    "role": "institute",
    "fullName": "Example Institute",
    "email": "institute@example.com",
    "phone": "+919876543210",
    "city": "Delhi",
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": "Example Contact",
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "20000000-0000-4000-8000-000000000001",
    "account_type": "institute",
    "full_name": "Example Institute",
    "email": "institute@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Registration already complete — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 403 Verify mobile first — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/link-phone"
}
```

</details>

<details>
<summary>Fictional example — 422 Normal signup password missing — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The password field is required.",
  "errors": {
    "password": [
      "The password field is required."
    ]
  }
}
```

</details>

### POST /auth/onboarding/complete

Shared registration endpoint; alias of POST /auth/register. Choose one role variant. Verified mobile is required. Normal signup requires email/password; Google-linked signup may omit both. Student form saves without DOB and returns 200 with nextScreen=age_verification; submit DOB only through the separate DOB API. Teacher/institute completes immediately without DOB. Retrying a saved student form while DOB is pending returns 200 and the same pending state. Completed registration returns 409; use PATCH /auth/onboarding for later changes. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/onboarding/complete
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "student",
  "fullName": "Asha Sharma",
  "email": "{{email}}",
  "password": "{{password}}",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

<details>
<summary>Fictional example — 200 Student form saved; separate DOB pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Retry while DOB pending; resume DOB page — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 Google student form saved without email/password — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "age_verification",
  "nextStep": "age_verification",
  "nextStepEndpoint": "/auth/date-of-birth",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Registration already complete — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 403 Verify mobile first — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/link-phone"
}
```

</details>

<details>
<summary>Fictional example — 422 Normal signup password missing — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The password field is required.",
  "errors": {
    "password": [
      "The password field is required."
    ]
  }
}
```

</details>

### PATCH /auth/onboarding

Verified, completed accounts only. Updates profile fields after registration; incomplete registration returns 409. GET /auth/onboarding restores saved database details but no pre-registration autosave is added. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/auth/onboarding
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "fullName": "Asha Sharma"
}
```

<details>
<summary>Fictional example — 200 Completed student profile updated — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-22 10:00:00",
  "phoneVerificationRequired": false,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": "2000-01-01",
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-22 10:00:00",
    "terms_accepted_at": "2026-10-22 10:00:00",
    "registration_submitted_at": "2026-10-22 10:00:00",
    "profile_completed_at": "2026-10-22 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

</details>

<details>
<summary>Fictional example — 409 Registration must complete first — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Complete registration before using autosave.",
  "registrationRequired": true,
  "autosaveEnabled": false,
  "registerEndpoint": "/auth/register"
}
```

</details>

### POST /auth/forgot-password

Always generic success; set emailCode from email, not SMS otp. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/forgot-password
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "email": "{{email}}"
}
```

<details>
<summary>Fictional example — 200 Generic recovery acknowledgement — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "message": "If an eligible account exists, a password reset code will be sent to its email.",
  "expiresInMinutes": 10
}
```

</details>

### POST /auth/reset-password

Revokes previous tokens. Log in again; update password to newPassword. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/reset-password
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "email": "{{email}}",
  "code": "{{emailCode}}",
  "password": "{{newPassword}}",
  "password_confirmation": "{{newPassword}}"
}
```

<details>
<summary>Fictional example — 200 Password reset — sign in again — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "message": "Password reset successfully. Log in with your email and new password."
}
```

</details>

<details>
<summary>Fictional example — 422 Validation error — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Invalid or expired code. Request a new code and try again.",
  "errors": {
    "code": [
      "Invalid or expired code. Request a new code and try again."
    ]
  }
}
```

</details>

### POST /auth/email/verification/request

Verified phone bearer; email delivery required. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/email/verification/request
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Verification email requested — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "message": "An email verification code has been requested.",
  "expiresInMinutes": 10
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /auth/email/verification/verify

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/email/verification/verify
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "code": "{{emailCode}}"
}
```

<details>
<summary>Fictional example — 200 Email verified — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "emailVerified": true
}
```

</details>

<details>
<summary>Fictional example — 422 Validation error — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Invalid or expired code. Request a new code and try again.",
  "errors": {
    "code": [
      "Invalid or expired code. Request a new code and try again."
    ]
  }
}
```

</details>

### POST /auth/account-type

Verified bearer. Changes account role; not required after registration. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/account-type
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "accountType": "teacher"
}
```

<details>
<summary>Fictional example — 200 Account type saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### PATCH /auth/link-google

Authenticated verified user. Link a verified Firebase Google identity to this existing account. Does not merge separate users and returns no new token. Preserves the authenticated user ID; conflicts return 409. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/auth/link-google
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "firebaseIdToken": "{{firebaseIdToken}}"
}
```

<details>
<summary>Fictional example — 200 Google linked to the existing user — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Example Student",
    "email": "student@example.com",
    "phone": "+919876543210",
    "google_id": "FICTIONAL_GOOGLE_UID_NOT_A_REAL_ACCOUNT",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "terms_accepted_at": null,
    "registration_submitted_at": null,
    "profile_completed_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-22 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "email_verified_at": "2026-10-22 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": false,
  "registrationCompleted": false,
  "registrationSubmitted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "complete_profile",
  "nextStepEndpoint": "/auth/register",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 409 Google identity already linked elsewhere — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "This Google account is already linked to a different account. Log in with that account or contact support."
}
```

</details>

## Learner

### POST /auth/date-of-birth

Separate student age page after successful registration form submission. DOB is required to complete learner/student registration, not teacher/institute registration. Format YYYY-MM-DD; minimum age 13. Ages 13–17 require guardianName/guardianContact (international parent phone; response includes parentalConsent.link and parent details for app-team delivery). DOB alone does not submit an unsaved registration form. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/date-of-birth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "dateOfBirth": "2000-01-01"
}
```

<details>
<summary>Fictional example — 200 Adult student; saved form now complete — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "isMinor": false,
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": false,
  "nextScreen": "home",
  "nextStep": "home",
  "nextStepEndpoint": "/profiles/me",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 200 Minor student; complete with guardian consent pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "isMinor": true,
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": true,
  "nextScreen": "guardian_consent",
  "nextStep": "guardian_consent",
  "nextStepEndpoint": "/auth/session",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "parentalConsentStatus": "pending",
  "parentalConsentRequired": true,
  "parentalConsent": {
    "status": "pending",
    "link": "{{baseUrl}}/parental-consent/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "expiresAt": "2026-10-28 10:00:00",
    "parent": {"name": "Example Guardian", "phone": "+919876543211"}
  },
  "message": "Send this link to the parent/guardian phone. Messaging and booking require approval."
}
```

</details>

<details>
<summary>Fictional example — 400 Minimum age not met — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "You must be at least 13 to use Nearby Teacher"
}
```

</details>

<details>
<summary>Fictional example — 400 Guardian details required for a minor — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "A parent or guardian name and phone number are required for users under 18"
}
```

</details>

### POST /auth/date-of-birth — Minor with guardian details

Separate student age page after successful registration form submission. DOB is required to complete learner/student registration, not teacher/institute registration. Format YYYY-MM-DD; minimum age 13. Ages 13–17 require guardianName/guardianContact (international parent phone; response includes parentalConsent.link and parent details for app-team delivery). DOB alone does not submit an unsaved registration form. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/date-of-birth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "dateOfBirth": "2010-01-01",
  "guardianName": "Example Guardian",
  "guardianContact": "+919876543211"
}
```

<details>
<summary>Fictional example — 200 Minor student; guardian consent pending — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "isMinor": true,
  "mobileVerified": true,
  "phoneVerificationRequired": false,
  "profileCompleted": true,
  "registrationCompleted": true,
  "registrationSubmitted": true,
  "onboardingRequired": false,
  "ageVerificationRequired": false,
  "parentalConsentPending": true,
  "nextScreen": "guardian_consent",
  "nextStep": "guardian_consent",
  "nextStepEndpoint": "/auth/session",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "parentalConsentStatus": "pending",
  "parentalConsentRequired": true,
  "parentalConsent": {
    "status": "pending",
    "link": "{{baseUrl}}/parental-consent/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "expiresAt": "2026-10-28 10:00:00",
    "parent": {"name": "Example Guardian", "phone": "+919876543211"}
  },
  "message": "Send this link to the parent/guardian phone. Messaging and booking require approval."
}
```

</details>

<details>
<summary>Fictional example — 400 Guardian contact invalid — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "Enter a valid guardian phone number with country code"
}
```

</details>

### POST /auth/parental-consent/confirm

Public, no bearer. Use the single-use consent token from parentalConsent.link returned by DOB, not the student bearer. Confirm only after the guardian has consented. After success the student calls GET /auth/session to obtain nextScreen=home. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/parental-consent/confirm
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "token": "{{consentToken}}"
}
```

<details>
<summary>Fictional example — 200 Guardian consent confirmed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "parentalConsentStatus": "approved"
}
```

</details>

<details>
<summary>Fictional example — 404 This consent link is invalid or has already been used. — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "This consent link is invalid or has already been used."
}
```

</details>

### GET /search/dashboard — home and map

Public home/map dashboard. Required lat/lng, optional radiusKm >0 and <=500 (default 7). Returns ALL matching active, registered, phone-verified teachers and institutes, nearest first. Coordinates and prices are numbers, lesson_modes/subjects are arrays, flags are booleans. Counts match returned results. No pagination or 50-result cap. Phone/email are not exposed. See docs/APP_TEAM_API.md#home-and-map-dashboard. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/dashboard?lat={{lat}}&lng={{lng}}&radiusKm=7
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Teachers and institutes for map — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "center": {
    "latitude": 28.6139,
    "longitude": 77.209
  },
  "radiusKm": 7,
  "teacherCount": 1,
  "instituteCount": 1,
  "totalCount": 2,
  "results": [
    {
      "id": "10000000-0000-4000-8000-000000000008",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "profile_type": "teacher",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "currency": "INR",
      "lesson_modes": [
        "in_person",
        "online"
      ],
      "latitude": 28.6139,
      "longitude": 77.209,
      "address_text": "Example address, Delhi",
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "IN",
      "slug": "asha-sharma-delhi",
      "is_verified": false,
      "verified_at": null,
      "trial_days": 1,
      "rating_avg": 4.5,
      "rating_count": 10,
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "bio": "Maths lessons for school students",
      "rate_per_hour": 500,
      "linkedin_url": null,
      "primary_subject_id": 1,
      "price": 500,
      "distance_km": 0,
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "is_bsl": false
        }
      ],
      "isActive": false
    },
    {
      "id": "10000000-0000-4000-8000-000000000018",
      "user_id": "10000000-0000-4000-8000-000000000012",
      "profile_type": "institute",
      "full_name": "Example Learning Centre",
      "avatar_url": null,
      "currency": "INR",
      "lesson_modes": [
        "in_person",
        "online"
      ],
      "latitude": 28.6239,
      "longitude": 77.209,
      "address_text": "Example address, Delhi",
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "IN",
      "slug": "example-learning-centre-delhi",
      "is_verified": false,
      "verified_at": null,
      "trial_days": 1,
      "rating_avg": 4.5,
      "rating_count": 10,
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "price": 500,
      "distance_km": 1.11195,
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "is_bsl": false
        }
      ],
      "description": "Lessons for school students",
      "starting_rate": 500,
      "contact_person": "Asha Sharma",
      "instructors_text": null,
      "website_url": null,
      "institute_type": "independent",
      "is_bsl_official": false,
      "bsl_official_since": null,
      "isActive": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 200 No providers in radius — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "center": {
    "latitude": 28.6139,
    "longitude": 77.209
  },
  "radiusKm": 7,
  "teacherCount": 0,
  "instituteCount": 0,
  "totalCount": 0,
  "results": []
}
```

</details>

<details>
<summary>Fictional example — 422 Missing coordinates — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The lat field is required. (and 1 more error)",
  "errors": {
    "lat": [
      "The lat field is required."
    ],
    "lng": [
      "The lng field is required."
    ]
  }
}
```

</details>

### GET /search

Nearest 50; q is optional. Remove subjectId to search all subjects. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search?lat={{lat}}&lng={{lng}}&radiusKm=7&subjectId={{subjectId}}&freeOnly=false
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Nearby providers — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "distance_km": 1.25,
      "isActive": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 200 No nearby matches — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "results": []
}
```

</details>

<details>
<summary>Fictional example — 422 Validation error — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "The lat field is required.",
  "errors": {
    "lat": [
      "The lat field is required."
    ]
  }
}
```

</details>

### GET /search/counts

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/counts?lat={{lat}}&lng={{lng}}&radiusKm=7
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Providers in radius — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "teacher_count": 12,
  "institute_count": 3
}
```

</details>

### GET /search/other-city

Online providers; city or country substring match. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/other-city?city={{city}}&subjectId={{subjectId}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Online providers in matching city/country — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "country": "India",
      "isActive": false
    }
  ]
}
```

</details>

### GET /search/by-subject/{{subjectId}} — nearby

Public subject discovery near lat/lng; tier=nearby, optional radiusKm (default 7). Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/by-subject/{{subjectId}}?tier=nearby&lat={{lat}}&lng={{lng}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Subject providers — nearby — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tier": "nearby",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "distance_km": 1.25,
      "isActive": false
    }
  ]
}
```

</details>

### GET /search/by-subject/{{subjectId}} — city

Public subject discovery in the supplied city; tier=city. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/by-subject/{{subjectId}}?tier=city&city={{city}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Subject providers — city — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tier": "city",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "isActive": false
    }
  ]
}
```

</details>

### GET /search/by-subject/{{subjectId}} — state

Public subject discovery in the supplied state; tier=state. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/by-subject/{{subjectId}}?tier=state&state={{state}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Subject providers — state — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tier": "state",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "isActive": false
    }
  ]
}
```

</details>

### GET /search/by-subject/{{subjectId}} — country

Public subject discovery in the supplied country; tier=country. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/by-subject/{{subjectId}}?tier=country&country={{country}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Subject providers — country — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tier": "country",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "isActive": false
    }
  ]
}
```

</details>

### GET /search/by-subject/{{subjectId}} — other-countries

Public online-provider discovery grouped by country. Add country to request provider results for one country. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/search/by-subject/{{subjectId}}?tier=other-countries
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Countries with matching providers — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tier": "other-countries",
  "countries": [
    {
      "country": "India",
      "trainer_count": 12
    },
    {
      "country": "United Kingdom",
      "trainer_count": 4
    }
  ]
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}}

Public profile read, No Auth. Set profileType to teacher or institute and providerId to the target provider user UUID. Phone is omitted. Use the signed-in Call or WhatsApp contact variant for quota-controlled contact lookup. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Provider profile — anonymous — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}} — Call or WhatsApp contact (bearer)

Call/WhatsApp contact lookup: set profileType to teacher or institute and providerId to the target provider user UUID. With a valid eligible API bearer, profile.phone is returned only while the phone-reveal quota permits it. A caller can reveal at most five distinct provider phones per day; inspect phoneLimitReached and phoneRevealsRemainingToday and hide actions when phone is absent. The frontend opens tel:<returned phone> for calls or https://wa.me/<returned phone digits only> for WhatsApp. These are external app launches, not separate backend /call or /whatsapp APIs; they do not expose learner phone numbers or create an in-app conversation. Public No Auth profile reads omit phone. OTP delivery through WhatsApp is a separate authentication feature. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Provider profile — signed in — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 200 Daily phone reveal quota reached — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneLimitReached": true,
  "phoneRevealsRemainingToday": 0
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### POST /bookings

Learner booking workflow. Current controller does not enforce a learner-only account type; it requires authenticated verified mobile plus safety/guardian contact gates. Cannot book yourself; provider must be a teacher/institute. price is a requested booking amount, not evidence of payment/revenue. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/bookings
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "providerId": "{{providerId}}",
  "subjectId": "{{subjectId}}",
  "mode": "online",
  "requestedTime": "{{requestedTime}}",
  "price": 500
}
```

<details>
<summary>Fictional example — 200 Booking requested — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "requested",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /bookings/mine

Authenticated bookings where learner_id equals the bearer user ID; current controller does not enforce learner-only role. Cursor pagination supports perPage/cursor. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/bookings/mine?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [
    {
      "id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "subject_id": 1,
      "mode": "online",
      "requested_time": "2026-11-01 10:00:00",
      "price": "500.00",
      "status": "requested",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "provider_name": "Asha Sharma",
      "subject_name": "Mathematics"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 No bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /messages/start

Learner/student only. Starts or returns a conversation with a teacher/institute after safety acknowledgement and guardian consent gates. Save returned conversationId. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/messages/start
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "providerId": "{{providerId}}",
  "body": "Hello, are you available for lessons?"
}
```

<details>
<summary>Fictional example — 200 Conversation started or reused — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversationId": "10000000-0000-4000-8000-000000000003"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /reviews

Caller must be the booking learner. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/reviews
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "bookingId": "{{bookingId}}",
  "rating": 5,
  "comment": "Helpful lesson."
}
```

<details>
<summary>Fictional example — 200 Review created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "review": {
    "id": "10000000-0000-4000-8000-000000000009",
    "booking_id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "rating": 5,
    "comment": "Helpful lesson.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Not allowed to review this booking — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Not allowed to review this booking"
}
```

</details>

### GET /reviews/for/{{providerId}}

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/reviews/for/{{providerId}}?perPage={{perPage}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Provider reviews — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "reviews": [
    {
      "id": "10000000-0000-4000-8000-000000000009",
      "booking_id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "rating": 5,
      "comment": "Helpful lesson.",
      "created_at": "2026-10-21 10:00:00",
      "learner_name": "Asha Sharma"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

### GET /auth/safety-disclaimer

Learner workflow. Public safety-disclaimer text. Display it before acknowledging the disclaimer through the authenticated POST endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/safety-disclaimer
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Safety disclaimer — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "text": "Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the \"BSL Verified\" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor."
}
```

</details>

### POST /auth/acknowledge-safety-disclaimer

Learner workflow. Record the authenticated user’s acknowledgement after displaying the safety disclaimer. Shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/acknowledge-safety-disclaimer
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Safety acknowledgement saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### PATCH /bookings/{{bookingId}}/status

Learner workflow. Shared participant action. Either booking participant may set accepted, declined, cancelled or completed in the current controller. Non-participants receive 404; category placement adds no new role restrictions. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/bookings/{{bookingId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "status": "accepted"
}
```

<details>
<summary>Fictional example — 200 Booking accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "accepted",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Booking not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Booking not found"
}
```

</details>

### GET /messages

Learner workflow. List conversations in which the authenticated user is the learner or provider. Teacher/institute users receive existing learner-started conversations; they cannot use POST /messages/start. No participant phone is exposed by this response. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Conversation inbox — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversations": [
    {
      "id": "10000000-0000-4000-8000-000000000003",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "other_name": "Asha Sharma",
      "other_avatar": null,
      "last_message": "Is this lesson slot available?",
      "last_message_at": "2026-10-21 10:00:00",
      "activity_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /messages/{{conversationId}}

Learner workflow. Read messages only in a conversation involving the authenticated user. Latest page is returned first and each page is chronological; cursor loads older messages. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages/{{conversationId}}?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Message history — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "messages": [
    {
      "id": "10000000-0000-4000-8000-000000000007",
      "conversation_id": "10000000-0000-4000-8000-000000000003",
      "sender_id": "10000000-0000-4000-8000-000000000001",
      "body": "Is this lesson slot available?",
      "created_at": "2026-10-21 10:00:00",
      "read_at": null
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /messages/{{conversationId}}

Learner workflow. Send a reply to an existing conversation involving the authenticated user. Teacher/institute replies use this endpoint; POST /messages/start remains learner-only. Each successful send creates a message; deduplicate socket events by message ID. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/messages/{{conversationId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Is this lesson slot available?"
}
```

<details>
<summary>Fictional example — 200 Message saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "message": {
    "id": "10000000-0000-4000-8000-000000000007",
    "conversation_id": "10000000-0000-4000-8000-000000000003",
    "sender_id": "10000000-0000-4000-8000-000000000001",
    "body": "Is this lesson slot available?",
    "created_at": "2026-10-21 10:00:00",
    "read_at": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /chat/socket/authorize

Learner workflow. Authorize Socket.IO connection identity or a conversation room. Include conversationId only for a room in which the user is a participant; omit for connection identity only. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/chat/socket/authorize
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "conversationId": "{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Conversation room authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "userId": "10000000-0000-4000-8000-000000000001",
  "room": "private-conversation.10000000-0000-4000-8000-000000000003",
  "expiresInSeconds": 900,
  "authVersion": 0
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### GET /feed

Learner workflow. Read the shared city feed with perPage/cursor pagination. Feed visibility is shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed?city={{city}}&perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 City feed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "posts": [
    {
      "id": "10000000-0000-4000-8000-000000000005",
      "author_id": "10000000-0000-4000-8000-000000000001",
      "post_type": "request",
      "city": "Delhi",
      "subject_id": 1,
      "body": "Looking for maths lessons in Delhi.",
      "image_url": null,
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma",
      "author_avatar": null,
      "like_count": 2,
      "comment_count": 1,
      "liked_by_me": 0
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /feed

Learner workflow. Create a city feed post. Learner accounts produce post_type=request; teacher/institute accounts produce post_type=offer. The server derives the post type from the bearer user, not from HTTP input. One post per account per seven days. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "city": "{{city}}",
  "subjectId": "{{subjectId}}",
  "body": "Looking for maths lessons in Delhi."
}
```

<details>
<summary>Fictional example — 200 Post created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "post": {
    "id": "10000000-0000-4000-8000-000000000005",
    "author_id": "10000000-0000-4000-8000-000000000001",
    "post_type": "request",
    "city": "Delhi",
    "subject_id": 1,
    "body": "Looking for maths lessons in Delhi.",
    "image_url": null,
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 429 You can post once every 7 days — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "You can post once every 7 days",
  "nextAllowedAt": "2026-10-28T10:00:00+00:00"
}
```

</details>

### POST /feed/{{postId}}/like

Learner workflow. Like a feed post as the authenticated user; repeated likes are idempotent. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Post liked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### DELETE /feed/{{postId}}/like

Learner workflow. Remove the authenticated user’s like from a feed post. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
DELETE {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Like removed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /feed/{{postId}}/comments

Learner workflow. Public comment/reply list for the same post, with perPage/cursor pagination. parent_comment_id distinguishes a reply from a top-level comment. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed/{{postId}}/comments?perPage={{perPage}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Comments — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comments": [
    {
      "id": "10000000-0000-4000-8000-000000000006",
      "post_id": "10000000-0000-4000-8000-000000000005",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "parent_comment_id": null,
      "body": "Please share more details.",
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

### POST /feed/{{postId}}/comments

Learner workflow. Add a comment to a post. Omit parentCommentId for a top-level comment; include a parent comment from the same post for a reply. Replies are not a separate endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Please share more details."
}
```

<details>
<summary>Fictional example — 200 Comment created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "10000000-0000-4000-8000-000000000006",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "parent_comment_id": null,
    "body": "Please share more details.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /feed/{{postId}}/comments — Reply to a comment

Learner comment reply. Use a parentCommentId belonging to the same postId; another post’s comment is rejected. Same POST endpoint as a top-level comment. The post/comment author may be learner, teacher or institute. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Thank you. Please share the next available lesson time.",
  "parentCommentId": "{{parentCommentId}}"
}
```

<details>
<summary>Fictional example — 200 Reply saved under the same post — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "60000000-0000-4000-8000-000000000001",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "parent_comment_id": "10000000-0000-4000-8000-000000000006",
    "body": "Thank you. Please share the next available lesson time.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

<details>
<summary>Fictional example — 404 Parent belongs to another post — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Parent comment not found for this post"
}
```

</details>

### GET /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/broadcasting/auth?socket_id=123.456&channel_name=private-conversation.{{conversationId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/broadcasting/auth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "socket_id": "123.456",
  "channel_name": "private-conversation.{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

## Teacher

### PUT /profiles/me — teacher

Verified registered teacher bearer required. Subject array replaces all current subject assignments. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PUT {{baseUrl}}/profiles/me
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "lessonModes": [
    "in_person",
    "online"
  ],
  "latitude": 28.6139,
  "longitude": 77.209,
  "city": "{{city}}",
  "state": "{{state}}",
  "country": "{{country}}",
  "countryCode": "IN",
  "currency": "INR",
  "subjectIds": [
    "{{subjectId}}"
  ],
  "trialDays": 1,
  "bio": "Maths lessons for school students",
  "ratePerHour": 500
}
```

<details>
<summary>Fictional example — 200 Listing saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 409 Complete registration before updating your listing profile. — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Complete registration before updating your listing profile.",
  "registrationRequired": true,
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 422 Validation error — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Latitude and longitude must be provided together",
  "errors": {
    "location": [
      "Latitude and longitude must be provided together"
    ]
  }
}
```

</details>

### GET /profiles/me/stats — teacher

Teacher/institute accounts only, same endpoint in both provider folders. Returns sinceDays, newRequests and profileViews. The controller does not require completed registration for stats. days defaults to 30. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/me/stats?days=30
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Provider dashboard stats — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "sinceDays": 30,
  "newRequests": 2,
  "profileViews": 45
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /bookings/incoming — teacher

Provider incoming workflow. Returns bookings where provider_id equals the bearer user ID; controller uses ownership filtering without an explicit role gate. Available in Teacher and Institute folders. Cursor pagination supports perPage/cursor. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/bookings/incoming?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [
    {
      "id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "subject_id": 1,
      "mode": "online",
      "requested_time": "2026-11-01 10:00:00",
      "price": "500.00",
      "status": "requested",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "learner_name": "Asha Sharma",
      "subject_name": "Mathematics"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 No bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}}

Public profile read, No Auth. Set profileType to teacher or institute and providerId to the target provider user UUID. Phone is omitted. Use the signed-in Call or WhatsApp contact variant for quota-controlled contact lookup. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Provider profile — anonymous — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}} — Call or WhatsApp contact (bearer)

Call/WhatsApp contact lookup: set profileType to teacher or institute and providerId to the target provider user UUID. With a valid eligible API bearer, profile.phone is returned only while the phone-reveal quota permits it. A caller can reveal at most five distinct provider phones per day; inspect phoneLimitReached and phoneRevealsRemainingToday and hide actions when phone is absent. The frontend opens tel:<returned phone> for calls or https://wa.me/<returned phone digits only> for WhatsApp. These are external app launches, not separate backend /call or /whatsapp APIs; they do not expose learner phone numbers or create an in-app conversation. Public No Auth profile reads omit phone. OTP delivery through WhatsApp is a separate authentication feature. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Provider profile — signed in — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 200 Daily phone reveal quota reached — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneLimitReached": true,
  "phoneRevealsRemainingToday": 0
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### GET /auth/safety-disclaimer

Teacher workflow. Public safety-disclaimer text. Display it before acknowledging the disclaimer through the authenticated POST endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/safety-disclaimer
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Safety disclaimer — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "text": "Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the \"BSL Verified\" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor."
}
```

</details>

### POST /auth/acknowledge-safety-disclaimer

Teacher workflow. Record the authenticated user’s acknowledgement after displaying the safety disclaimer. Shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/acknowledge-safety-disclaimer
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Safety acknowledgement saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### PATCH /bookings/{{bookingId}}/status

Teacher workflow. Shared participant action. Either booking participant may set accepted, declined, cancelled or completed in the current controller. Non-participants receive 404; category placement adds no new role restrictions. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/bookings/{{bookingId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "status": "accepted"
}
```

<details>
<summary>Fictional example — 200 Booking accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "20000000-0000-4000-8000-000000000001",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "accepted",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Booking not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Booking not found"
}
```

</details>

### GET /messages

Teacher workflow. List conversations in which the authenticated user is the learner or provider. Teacher/institute users receive existing learner-started conversations; they cannot use POST /messages/start. No participant phone is exposed by this response. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Conversation inbox — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversations": [
    {
      "id": "10000000-0000-4000-8000-000000000003",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "20000000-0000-4000-8000-000000000001",
      "other_name": "Example Student",
      "other_avatar": null,
      "last_message": "Yes, this lesson slot is available.",
      "last_message_at": "2026-10-21 10:00:00",
      "activity_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /messages/{{conversationId}}

Teacher workflow. Read messages only in a conversation involving the authenticated user. Latest page is returned first and each page is chronological; cursor loads older messages. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages/{{conversationId}}?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Message history — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "messages": [
    {
      "id": "10000000-0000-4000-8000-000000000007",
      "conversation_id": "10000000-0000-4000-8000-000000000003",
      "sender_id": "20000000-0000-4000-8000-000000000001",
      "body": "Yes, this lesson slot is available.",
      "created_at": "2026-10-21 10:00:00",
      "read_at": null
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /messages/{{conversationId}}

Teacher workflow. Send a reply to an existing conversation involving the authenticated user. Teacher/institute replies use this endpoint; POST /messages/start remains learner-only. Each successful send creates a message; deduplicate socket events by message ID. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/messages/{{conversationId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Yes, this lesson slot is available."
}
```

<details>
<summary>Fictional example — 200 Message saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "message": {
    "id": "10000000-0000-4000-8000-000000000007",
    "conversation_id": "10000000-0000-4000-8000-000000000003",
    "sender_id": "20000000-0000-4000-8000-000000000001",
    "body": "Yes, this lesson slot is available.",
    "created_at": "2026-10-21 10:00:00",
    "read_at": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /chat/socket/authorize

Teacher workflow. Authorize Socket.IO connection identity or a conversation room. Include conversationId only for a room in which the user is a participant; omit for connection identity only. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/chat/socket/authorize
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "conversationId": "{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Conversation room authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "userId": "20000000-0000-4000-8000-000000000001",
  "room": "private-conversation.10000000-0000-4000-8000-000000000003",
  "expiresInSeconds": 900,
  "authVersion": 0
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### Feed / Posts

Teacher city-feed APIs: list posts, add an offer post, like/unlike, read comments, add a comment and reply. These existing shared endpoints are available within this role workflow; POST /feed derives post_type=offer for teacher/institute accounts. One post per account per seven days.

#### GET /feed

Teacher workflow. Read the shared city feed with perPage/cursor pagination. Feed visibility is shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed?city={{city}}&perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 City feed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "posts": [
    {
      "id": "10000000-0000-4000-8000-000000000005",
      "author_id": "20000000-0000-4000-8000-000000000001",
      "post_type": "offer",
      "city": "Delhi",
      "subject_id": 1,
      "body": "Offering maths lessons in Delhi. Ask about available sessions.",
      "image_url": null,
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Example Teacher",
      "author_avatar": null,
      "like_count": 2,
      "comment_count": 1,
      "liked_by_me": 0
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### POST /feed — Add post

Teacher workflow. Create a city feed post. Learner accounts produce post_type=request; teacher/institute accounts produce post_type=offer. The server derives the post type from the bearer user, not from HTTP input. One post per account per seven days. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "city": "{{city}}",
  "subjectId": "{{subjectId}}",
  "body": "Offering maths lessons in Delhi. Ask about available sessions."
}
```

<details>
<summary>Fictional example — 200 Post created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "post": {
    "id": "10000000-0000-4000-8000-000000000005",
    "author_id": "20000000-0000-4000-8000-000000000001",
    "post_type": "offer",
    "city": "Delhi",
    "subject_id": 1,
    "body": "Offering maths lessons in Delhi. Ask about available sessions.",
    "image_url": null,
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 429 You can post once every 7 days — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "You can post once every 7 days",
  "nextAllowedAt": "2026-10-28T10:00:00+00:00"
}
```

</details>

#### POST /feed/{{postId}}/like

Teacher workflow. Like a feed post as the authenticated user; repeated likes are idempotent. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Post liked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### DELETE /feed/{{postId}}/like

Teacher workflow. Remove the authenticated user’s like from a feed post. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
DELETE {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Like removed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### GET /feed/{{postId}}/comments

Teacher workflow. Public comment/reply list for the same post, with perPage/cursor pagination. parent_comment_id distinguishes a reply from a top-level comment. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed/{{postId}}/comments?perPage={{perPage}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Comments — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comments": [
    {
      "id": "10000000-0000-4000-8000-000000000006",
      "post_id": "10000000-0000-4000-8000-000000000005",
      "user_id": "20000000-0000-4000-8000-000000000001",
      "parent_comment_id": null,
      "body": "Please share more details.",
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Example Teacher"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

#### POST /feed/{{postId}}/comments

Teacher workflow. Add a comment to a post. Omit parentCommentId for a top-level comment; include a parent comment from the same post for a reply. Replies are not a separate endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Please share more details."
}
```

<details>
<summary>Fictional example — 200 Comment created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "10000000-0000-4000-8000-000000000006",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "20000000-0000-4000-8000-000000000001",
    "parent_comment_id": null,
    "body": "Please share more details.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### POST /feed/{{postId}}/comments — Reply to a comment

Teacher comment reply. Use a parentCommentId belonging to the same postId; another post’s comment is rejected. Same POST endpoint as a top-level comment. The post/comment author may be learner, teacher or institute. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Thank you. Please share the next available lesson time.",
  "parentCommentId": "{{parentCommentId}}"
}
```

<details>
<summary>Fictional example — 200 Reply saved under the same post — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "60000000-0000-4000-8000-000000000001",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "20000000-0000-4000-8000-000000000001",
    "parent_comment_id": "10000000-0000-4000-8000-000000000006",
    "body": "Thank you. Please share the next available lesson time.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

<details>
<summary>Fictional example — 404 Parent belongs to another post — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Parent comment not found for this post"
}
```

</details>

### GET /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/broadcasting/auth?socket_id=123.456&channel_name=private-conversation.{{conversationId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/broadcasting/auth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "socket_id": "123.456",
  "channel_name": "private-conversation.{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

## Institute

### PUT /profiles/me — institute

Verified registered institute bearer required. Subject array replaces all current subject assignments. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PUT {{baseUrl}}/profiles/me
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "lessonModes": [
    "in_person",
    "online"
  ],
  "latitude": 28.6139,
  "longitude": 77.209,
  "city": "{{city}}",
  "state": "{{state}}",
  "country": "{{country}}",
  "countryCode": "IN",
  "currency": "INR",
  "subjectIds": [
    "{{subjectId}}"
  ],
  "trialDays": 1,
  "description": "Lessons for school students",
  "startingRate": 500
}
```

<details>
<summary>Fictional example — 200 Listing saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 409 Complete registration before updating your listing profile. — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "Complete registration before updating your listing profile.",
  "registrationRequired": true,
  "registerEndpoint": "/auth/register"
}
```

</details>

<details>
<summary>Fictional example — 422 Validation error — HTTP 422</summary>

Content-Type: `application/json`

```json
{
  "message": "Latitude and longitude must be provided together",
  "errors": {
    "location": [
      "Latitude and longitude must be provided together"
    ]
  }
}
```

</details>

### GET /profiles/me/stats — institute

Teacher/institute accounts only, same endpoint in both provider folders. Returns sinceDays, newRequests and profileViews. The controller does not require completed registration for stats. days defaults to 30. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/me/stats?days=30
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Provider dashboard stats — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "sinceDays": 30,
  "newRequests": 2,
  "profileViews": 45
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /bookings/incoming — institute

Provider incoming workflow. Returns bookings where provider_id equals the bearer user ID; controller uses ownership filtering without an explicit role gate. Available in Teacher and Institute folders. Cursor pagination supports perPage/cursor. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/bookings/incoming?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [
    {
      "id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "subject_id": 1,
      "mode": "online",
      "requested_time": "2026-11-01 10:00:00",
      "price": "500.00",
      "status": "requested",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "learner_name": "Asha Sharma",
      "subject_name": "Mathematics"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 200 No bookings — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}}

Public profile read, No Auth. Set profileType to teacher or institute and providerId to the target provider user UUID. Phone is omitted. Use the signed-in Call or WhatsApp contact variant for quota-controlled contact lookup. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Provider profile — anonymous — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### GET /profiles/{{profileType}}/{{providerId}} — Call or WhatsApp contact (bearer)

Call/WhatsApp contact lookup: set profileType to teacher or institute and providerId to the target provider user UUID. With a valid eligible API bearer, profile.phone is returned only while the phone-reveal quota permits it. A caller can reveal at most five distinct provider phones per day; inspect phoneLimitReached and phoneRevealsRemainingToday and hide actions when phone is absent. The frontend opens tel:<returned phone> for calls or https://wa.me/<returned phone digits only> for WhatsApp. These are external app launches, not separate backend /call or /whatsapp APIs; they do not expose learner phone numbers or create an in-app conversation. Public No Auth profile reads omit phone. OTP delivery through WhatsApp is a separate authentication feature. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/profiles/{{profileType}}/{{providerId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Provider profile — signed in — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 200 Daily phone reveal quota reached — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneLimitReached": true,
  "phoneRevealsRemainingToday": 0
}
```

</details>

<details>
<summary>Fictional example — 200 Institute profile — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

</details>

<details>
<summary>Fictional example — 404 Not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Not found"
}
```

</details>

### GET /auth/safety-disclaimer

Institute workflow. Public safety-disclaimer text. Display it before acknowledging the disclaimer through the authenticated POST endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/safety-disclaimer
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Safety disclaimer — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "text": "Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the \"BSL Verified\" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor."
}
```

</details>

### POST /auth/acknowledge-safety-disclaimer

Institute workflow. Record the authenticated user’s acknowledgement after displaying the safety disclaimer. Shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/acknowledge-safety-disclaimer
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Safety acknowledgement saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### PATCH /bookings/{{bookingId}}/status

Institute workflow. Shared participant action. Either booking participant may set accepted, declined, cancelled or completed in the current controller. Non-participants receive 404; category placement adds no new role restrictions. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/bookings/{{bookingId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "status": "accepted"
}
```

<details>
<summary>Fictional example — 200 Booking accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "30000000-0000-4000-8000-000000000001",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "accepted",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Booking not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Booking not found"
}
```

</details>

### GET /messages

Institute workflow. List conversations in which the authenticated user is the learner or provider. Teacher/institute users receive existing learner-started conversations; they cannot use POST /messages/start. No participant phone is exposed by this response. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Conversation inbox — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversations": [
    {
      "id": "10000000-0000-4000-8000-000000000003",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "30000000-0000-4000-8000-000000000001",
      "other_name": "Example Student",
      "other_avatar": null,
      "last_message": "Yes, this lesson slot is available.",
      "last_message_at": "2026-10-21 10:00:00",
      "activity_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /messages/{{conversationId}}

Institute workflow. Read messages only in a conversation involving the authenticated user. Latest page is returned first and each page is chronological; cursor loads older messages. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages/{{conversationId}}?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Message history — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "messages": [
    {
      "id": "10000000-0000-4000-8000-000000000007",
      "conversation_id": "10000000-0000-4000-8000-000000000003",
      "sender_id": "30000000-0000-4000-8000-000000000001",
      "body": "Yes, this lesson slot is available.",
      "created_at": "2026-10-21 10:00:00",
      "read_at": null
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /messages/{{conversationId}}

Institute workflow. Send a reply to an existing conversation involving the authenticated user. Teacher/institute replies use this endpoint; POST /messages/start remains learner-only. Each successful send creates a message; deduplicate socket events by message ID. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/messages/{{conversationId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Yes, this lesson slot is available."
}
```

<details>
<summary>Fictional example — 200 Message saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "message": {
    "id": "10000000-0000-4000-8000-000000000007",
    "conversation_id": "10000000-0000-4000-8000-000000000003",
    "sender_id": "30000000-0000-4000-8000-000000000001",
    "body": "Yes, this lesson slot is available.",
    "created_at": "2026-10-21 10:00:00",
    "read_at": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /chat/socket/authorize

Institute workflow. Authorize Socket.IO connection identity or a conversation room. Include conversationId only for a room in which the user is a participant; omit for connection identity only. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/chat/socket/authorize
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "conversationId": "{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Conversation room authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "userId": "30000000-0000-4000-8000-000000000001",
  "room": "private-conversation.10000000-0000-4000-8000-000000000003",
  "expiresInSeconds": 900,
  "authVersion": 0
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### Feed / Posts

Institute city-feed APIs: list posts, add an offer post, like/unlike, read comments, add a comment and reply. These existing shared endpoints are available within this role workflow; POST /feed derives post_type=offer for teacher/institute accounts. One post per account per seven days.

#### GET /feed

Institute workflow. Read the shared city feed with perPage/cursor pagination. Feed visibility is shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed?city={{city}}&perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 City feed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "posts": [
    {
      "id": "10000000-0000-4000-8000-000000000005",
      "author_id": "30000000-0000-4000-8000-000000000001",
      "post_type": "offer",
      "city": "Delhi",
      "subject_id": 1,
      "body": "Offering maths lessons in Delhi. Contact us for available sessions.",
      "image_url": null,
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Example Institute",
      "author_avatar": null,
      "like_count": 2,
      "comment_count": 1,
      "liked_by_me": 0
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### POST /feed — Add post

Institute workflow. Create a city feed post. Learner accounts produce post_type=request; teacher/institute accounts produce post_type=offer. The server derives the post type from the bearer user, not from HTTP input. One post per account per seven days. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "city": "{{city}}",
  "subjectId": "{{subjectId}}",
  "body": "Offering maths lessons in Delhi. Contact us for available sessions."
}
```

<details>
<summary>Fictional example — 200 Post created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "post": {
    "id": "10000000-0000-4000-8000-000000000005",
    "author_id": "30000000-0000-4000-8000-000000000001",
    "post_type": "offer",
    "city": "Delhi",
    "subject_id": 1,
    "body": "Offering maths lessons in Delhi. Contact us for available sessions.",
    "image_url": null,
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 429 You can post once every 7 days — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "You can post once every 7 days",
  "nextAllowedAt": "2026-10-28T10:00:00+00:00"
}
```

</details>

#### POST /feed/{{postId}}/like

Institute workflow. Like a feed post as the authenticated user; repeated likes are idempotent. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Post liked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### DELETE /feed/{{postId}}/like

Institute workflow. Remove the authenticated user’s like from a feed post. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
DELETE {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Like removed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### GET /feed/{{postId}}/comments

Institute workflow. Public comment/reply list for the same post, with perPage/cursor pagination. parent_comment_id distinguishes a reply from a top-level comment. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed/{{postId}}/comments?perPage={{perPage}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Comments — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comments": [
    {
      "id": "10000000-0000-4000-8000-000000000006",
      "post_id": "10000000-0000-4000-8000-000000000005",
      "user_id": "30000000-0000-4000-8000-000000000001",
      "parent_comment_id": null,
      "body": "Please share more details.",
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Example Institute"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

#### POST /feed/{{postId}}/comments

Institute workflow. Add a comment to a post. Omit parentCommentId for a top-level comment; include a parent comment from the same post for a reply. Replies are not a separate endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Please share more details."
}
```

<details>
<summary>Fictional example — 200 Comment created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "10000000-0000-4000-8000-000000000006",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "30000000-0000-4000-8000-000000000001",
    "parent_comment_id": null,
    "body": "Please share more details.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

#### POST /feed/{{postId}}/comments — Reply to a comment

Institute comment reply. Use a parentCommentId belonging to the same postId; another post’s comment is rejected. Same POST endpoint as a top-level comment. The post/comment author may be learner, teacher or institute. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Thank you. Please share the next available lesson time.",
  "parentCommentId": "{{parentCommentId}}"
}
```

<details>
<summary>Fictional example — 200 Reply saved under the same post — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "60000000-0000-4000-8000-000000000001",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "30000000-0000-4000-8000-000000000001",
    "parent_comment_id": "10000000-0000-4000-8000-000000000006",
    "body": "Thank you. Please share the next available lesson time.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

<details>
<summary>Fictional example — 404 Parent belongs to another post — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Parent comment not found for this post"
}
```

</details>

### GET /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/broadcasting/auth?socket_id=123.456&channel_name=private-conversation.{{conversationId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/broadcasting/auth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "socket_id": "123.456",
  "channel_name": "private-conversation.{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

## Common

### PATCH /auth/preferred-language

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/auth/preferred-language
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "language": "{{lang}}"
}
```

<details>
<summary>Fictional example — 200 Language saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "preferredLanguage": "en"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### PATCH /auth/avatar

Use an actual already-hosted image URL. Photo awaits moderation. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/auth/avatar
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "avatarUrl": "https://example.com/avatar.jpg"
}
```

<details>
<summary>Fictional example — 200 Avatar awaiting moderation — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "avatar_url": "https://example.com/avatar.jpg",
    "avatar_status": "pending"
  },
  "message": "Photo submitted — it will appear on your profile once reviewed."
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /auth/safety-disclaimer

Shared workflow. Public safety-disclaimer text. Display it before acknowledging the disclaimer through the authenticated POST endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/auth/safety-disclaimer
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Safety disclaimer — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "text": "Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the \"BSL Verified\" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor."
}
```

</details>

### POST /auth/acknowledge-safety-disclaimer

Shared workflow. Record the authenticated user’s acknowledgement after displaying the safety disclaimer. Shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/auth/acknowledge-safety-disclaimer
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Safety acknowledgement saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /subjects

Choose actual subjectId from the response; virtual domain ID is not a subject ID. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/subjects
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Subject domains and featured subjects — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bslFeatured": [
    {
      "id": 2,
      "name": "English Speaking",
      "domain_id": 1
    }
  ],
  "domains": [
    {
      "id": "instant-teacher-available",
      "name": "Instant Teacher Available",
      "icon": "ti-bolt",
      "isVirtual": true,
      "subjects": [
        {
          "id": 2,
          "name": "English Speaking",
          "domain_id": 1
        }
      ]
    },
    {
      "id": 1,
      "name": "Academic subjects (mock)",
      "icon": "ti-book",
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "domain_id": 1,
          "is_bsl": false
        },
        {
          "id": 2,
          "name": "English Speaking",
          "domain_id": 1,
          "is_bsl": true
        }
      ]
    }
  ]
}
```

</details>

### GET /i18n/languages

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/i18n/languages
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Supported languages — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "languages": [
    {
      "code": "en",
      "name": "English",
      "nativeName": "English",
      "rtl": false
    },
    {
      "code": "hi",
      "name": "Hindi",
      "nativeName": "हिन्दी",
      "rtl": false
    },
    {
      "code": "es",
      "name": "Spanish",
      "nativeName": "Español",
      "rtl": false
    },
    {
      "code": "fr",
      "name": "French",
      "nativeName": "Français",
      "rtl": false
    },
    {
      "code": "ar",
      "name": "Arabic",
      "nativeName": "العربية",
      "rtl": true
    },
    {
      "code": "zh",
      "name": "Mandarin Chinese",
      "nativeName": "中文",
      "rtl": false
    },
    {
      "code": "pt",
      "name": "Portuguese",
      "nativeName": "Português",
      "rtl": false
    },
    {
      "code": "ru",
      "name": "Russian",
      "nativeName": "Русский",
      "rtl": false
    }
  ]
}
```

</details>

### GET /i18n/{{lang}}

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/i18n/{{lang}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 English translations — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "requested": "en",
  "served": "en",
  "translations": {
    "_meta": {
      "code": "en",
      "name": "English",
      "nativeName": "English",
      "rtl": false
    },
    "nav": {
      "home": "Home",
      "search": "Search",
      "messages": "Messages",
      "feed": "Feed",
      "profile": "Profile"
    },
    "common": {
      "appName": "Nearby Teacher",
      "byBsl": "by British School of Language",
      "search": "Search",
      "message": "Message",
      "requestBooking": "Request booking",
      "call": "Call",
      "whatsapp": "WhatsApp",
      "submit": "Submit",
      "cancel": "Cancel",
      "save": "Save",
      "edit": "Edit profile",
      "logOut": "Log out",
      "free": "Free",
      "verified": "BSL Verified",
      "loading": "Loading…",
      "seeMore": "See more",
      "back": "Back",
      "download": "Download the app",
      "openInApp": "Open in app"
    },
    "home": {
      "nearby": "Nearby",
      "otherCity": "Other city / online",
      "radius3": "3km",
      "radius7": "7km",
      "radius14": "14km",
      "freelancers": "Freelancers nearby",
      "institutes": "Institutes nearby"
    },
    "search": {
      "placeholder": "Search subject, teacher…",
      "teachersFoundNearby": "{count} teachers found nearby",
      "distance": "{km}km away",
      "rating": "{rating} ({count} reviews)",
      "lessonsCompleted": "{count} lessons completed"
    },
    "profile": {
      "trialNone": "No trial",
      "trial1Day": "1-day trial",
      "trialWeek": "1-week trial",
      "bio": "Bio",
      "subjects": "Subjects",
      "lessonModes": "Lesson modes",
      "ratePerHour": "Rate per hour",
      "website": "Visit website",
      "linkedIn": "View LinkedIn",
      "instructors": "Instructors"
    },
    "booking": {
      "confirmed": "Lesson confirmed",
      "addToCalendar": "Add to calendar",
      "status_requested": "Requested",
      "status_accepted": "Accepted",
      "status_declined": "Declined",
      "status_completed": "Completed"
    },
    "review": {
      "prompt": "How was your lesson?",
      "commentPlaceholder": "Add a comment (optional)",
      "submitReview": "Submit review"
    },
    "settings": {
      "title": "Settings",
      "location": "Location",
      "notifications": "Notifications",
      "privacySafety": "Privacy and safety",
      "reportProblem": "Report a problem",
      "contactBsl": "Contact BSL",
      "referFriend": "Refer a friend",
      "language": "Language"
    },
    "safety": {
      "disclaimerTitle": "Before you message or book",
      "acknowledge": "I understand"
    },
    "feed": {
      "title": "City feed",
      "lookingFor": "Looking for",
      "newPost": "New post",
      "like": "Like",
      "comment": "Comment",
      "share": "Share"
    },
    "referral": {
      "title": "Refer a friend",
      "subtitle": "Help a friend find a nearby teacher",
      "shareLink": "Share your link",
      "friendsJoined": "{count} friends joined using your link"
    },
    "contactBsl": {
      "title": "Contact BSL",
      "subject": "Subject",
      "messagePlaceholder": "How can we help?",
      "pastTickets": "Your past tickets"
    },
    "website": {
      "cityDirectory": "All cities",
      "teachersIn": "Teachers in {city}",
      "institutesIn": "Institutes in {city}",
      "contactDetailsInApp": "Full contact details, reviews, and booking are available in the app.",
      "notFound": "Page not found",
      "downloadPrompt": "Get the app to message, book, and see full profiles",
      "openingApp": "Opening the app…",
      "findNearYou": "Find teachers and institutes near you",
      "citiesOnPlatform": "{count} cities on Nearby Teacher",
      "noCitiesYet": "No cities listed yet — check back soon.",
      "listingsCount": "{count} listings",
      "teachersAndInstitutesIn": "Teachers and institutes in {city}",
      "subjectsCovered": "{count} subjects covered",
      "subjectsTaughtIn": "Subjects taught in {city}",
      "institutesHeading": "Institutes",
      "teachersHeading": "Teachers",
      "noInstitutesIn": "No institutes listed in {city} yet.",
      "noTeachersIn": "No individual teachers listed in {city} yet.",
      "noReviewsYet": "No reviews yet",
      "seeWhatsHappening": "See what's happening in {city}",
      "since": "since {date}",
      "fromPriceHour": "From {price}/hour",
      "priceHour": "{price}/hour",
      "downloadToMessage": "Download the app to message or book",
      "poweredBy": "Powered by",
      "allTeachersInstitutesIn": "All teachers and institutes in {city}",
      "cityFeedTitle": "{city} city feed",
      "feedSubtitle": "What teachers, institutes, and learners in {city} are posting right now.",
      "seeAllHere": "See all teachers and institutes here",
      "noPostsYet": "No posts yet in {city}. Be the first — open the app to post.",
      "replyLikePost": "Reply, like, or post your own — open the Nearby Teacher app.",
      "getApp": "Get the app"
    },
    "errors": {
      "notFound": "Not found",
      "somethingWrong": "Something went wrong",
      "tooManyRequests": "Too many requests. Please slow down and try again shortly.",
      "invalidToken": "Invalid or expired token"
    }
  }
}
```

</details>

### PATCH /bookings/{{bookingId}}/status

Shared workflow. Shared participant action. Either booking participant may set accepted, declined, cancelled or completed in the current controller. Non-participants receive 404; category placement adds no new role restrictions. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/bookings/{{bookingId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "status": "accepted"
}
```

<details>
<summary>Fictional example — 200 Booking accepted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "accepted",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Booking not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Booking not found"
}
```

</details>

### GET /messages

Shared workflow. List conversations in which the authenticated user is the learner or provider. Teacher/institute users receive existing learner-started conversations; they cannot use POST /messages/start. No participant phone is exposed by this response. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Conversation inbox — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversations": [
    {
      "id": "10000000-0000-4000-8000-000000000003",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "other_name": "Asha Sharma",
      "other_avatar": null,
      "last_message": "Is this lesson slot available?",
      "last_message_at": "2026-10-21 10:00:00",
      "activity_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /messages/{{conversationId}}

Shared workflow. Read messages only in a conversation involving the authenticated user. Latest page is returned first and each page is chronological; cursor loads older messages. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/messages/{{conversationId}}?perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Message history — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "messages": [
    {
      "id": "10000000-0000-4000-8000-000000000007",
      "conversation_id": "10000000-0000-4000-8000-000000000003",
      "sender_id": "10000000-0000-4000-8000-000000000001",
      "body": "Is this lesson slot available?",
      "created_at": "2026-10-21 10:00:00",
      "read_at": null
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /messages/{{conversationId}}

Shared workflow. Send a reply to an existing conversation involving the authenticated user. Teacher/institute replies use this endpoint; POST /messages/start remains learner-only. Each successful send creates a message; deduplicate socket events by message ID. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/messages/{{conversationId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Is this lesson slot available?"
}
```

<details>
<summary>Fictional example — 200 Message saved — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "message": {
    "id": "10000000-0000-4000-8000-000000000007",
    "conversation_id": "10000000-0000-4000-8000-000000000003",
    "sender_id": "10000000-0000-4000-8000-000000000001",
    "body": "Is this lesson slot available?",
    "created_at": "2026-10-21 10:00:00",
    "read_at": null
  }
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### POST /chat/socket/authorize

Shared workflow. Authorize Socket.IO connection identity or a conversation room. Include conversationId only for a room in which the user is a participant; omit for connection identity only. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/chat/socket/authorize
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "conversationId": "{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Conversation room authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "userId": "10000000-0000-4000-8000-000000000001",
  "room": "private-conversation.10000000-0000-4000-8000-000000000003",
  "expiresInSeconds": 900,
  "authVersion": 0
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

### GET /feed

Shared workflow. Read the shared city feed with perPage/cursor pagination. Feed visibility is shared across learner, teacher and institute accounts. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed?city={{city}}&perPage={{perPage}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 City feed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "posts": [
    {
      "id": "10000000-0000-4000-8000-000000000005",
      "author_id": "10000000-0000-4000-8000-000000000001",
      "post_type": "request",
      "city": "Delhi",
      "subject_id": 1,
      "body": "Looking for maths lessons in Delhi.",
      "image_url": null,
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma",
      "author_avatar": null,
      "like_count": 2,
      "comment_count": 1,
      "liked_by_me": 0
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /feed

Shared workflow. Create a city feed post. Learner accounts produce post_type=request; teacher/institute accounts produce post_type=offer. The server derives the post type from the bearer user, not from HTTP input. One post per account per seven days. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "city": "{{city}}",
  "subjectId": "{{subjectId}}",
  "body": "Looking for maths lessons in Delhi."
}
```

<details>
<summary>Fictional example — 200 Post created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "post": {
    "id": "10000000-0000-4000-8000-000000000005",
    "author_id": "10000000-0000-4000-8000-000000000001",
    "post_type": "request",
    "city": "Delhi",
    "subject_id": 1,
    "body": "Looking for maths lessons in Delhi.",
    "image_url": null,
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 429 You can post once every 7 days — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "error": "You can post once every 7 days",
  "nextAllowedAt": "2026-10-28T10:00:00+00:00"
}
```

</details>

### POST /feed/{{postId}}/like

Shared workflow. Like a feed post as the authenticated user; repeated likes are idempotent. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 Post liked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### DELETE /feed/{{postId}}/like

Shared workflow. Remove the authenticated user’s like from a feed post. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
DELETE {{baseUrl}}/feed/{{postId}}/like
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Like removed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /feed/{{postId}}/comments

Shared workflow. Public comment/reply list for the same post, with perPage/cursor pagination. parent_comment_id distinguishes a reply from a top-level comment. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/feed/{{postId}}/comments?perPage={{perPage}}
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Comments — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comments": [
    {
      "id": "10000000-0000-4000-8000-000000000006",
      "post_id": "10000000-0000-4000-8000-000000000005",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "parent_comment_id": null,
      "body": "Please share more details.",
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

</details>

### POST /feed/{{postId}}/comments

Shared workflow. Add a comment to a post. Omit parentCommentId for a top-level comment; include a parent comment from the same post for a reply. Replies are not a separate endpoint. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Please share more details."
}
```

<details>
<summary>Fictional example — 200 Comment created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "10000000-0000-4000-8000-000000000006",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "parent_comment_id": null,
    "body": "Please share more details.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /feed/{{postId}}/comments — Reply to a comment

Common comment reply. Use a parentCommentId belonging to the same postId; another post’s comment is rejected. Same POST endpoint as a top-level comment. The post/comment author may be learner, teacher or institute. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/feed/{{postId}}/comments
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "body": "Thank you. Please share the next available lesson time.",
  "parentCommentId": "{{parentCommentId}}"
}
```

<details>
<summary>Fictional example — 200 Reply saved under the same post — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "comment": {
    "id": "60000000-0000-4000-8000-000000000001",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "parent_comment_id": "10000000-0000-4000-8000-000000000006",
    "body": "Thank you. Please share the next available lesson time.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

<details>
<summary>Fictional example — 404 Parent belongs to another post — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Parent comment not found for this post"
}
```

</details>

### POST /safety/reports

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/safety/reports
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "targetType": "user",
  "targetId": "{{userId}}",
  "reasonCategory": "spam",
  "reason": "Repeated unwanted contact."
}
```

<details>
<summary>Fictional example — 200 Report created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "report": {
    "id": "10000000-0000-4000-8000-000000000011",
    "reporter_id": "10000000-0000-4000-8000-000000000001",
    "target_type": "user",
    "target_id": "10000000-0000-4000-8000-000000000002",
    "reason_category": "spam",
    "reason": "Repeated unwanted contact.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /safety/blocks/{{userId}}

Blocks messaging in both directions through REST and Socket.IO. Existing history remains readable; see docs/CHAT_EVENTS.md. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/safety/blocks/{{userId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{}
```

<details>
<summary>Fictional example — 200 User blocked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "userId": 2,
  "blocked": true,
  "chatStatus": {
    "otherUserId": 2,
    "blockedByMe": true,
    "canMessage": false
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### DELETE /safety/blocks/{{userId}}

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
DELETE {{baseUrl}}/safety/blocks/{{userId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 User unblocked — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true,
  "userId": 2,
  "blocked": false,
  "chatStatus": {
    "otherUserId": 2,
    "blockedByMe": false,
    "canMessage": true
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /suggestions

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/suggestions
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "suggestionType": "course",
  "name": "Robotics",
  "notes": "Please add robotics lessons."
}
```

<details>
<summary>Fictional example — 200 Suggestion submitted — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "suggestion": {
    "id": "10000000-0000-4000-8000-000000000012",
    "submitted_by": "10000000-0000-4000-8000-000000000001",
    "suggestion_type": "course",
    "name": "Robotics",
    "notes": "Please add robotics lessons.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /support

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/support
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "subject": "Booking help",
  "message": "Please help me with my booking."
}
```

<details>
<summary>Fictional example — 200 Support ticket created — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ticket": {
    "id": "10000000-0000-4000-8000-000000000010",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "subject": "Booking help",
    "message": "Please help me with my booking.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /support/mine

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/support/mine
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 My support tickets — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tickets": [
    {
      "id": "10000000-0000-4000-8000-000000000010",
      "user_id": "10000000-0000-4000-8000-000000000001",
      "subject": "Booking help",
      "message": "Please help me with my booking.",
      "status": "open",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00"
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /referrals/mine

Returns or creates caller referral code. Do not redeem your own code. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/referrals/mine
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 My referral code and referrals — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "code": "MOCK7ABC",
  "link": "https://example.com/download?ref=MOCK7ABC",
  "referredCount": 1,
  "referred": [
    {
      "id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "created_at": "2026-10-21 10:00:00"
    }
  ]
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### POST /referrals/redeem

Set referralCode to another user code. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/referrals/redeem
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "code": "{{referralCode}}"
}
```

<details>
<summary>Fictional example — 200 Referral redeemed — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "referral": {
    "id": "10000000-0000-4000-8000-000000000013",
    "referrer_id": "10000000-0000-4000-8000-000000000002",
    "referred_id": "10000000-0000-4000-8000-000000000001",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 409 This account has already been credited to a referrer — HTTP 409</summary>

Content-Type: `application/json`

```json
{
  "error": "This account has already been credited to a referrer"
}
```

</details>

### POST /analytics/page-view

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/analytics/page-view
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "pageType": "city_directory",
  "target": "{{city}}",
  "platform": "android"
}
```

<details>
<summary>Fictional example — 200 Page view recorded — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

### GET /health

 See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/health
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Healthy — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ok": true
}
```

</details>

### POST /location-lookup/reverse

Public coordinate lookup through OSM Nominatim; at least one second between requests per IP. See docs/APP_TEAM_API.md for access gates, validation, response fields and limits. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/location-lookup/reverse
Accept: application/json
Content-Type: application/json
```

Access: Public (No Auth).

Request body:

```json
{
  "latitude": 28.6139,
  "longitude": 77.209
}
```

<details>
<summary>Fictional example — 200 Reverse-geocoded location — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "location": {
    "place_id": 123456,
    "licence": "Data © OpenStreetMap contributors, ODbL 1.0. http://osm.org/copyright",
    "osm_type": "node",
    "osm_id": 123456,
    "lat": "28.6139000",
    "lon": "77.2090000",
    "class": "place",
    "type": "city",
    "place_rank": 16,
    "importance": 0.8,
    "addresstype": "city",
    "name": "New Delhi",
    "display_name": "New Delhi, Delhi, India",
    "address": {
      "city": "New Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "in"
    },
    "boundingbox": [
      "28.60",
      "28.63",
      "77.19",
      "77.22"
    ],
    "namedetails": {
      "name": "New Delhi"
    },
    "extratags": {}
  }
}
```

</details>

<details>
<summary>Fictional example — 429 Lookup too frequent — HTTP 429</summary>

Content-Type: `application/json`

```json
{
  "message": "Please wait a moment before looking up another location.",
  "retryAfterSeconds": 1
}
```

</details>

<details>
<summary>Fictional example — 404 Address not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "message": "No address details were found for these coordinates."
}
```

</details>

### GET /up — Framework health (HTML)

Public Laravel health route. Returns HTML, not the /health JSON contract. Does not itself prove database or external-service health. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/up
Accept: application/json
```

Access: Public (No Auth).

<details>
<summary>Fictional example — 200 Application up; abbreviated HTML preview — HTTP 200</summary>

Content-Type: `text/html; charset=UTF-8`

```html
<!DOCTYPE html>
<html lang="en"><body><h2>Application up</h2><p>HTTP request received.</p></body></html>
```

</details>

### POST /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/broadcasting/auth
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

Request body:

```json
{
  "socket_id": "123.456",
  "channel_name": "private-conversation.{{conversationId}}"
}
```

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### GET /broadcasting/auth — Broadcast compatibility

Authenticated legacy broadcasting compatibility route, registered for GET/POST (HEAD follows GET). For the default Socket.IO broadcaster the participant channel must be private-conversation.{conversationId}; successful response is authorized/channel. This is not the Socket.IO /chat/socket/authorize token contract. Different configured broadcast drivers may return different response bodies. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/broadcasting/auth?socket_id=123.456&channel_name=private-conversation.{{conversationId}}
Accept: application/json
Authorization: Bearer {{token}}
```

Access: Bearer; additional phone/role/participant restrictions apply as documented.

<details>
<summary>Fictional example — 200 Default Socket.IO driver; participant authorized — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "authorized": true,
  "channel": "private-conversation.50000000-0000-4000-8000-000000000001"
}
```

</details>

<details>
<summary>Fictional example — 401 Bearer missing — HTTP 401</summary>

Content-Type: `application/json`

```json
{
  "error": "Missing Authorization header"
}
```

</details>

### Admin only

Restricted JSON APIs requiring a valid admin bearer (users.is_admin=true), plus the standard API authentication middleware. Uses adminToken instead of the ordinary collection token. Developer portal or admin web sessions do not grant API access. These requests can change moderation/provider state; execute individually with deliberate IDs.

#### PATCH /profiles/{{profileType}}/{{providerId}}/verify — Verify provider listing

Admin only. type must be teacher/institute. id is the provider user ID. Updates is_verified/verified_at. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/profiles/{{profileType}}/{{providerId}}/verify
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "verified": true
}
```

<details>
<summary>Fictional example — 200 Verify provider listing — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "40000000-0000-4000-8000-000000000001",
    "user_id": "20000000-0000-4000-8000-000000000001",
    "bio": "Example teaching profile.",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\",\"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": null,
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "example-teacher",
    "is_verified": 1,
    "verified_at": "2026-10-22 10:00:00",
    "trial_days": 0,
    "rating_avg": "0.00",
    "rating_count": 0,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "primary_subject_id": 1
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Profile not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Profile not found"
}
```

</details>

#### PATCH /profiles/institute/{{providerId}}/type — Classify institute

Admin only. institute_type is independent, franchise, mcp_partner or bsl_direct. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/profiles/institute/{{providerId}}/type
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "institute_type": "bsl_direct"
}
```

<details>
<summary>Fictional example — 200 Classify institute — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "40000000-0000-4000-8000-000000000001",
    "user_id": "20000000-0000-4000-8000-000000000001",
    "description": "Example teaching profile.",
    "starting_rate": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\",\"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": null,
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "website_url": null,
    "slug": "example-institute",
    "is_verified": 1,
    "verified_at": "2026-10-22 10:00:00",
    "trial_days": 0,
    "rating_avg": "0.00",
    "rating_count": 0,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "instructors_text": null,
    "contact_person": "Example Contact",
    "institute_type": "bsl_direct",
    "is_bsl_official": 0,
    "bsl_official_since": null
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Profile not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Profile not found"
}
```

</details>

#### PATCH /profiles/institute/{{providerId}}/bsl-verified — BSL official verification

Admin only. verified=true is allowed only for bsl_direct institutes. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/profiles/institute/{{providerId}}/bsl-verified
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "verified": true
}
```

<details>
<summary>Fictional example — 200 BSL official verification — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "profile": {
    "id": "40000000-0000-4000-8000-000000000001",
    "user_id": "20000000-0000-4000-8000-000000000001",
    "description": "Example teaching profile.",
    "starting_rate": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\",\"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": null,
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "website_url": null,
    "slug": "example-institute",
    "is_verified": 1,
    "verified_at": "2026-10-22 10:00:00",
    "trial_days": 0,
    "rating_avg": "0.00",
    "rating_count": 0,
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00",
    "instructors_text": null,
    "contact_person": "Example Contact",
    "institute_type": "bsl_direct",
    "is_bsl_official": 1,
    "bsl_official_since": "2026-10-22 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 400 BSL Verified is reserved for directly-operated BSL centres. Franchise and MCP partners are not eligible. — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "BSL Verified is reserved for directly-operated BSL centres. Franchise and MCP partners are not eligible."
}
```

</details>

#### GET /safety/reports — List reports

Admin only. status filter is open (default), reviewed or dismissed. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/safety/reports?status=open
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 List reports — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "reports": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### PATCH /safety/reports/{{reportId}}/status — Update report

Admin only. status is open, reviewed or dismissed. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/safety/reports/{{reportId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "status": "reviewed"
}
```

<details>
<summary>Fictional example — 200 Update report — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "report": {
    "id": "50000000-0000-4000-8000-000000000001",
    "reporter_id": "10000000-0000-4000-8000-000000000001",
    "target_type": "user",
    "target_id": "20000000-0000-4000-8000-000000000001",
    "reason_category": "other",
    "reason": "Fictional example report.",
    "status": "reviewed",
    "created_at": "2026-10-22 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Report not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Report not found"
}
```

</details>

#### GET /suggestions — List suggestions

Admin only. status filter is open (default), added or declined. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/suggestions?status=open
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 List suggestions — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "suggestions": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### PATCH /suggestions/{{suggestionId}}/status — Update suggestion

Admin only. status is open, added or declined. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/suggestions/{{suggestionId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "status": "added"
}
```

<details>
<summary>Fictional example — 200 Update suggestion — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "suggestion": {
    "id": "50000000-0000-4000-8000-000000000001",
    "submitted_by": "10000000-0000-4000-8000-000000000001",
    "status": "added",
    "created_at": "2026-10-22 10:00:00",
    "suggestion_type": "course",
    "name": "Robotics",
    "notes": "Fictional example suggestion."
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Suggestion not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Suggestion not found"
}
```

</details>

#### GET /support — List support tickets

Admin only. status filter is open (default), in_progress or resolved. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/support?status=open
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 List support tickets — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "tickets": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### PATCH /support/{{ticketId}}/status — Update support ticket

Admin only. status is open, in_progress or resolved. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/support/{{ticketId}}/status
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "status": "resolved"
}
```

<details>
<summary>Fictional example — 200 Update support ticket — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "ticket": {
    "id": "50000000-0000-4000-8000-000000000001",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "subject": "Fictional support request",
    "message": "Fictional example ticket.",
    "status": "resolved",
    "created_at": "2026-10-22 10:00:00",
    "updated_at": "2026-10-22 10:00:00"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Ticket not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Ticket not found"
}
```

</details>

#### GET /referrals/top — Referral leaderboard

Admin only. Returns the top 50 referrers by referral count. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/referrals/top
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Referral leaderboard — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "topReferrers": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### GET /analytics/summary — Analytics summary

Admin only. Positive numeric days defaults to 30. Counts page views and download clicks; not payments/revenue. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/analytics/summary?days=30
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Analytics summary — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "sinceDays": 30,
  "topPages": [],
  "topCities": [],
  "downloadsByPlatform": [],
  "totalsByPageType": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### GET /moderation/photos/pending — Pending photos

Admin only. Returns pending user-avatar submissions. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/photos/pending
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Pending photos — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "pending": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### PATCH /moderation/photos/{{userId}} — Review photo

Admin only. status must be approved or rejected. Only pending photos can be reviewed. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
PATCH {{baseUrl}}/moderation/photos/{{userId}}
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "status": "approved"
}
```

<details>
<summary>Fictional example — 200 Review photo — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "30000000-0000-4000-8000-000000000001",
    "full_name": "Example User",
    "avatar_status": "approved"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 No pending photo found for that user (already reviewed, or no photo on file) — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "No pending photo found for that user (already reviewed, or no photo on file)"
}
```

</details>

#### GET /moderation/conversations — Conversation summaries

Admin only. Lists participants, message count and last-message timestamp. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/conversations
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Conversation summaries — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversations": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### GET /moderation/conversations/{{conversationId}}/messages — Read conversation for moderation

Admin only. Records this admin conversation-read access. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/conversations/{{conversationId}}/messages
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Read conversation for moderation — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "conversation": {
    "id": "50000000-0000-4000-8000-000000000001",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "20000000-0000-4000-8000-000000000001",
    "learner_name": "Example Student",
    "provider_name": "Example Teacher"
  },
  "messages": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 Conversation not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "Conversation not found"
}
```

</details>

#### GET /moderation/bookings — Booking audit list

Admin only. Records this admin booking-read access. Booking price is not payment/revenue. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/bookings
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Booking audit list — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "bookings": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### GET /moderation/contact-stats — Contact statistics

Admin only. Counts conversations; mostContactedTeachers may contain both teacher and institute providers. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/contact-stats
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Contact statistics — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "mostContactedTeachers": [],
  "mostActiveStudents": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

#### POST /moderation/users/{{userId}}/suspend — Suspend user

Admin only. reason is optional, max 5000 characters. Cannot suspend yourself. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/moderation/users/{{userId}}/suspend
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

Request body:

```json
{
  "reason": "Fictional example moderation reason."
}
```

<details>
<summary>Fictional example — 200 Suspend user — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "30000000-0000-4000-8000-000000000001",
    "full_name": "Example User",
    "suspended_at": "2026-10-22 10:00:00",
    "suspended_reason": "Fictional example moderation reason."
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 400 You cannot suspend your own account. — HTTP 400</summary>

Content-Type: `application/json`

```json
{
  "error": "You cannot suspend your own account."
}
```

</details>

#### POST /moderation/users/{{userId}}/unsuspend — Restore user access

Admin only. Removes stored suspension state. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
POST {{baseUrl}}/moderation/users/{{userId}}/unsuspend
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Restore user access — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "user": {
    "id": "30000000-0000-4000-8000-000000000001",
    "full_name": "Example User"
  }
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>

<details>
<summary>Fictional example — 404 User not found — HTTP 404</summary>

Content-Type: `application/json`

```json
{
  "error": "User not found"
}
```

</details>

#### GET /moderation/users/suspended — Suspended users

Admin only. Returns suspended users with the admin name where available. Saved examples are fictional contract illustrations, not live captures.

Request:

```http
GET {{baseUrl}}/moderation/users/suspended
Accept: application/json
Authorization: Bearer {{adminToken}}
```

Access: Admin API bearer; users.is_admin must be true.

<details>
<summary>Fictional example — 200 Suspended users — HTTP 200</summary>

Content-Type: `application/json`

```json
{
  "suspended": []
}
```

</details>

<details>
<summary>Fictional example — 403 Non-admin bearer rejected — HTTP 403</summary>

Content-Type: `application/json`

```json
{
  "error": "Admin access required"
}
```

</details>


## App presence lifecycle examples

See [presence](PRESENCE.md). These examples assume one device; another active device can keep `isActive:true` after background.

### POST /presence/update · foreground

```http
POST {{baseUrl}}/presence/update
Authorization: Bearer {{token}}
Content-Type: application/json
```

```json
{
  "deviceId": "app-device-unique-id",
  "event": "foreground"
}
```

Example response:

```json
{
  "isActive": true
}
```

### POST /presence/update · activity

```http
POST {{baseUrl}}/presence/update
Authorization: Bearer {{token}}
Content-Type: application/json
```

```json
{
  "deviceId": "app-device-unique-id",
  "event": "activity"
}
```

Example response:

```json
{
  "isActive": true
}
```

### POST /presence/update · heartbeat

```http
POST {{baseUrl}}/presence/update
Authorization: Bearer {{token}}
Content-Type: application/json
```

```json
{
  "deviceId": "app-device-unique-id",
  "event": "heartbeat"
}
```

Example response:

```json
{
  "isActive": true
}
```

### POST /presence/update · background

```http
POST {{baseUrl}}/presence/update
Authorization: Bearer {{token}}
Content-Type: application/json
```

```json
{
  "deviceId": "app-device-unique-id",
  "event": "background"
}
```

Example response:

```json
{
  "isActive": false
}
```
