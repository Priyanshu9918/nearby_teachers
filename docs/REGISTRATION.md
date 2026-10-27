# Registration API: run and integration guide

Teacher address fields and required agreement labels are documented in
[TEACHER_REGISTRATION_ADDRESS.md](TEACHER_REGISTRATION_ADDRESS.md).


This detailed signup guide supplements the five-part
[API reference](API.md) and [app integration guide](APP_TEAM_API.md): Auth,
Learner, Teacher, Institute and Common. The deployed base URL is
`http://157.245.125.79/nearby_teachers/public`, with no `/api` prefix.

The Student, Teacher and Institute selectors belong to one registration page.
Keep all form values in frontend state until Register is pressed. Send and
verify OTP on that same page. Registration saves the full form once; autosave
is available only after registration succeeds.

## Google signup and normal signup

Normal signup opens the common form directly and uses the existing inline
phone OTP verification before registration. Google signup first exchanges a
Firebase ID token at `POST /auth/google`, then opens the same form when the
response says `nextScreen:"onboarding"`. Link the phone using the Google
response's Laravel bearer. Returning users follow saved routing state.
See [Firebase backend and app-team handoff](FIREBASE_GOOGLE_AUTH.md).

## Start Laravel

Requirements: PHP 8.2+, Composer, `pdo_mysql`, MySQL 8.

```bash
cd /var/www/html/backend
composer install
```

For a fresh checkout only, copy `.env.example` to `.env` and run
`php artisan key:generate`. Keep the existing `.env` for this workspace.
Set your MySQL database credentials and SMS provider values:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password
JWT_SECRET=your_random_secret_at_least_32_bytes_long
OTP_TEST_MODE=false
MSG91_AUTH_KEY=your_msg91_auth_key
MSG91_TEMPLATE_ID=your_approved_msg91_template_id
```

This workspace already has APP_KEY and a generated JWT_SECRET. Keep JWT_SECRET
private and stable. On a fresh deployment, generate a random value using
`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and put it in `.env`.
SMS uses the selected provider (`OTP_SMS_PROVIDER`); WhatsApp uses Meta Cloud API.
This workspace selects Muzztech SMS and both delivery channels, with simulation disabled.
Complete provider credentials and approved templates before testing real delivery.
See [dual-channel setup](OTP_DELIVERY.md). Local/testing `OTP_TEST_MODE=true` returns the code without sending messages.

```bash
php artisan config:clear
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

Background email/broadcast workers must also run after registration when
using the app's booking, comment, consent and chat features; see
[PERFORMANCE.md](PERFORMANCE.md). These workers use `QUEUE_CONNECTION=database`.

Local base URL: `http://127.0.0.1:8000`. Routes have **no `/api` prefix**.
For a phone on the same Wi-Fi, use `http://YOUR_COMPUTER_LAN_IP:8000`.
Android emulator uses `http://10.0.2.2:8000`. Use HTTPS on your deployed host.

## Muzztech SMS provider

The backend can use `OTP_SMS_PROVIDER=muzztech` with the account's key, sender
and actual approved template ID. See [Muzztech OTP configuration](MUZZTECH_OTP.md)
for the message placeholder, phone/response format and acceptance checks.
The mobile API contract and Google phone-linking steps remain the same.

## Run in Postman

Import [registration.postman_collection.json](postman/registration.postman_collection.json).
Set collection variables `baseUrl` and `phone`. Run **Send OTP**; in test mode
the collection automatically saves the returned `otp`. For real SMS, enter
the received six-digit code in `otp`. Run **Verify OTP**. Its test script stores
the returned `token` automatically. Submit **one** of Student, Teacher or
Institute registration requests. For Teacher, select a real `subjectId` from
the subjects request. Run autosave requests only after registration succeeds.

All JSON requests use `Accept: application/json` and
`Content-Type: application/json`. Protected requests additionally use
`Authorization: Bearer <token>`.

## 1. Send OTP

The button under the mobile input calls this public endpoint. No form fields
other than the phone number are saved.

```bash
REG_API_BASE=http://127.0.0.1:8000
curl -X POST "$REG_API_BASE/auth/otp/request" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"phone":"+919876543210"}'
```

Success: `{"ok":true,"expiresInMinutes":10}`. Resend uses the same endpoint.
Maximum three OTP requests per phone in ten minutes.

Live responses include channel statuses without the OTP. Enter the received SMS or
WhatsApp code in Verify OTP. Local/testing simulation returns `testMode:true` and
`simulated` channel statuses and `otp` for verification testing, without delivery. Expiry, attempt
limits, hashing and one-time verification still apply.

## 2. Verify OTP on the same page

Replace the example code with the code delivered to the entered phone.

```bash
curl -X POST "$REG_API_BASE/auth/otp/verify" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"phone":"+919876543210","code":"123456"}'
```

The response contains `token`, `user`, and `mobileVerified: true`. Store the
token securely; show the returned `user.phone` as verified. If the phone input
changes, reset its verified state and verify the new number. For a new number,
OTP verification creates only a minimal phone identity; the registration
fields remain on the frontend and autosave is disabled.

```bash
REG_API_TOKEN='PASTE_TOKEN_FROM_VERIFY_RESPONSE'
curl "$REG_API_BASE/auth/onboarding" \
  -H 'Accept: application/json' -H "Authorization: Bearer $REG_API_TOKEN"
```

For an already registered phone, OTP verification returns `409` with
`loginRequired: true`. Use email/password login instead; no new OTP bearer
is issued. If an existing saved session shows `registrationCompleted: true`,
continue to the application rather than registering again.
For Google sign-in, send `authMethod:"google"` with the sign-in bearer to both
`POST /auth/otp/request` and `POST /auth/otp/verify`. Save the new verification
token for registration; it still identifies the same Google account and does
not require email/password. Google OTP verification without its bearer is
rejected. `PATCH /auth/link-phone` also returns a new Google token.
Normal signup sends no bearer and uses `authMethod:"phone_otp"` (or omits it).
See [API.md](API.md) for request examples. Deploy migration `000031` first.

## 3. Register: submit the full form

Use the verified bearer token. Do not send a `phone` or `mobileVerified` field;
the server uses the phone verified for that token. Selecting Student, Teacher
or Institute before this request only changes frontend state.

For accounts linked to Google, `email` and `password` are optional when creating
the account (`POST /auth/register` or `/auth/onboarding/complete`). Omit them to
keep the verified Google email and continue without setting a password. Supplied
values still undergo validation. Mobile verification and the other required
registration fields still apply. Regular signup requires email and password.

Student:

```bash
curl -X POST "$REG_API_BASE/auth/register" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $REG_API_TOKEN" \
  -d '{
    "accountType":"student",
    "fullName":"Anita Sharma",
    "email":"anita@example.com",
    "password":"a-secure-password",
    "termsAccepted":true,
    "acknowledgeTeachersNotBackgroundChecked":true
  }'
```

Teacher uses the same endpoint with this full body. Get a valid subject ID
using public `GET /subjects` first:

```json
{
  "accountType": "teacher",
  "fullName": "Anita Sharma",
  "email": "anita@example.com",
  "password": "a-secure-password",
  "city": "Jaipur",
  "primarySubjects": [1, 2, 3, 4, 5],
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

`primarySubjects` accepts 1–5 distinct existing IDs. All selected subjects are equal; none is designated as the primary subject. The response includes `draft.primarySubjects`. Registration/onboarding reject the retired `primarySubjectId` and `subjectIds` inputs. Invalid/empty/duplicate selections or more than five IDs return `422`. Teacher `PUT /profiles/me` also uses `primarySubjects`; institutes retain `subjectIds`.

Institute uses the same endpoint with this full body:

```json
{
  "accountType": "institute",
  "fullName": "Bright Academy",
  "contactPerson": "Anita Sharma",
  "city": "Delhi",
  "email": "academy@example.com",
  "password": "a-secure-password",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

Successful form submission returns HTTP 200 with `registrationSubmitted:true`,
`draft`, `user` and routing flags. DOB is not validated as a registration-form
field: the student has a separate DOB page and API.

Students without DOB receive `registrationCompleted:false`,
`profileCompleted:false`, `autosaveEnabled:false`, `missingFields:[]` and
`nextScreen:"age_verification"`. Retrying the already saved form while DOB is
pending returns `200` and resumes DOB without resaving fields. After a valid
DOB submission the student becomes registration complete. Teachers/institutes
complete immediately on form submission and receive `nextStep:"home"`,
`registrationCompleted:true`, `profileCompleted:true`, `autosaveEnabled:true`;
they do not require DOB. Repeating completed registration returns `409`.

Passwords/hashes are never returned; `draft.passwordSet`
indicates a saved password. `POST /auth/onboarding/complete` remains an alias
with the same full-form requirement. `{}` cannot register a new user.

## 4. Student age verification

```bash
curl -X POST "$REG_API_BASE/auth/date-of-birth" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $REG_API_TOKEN" \
  -d '{"dateOfBirth":"2000-01-01"}'
```

For ages 13–17 also send `guardianName` and `guardianContact`; under 13 is
rejected. Use guardian email for deliverable consent: guardian phone is
accepted, but SMS consent delivery is not implemented. DOB completes a submitted
student registration and fills `profile_completed_at`; it does not fill
`registration_submitted_at` or submit an unsubmitted form. Minor registration
can be complete while guardian consent remains pending. Route by `nextScreen`.
The parental-consent flow is documented under [Learner](API.md#learner).

## 5. Autosave after registration

Autosave requires completed registration, including student DOB. Form submission
alone does not enable it while DOB is pending. No new autosave or draft-save API
has been introduced. Send only changed fields. Debounce about 500ms, serialize requests, and wait
for pending saves before navigation. Registration does not need autosave.

```bash
curl -X PATCH "$REG_API_BASE/auth/onboarding" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $REG_API_TOKEN" \
  -d '{"fullName":"Anita Sharma Updated"}'
```

Restore the saved form using `GET /auth/onboarding`. Teacher/institute listing
tabs can send partial `PUT /profiles/me` updates, for example
`{"city":"Jaipur","lessonModes":["online"],"trialDays":1}`. Listing fields,
subject assignments, locations and rates are documented in [API.md](API.md).

If registration is incomplete, password login returns `409` with
`code:"REGISTRATION_INCOMPLETE"` and no token, even for a correct saved password.
Resume through phone OTP verification (normal signup) or Google authentication,
then call `GET /auth/onboarding` with that bearer. Use `draft` to autofill the
already saved registration details. Passwords are never returned. A saved
password alone does not block OTP resume for an incomplete account. No new
server autosave is enabled: unsaved form changes remain on the client, and
`PATCH /auth/onboarding` still requires completed registration.

For a later password login, use public `POST /auth/login` with
`{"email":"anita@example.com","password":"a-secure-password"}`. Mobile-number
password login is rejected. Registered users cannot sign in with mobile OTP;
that OTP flow is for registration verification. Retain the email login token.
See [EMAIL_AUTH.md](EMAIL_AUTH.md) for password recovery and email verification.

## Errors and checks

| Status | Meaning |
|---|---|
| 401 | Missing bearer token |
| 403 | Invalid/expired bearer token, phone not verified, or suspended account |
| 409 | Incomplete-profile password login, autosave before registration, or registration already complete |
| 422 | Invalid/missing fields, duplicate email, incorrect/expired/consumed OTP |
| 429 | OTP or endpoint request limit exceeded |
| 503 | SMS delivery unavailable; check configured provider credentials |

If Register is pressed before OTP verification, the response is HTTP 403:

```json
{
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/otp/verify"
}
```

The response also includes `error` with the same text. Show `message` below
the mobile input and enable Send OTP. For normal signup, both OTP endpoints
use `authMethod:"phone_otp"` (or omit it) without a bearer. For Google signup,
both endpoints require `authMethod:"google"` and its Google bearer. After Verify OTP succeeds on this same page, retain
its response `token` and include it automatically on Register. An invalid
token returns HTTP 403 with the same `OTP_VERIFICATION_REQUIRED` code. For
an authenticated account whose phone is unverified (such as Google sign-in),
`verifyPhoneEndpoint` is `/auth/link-phone` to preserve that account.

Display `422` response `errors` next to the matching inputs. A failed
registration does not save form fields. `canSubmit` checks server-stored values;
enable the Register button using local form validation plus `mobileVerified`,
because the server has no saved registration form yet.

```bash
php artisan route:list --path=auth
php artisan test
```

Database-backed tests opt in using a disposable MySQL database ending in
`_test`; they mock SMS delivery, use real JWT authentication for the full OTP
flow, and roll back test records:

```bash
ONBOARDING_TEST_DATABASE=nearby_onboarding_test php artisan test
```

Teacher registration and profile edits accept optional paired `latitude` / `longitude`, with private Google reverse-geocoding enrichment when enabled. See [TEACHER_LOCATION_GEOCODING.md](TEACHER_LOCATION_GEOCODING.md) for setup, responses, migration and village/thana limitations.
