# Firebase Google auth: backend and app-team handoff

This repository handles backend authentication and onboarding state. Firebase
Console configuration, native SDK integration and app screens belong to the
app/Firebase team. No mobile UI code is changed here.

## Two signup paths, one onboarding contract

```text
Google button → Firebase Google sign-in → obtain Firebase user's ID token
  → POST /auth/google → store returned Laravel token → follow nextScreen

Normal signup button → open common onboarding form directly
  → inline phone OTP request/verification → store Laravel token
  → POST /auth/register → follow nextScreen
```

New/incomplete Google users enter the common form, prefilled using
`GET /auth/onboarding`. Google email verification does not verify their phone;
they must complete the existing inline OTP step before submitting registration.
Returning registered users resume from saved phone/profile/age/consent state.

Normal signup remains Laravel email/password plus SMS OTP. It does not require
a Firebase password account or Firebase's Email/Password provider. The common
form allows Google-linked users to omit email and password during account
creation. The verified Google email is preserved when omitted; no app password
is required. All other registration and phone verification requirements apply.

## Firebase and app-team responsibilities

| Owner | Deliverable |
|---|---|
| Firebase project owner | Confirm project ID for each environment; enable Google under Authentication sign-in providers; complete Console support-email settings |
| Android team | Register exact package name; configure the signing fingerprints for debug and production/Play signing; download updated `google-services.json` |
| iOS team | Register exact bundle ID; use updated `GoogleService-Info.plist` and reversed-client-ID URL scheme |
| App team | Authenticate with Firebase Google SDK, obtain the Firebase user's ID token and exchange it with the backend |
| Server/backend owner | Configure project ID, Laravel JWT secret, MySQL and SMS provider; run backend checks |

References: [Android Google sign-in](https://firebase.google.com/docs/auth/android/google-signin),
[Apple Google sign-in](https://firebase.google.com/docs/auth/ios/google-signin),
[Flutter federated authentication](https://firebase.google.com/docs/auth/flutter/federated-auth).
Use the same Firebase project in the app and backend for each environment.

The backend does not need native config files, an OAuth client secret or a
service-account private key to verify these ID tokens. Mobile config files
belong in the corresponding app builds. Do not send backend secrets to clients.

## Backend server configuration

Set in the server's untracked `.env`:

```dotenv
FIREBASE_PROJECT_ID=your-actual-firebase-project-id
JWT_SECRET=your-existing-stable-random-secret-at-least-32-bytes
```

Use the project ID, not project display name/number, app ID, Web client ID or
API key. Keep an existing JWT secret stable; changing it invalidates app
sessions. For a fresh installation only, generate one using
`openssl rand -hex 32` and store it securely.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan firebase:check --offline
php artisan firebase:check
php artisan optimize
php artisan queue:restart
```

The reporting migrations must exist for transactional identity-link and
registration events. Offline checks validate local project/JWT configuration;
online checks also obtain Google's public certificates or use their valid
cache. They do not prove the provider is enabled or native sign-in works.
Acceptance requires a real token from the app's Firebase SDK.

### Existing nearby_teacher.json file

Place the backend Firebase service-account file at:

```text
storage/app/private/firebase/nearby_teacher.json
```

The directory and Git ignore rules are prepared. Copy the real file separately on
each server; it is intentionally not deployed through Git. Keep it outside `public`
and readable by the PHP process only and trusted server administrators.

```dotenv
FIREBASE_SERVICE_ACCOUNT_PATH=storage/app/private/firebase/nearby_teacher.json
```

Relative paths resolve from the Laravel project root; absolute server paths also
work. Do not paste the private JSON into app-team configuration. This should be a
Firebase backend service-account JSON with a top-level `project_id`, not Android's
`google-services.json` or the iOS plist.

If `FIREBASE_PROJECT_ID` is filled, it takes precedence. Otherwise the backend reads
`project_id` from this file. The legacy inline `FIREBASE_SERVICE_ACCOUNT_JSON` is
used only when no file path is configured. Missing/unreadable or malformed configured
files fail closed when needed. ID-token verification still uses Google's public
certificates; storing this file does not add Firebase Admin operations or revocation
checks. No private key is needed solely for the existing Google sign-in verifier.

After copying the actual file, run:

```sh
php artisan config:clear
php artisan firebase:check --offline
php artisan firebase:check
```

The project setup is incomplete until the real file/project ID and app-side Firebase
configuration are present. A successful check still requires an app-issued ID token
through `POST /auth/google` for end-to-end acceptance.

## Token exchange

All URLs are relative to the Laravel base URL, with no `/api` prefix. Use HTTPS
in production.

```http
POST /auth/google
Accept: application/json
Content-Type: application/json

{"firebaseIdToken":"<Firebase user's ID token>"}
```

Send the Firebase ID token obtained after Firebase sign-in. A Google OAuth
access token, raw Google ID token, custom token, email or UID is not a substitute.
The server checks the signature, project/time claims, Google provider and
verified email. See [Firebase ID-token verification](https://firebase.google.com/docs/auth/admin/verify-id-tokens).

Abbreviated response for a new user:

```json
{
  "token": "<Laravel bearer>",
  "authMethod": "google",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "onboardingRequired": true,
  "ageVerificationRequired": true,
  "parentalConsentPending": false,
  "nextScreen": "onboarding",
  "nextStep": "mobile_verification",
  "nextStepEndpoint": "/auth/link-phone",
  "onboardingEndpoint": "/auth/onboarding",
  "registerEndpoint": "/auth/register",
  "user": {"id":"<local UUID>","full_name":"Google Person","email":"user@example.com"}
}
```

`nextScreen` selects the screen; `nextStep` describes the immediate action,
including inline OTP. Flags reflect saved state, not an unsaved local form.
The initial learner role is provisional; the form selects student, teacher or
institute. The age flag describes a later learner requirement; do not bypass
the earlier phone/profile steps because it is true.

| State | nextScreen | nextStep |
|---|---|---|
| Incomplete profile, phone unverified | `onboarding` | `mobile_verification` |
| Incomplete profile, phone verified | `onboarding` | `complete_profile` |
| Completed profile, phone unverified | `mobile_verification` | `mobile_verification` |
| Registered student, DOB missing | `age_verification` | `age_verification` |
| Registered minor, guardian confirmation pending | `guardian_consent` | `guardian_consent` |
| Required steps complete | `home` | `home` |

The same routing fields are returned by email login, OTP verification, phone
linking, session, onboarding reads and registration completion. Resume via
`GET /auth/session` using the stored Laravel token. After DOB submission or
external guardian confirmation, reload session state. The guardian step's
`nextStepEndpoint:/auth/session` is for polling/resuming; guardian consent
tokens are not exposed in the user API.

For Google phone verification:

1. `POST /auth/otp/request` with `{authMethod:"google",phone}` and the sign-in bearer.
2. `POST /auth/otp/verify` with `{authMethod:"google",phone,code}` and the sign-in bearer.
3. Save the fresh verification `token`; use it for `/auth/register` and later APIs.

The new token identifies the same Google user; email/password are optional.
Missing Google bearer returns `401` (`GOOGLE_AUTH_REQUIRED`) before sending OTP.
A Google OTP is bound to its user and cannot be verified as normal signup or
by another Google account. `PATCH /auth/link-phone` remains an authenticated
alternative and returns a fresh token as well. A phone owned by another account
returns `409` without consuming the OTP; accounts are not silently merged.
Normal signup uses `authMethod:"phone_otp"` (or omits it), sends no Authorization
header to OTP endpoints, then saves the verification token. It requires
email/password at registration. Deploy migration `000031` before this code.
See [Registration](REGISTRATION.md) for the full required form and OTP limits.
For this account’s SMS gateway, see [Muzztech OTP setup](MUZZTECH_OTP.md).

Use the Laravel token for APIs and Socket.IO. Strict verified-Google Firebase
bearer compatibility remains for legacy clients; new clients should always
exchange through `/auth/google`.

## Identity, errors and reporting

- A returning Firebase UID reuses its local record. A verified Google email
  matching an unlinked local account links that account, preserving password,
  role and registration. Identity conflicts return `409`.
- Suspended accounts receive `403`, no new token and no identity mutation.
  Invalid, forged, expired, wrong-project, non-Google or unverified-email tokens
  fail with `400`; invalid request fields return `422`.
- Verification configuration/certificate outages return `503` with
  `code:FIREBASE_AUTH_UNAVAILABLE`. Laravel session expiry returns `401`;
  reauthenticate and exchange a fresh Firebase ID token.
- Password resets reject Firebase sessions authenticated before the reset.
  Use a fresh Firebase sign-in, not just an ID-token refresh of the old session.
- Linking emits `identity.google_linked` transactionally, once per new link.
  Authentication outcomes use existing login activity; completing registration
  emits its existing event. Snapshots exclude tokens, passwords and private
  keys. User responses omit guardian-consent tokens and password hashes.
- This custom verifier checks signatures/claims, not Firebase Admin user-disabled,
  token-revocation or MFA state. Local suspension/auth-version rules still
  apply. Firebase-side revocation checks need a separate Admin integration.

## Joint acceptance

App/Firebase team supplies project ID, framework, platform identifiers and a
working build. Finish with a real Firebase ID token through Postman or the app.
Do not paste live tokens into shared chat or commit them to collections.

| Test | Expected |
|---|---|
| Fresh Google account | One local user; common onboarding; name/email prefilled; phone pending |
| Resume incomplete onboarding | Same local identity; no duplicate registration |
| Complete OTP and form | Saved selected role, then learner age step or provider home |
| Returning completed adult/provider | Same local user, `nextScreen:home` |
| Normal signup | Common form and normal phone OTP, no Firebase dependency |
| Existing email login account | Same account linked; existing password preserved |
| Wrong-project/forged token | No user creation or session token |
| Suspended/conflicting identity | `403`/`409`, no token or silent merge |
| Certificate outage | Retryable `503` |

Automated tests use generated RSA keys and fake certificate responses, not a
live project. Use a disposable MySQL database ending in `_test`:

```sh
FIREBASE_TEST_DATABASE=nearby_firebase_test php artisan test --filter=FirebaseGoogleAuthTest
php artisan test --filter=FirebaseVerificationTest
```
