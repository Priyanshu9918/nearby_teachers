# Registration API: run and integration guide

The Student, Teacher and Institute selectors belong to one registration page.
Keep all form values in frontend state until Register is pressed. Send and
verify OTP on that same page. Registration saves the full form once; autosave
is available only after registration succeeds.

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
OTP_TEST_MODE=true
MSG91_AUTH_KEY=your_msg91_auth_key
MSG91_TEMPLATE_ID=your_approved_msg91_template_id
```

This workspace already has APP_KEY and a generated JWT_SECRET. Keep JWT_SECRET
private and stable. On a fresh deployment, generate a random value using
`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and put it in `.env`.
Indian numbers use MSG91; the configured template must accept the `code`
variable. Other countries use `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and
`TWILIO_FROM_NUMBER`. For current local testing, `OTP_TEST_MODE=true` is
enabled in this workspace: a random six-digit OTP is returned in the response
without calling an SMS provider. No SMS credentials are needed for this mode.
It only works with `APP_ENV=local` or `testing`. Production always uses SMS
and never returns an OTP, even if the test flag is enabled. Later set
`OTP_TEST_MODE=false`, configure the SMS credentials, and clear config cache
to use real SMS delivery.

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

In local/testing with `OTP_TEST_MODE=true`, the response also contains:

```json
{"ok":true,"expiresInMinutes":10,"testMode":true,"otp":"483921"}
```

The OTP is randomly generated for each request; use the actual response
value as `code` in Verify OTP. Expiry, attempt limits, hashing, and one-time
verification still apply.

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
For Google sign-in, use its token and `PATCH /auth/link-phone` to verify OTP,
as described in [API.md](API.md); do not switch to `/auth/otp/verify`.

## 3. Register: submit the full form

Use the verified bearer token. Do not send a `phone` or `mobileVerified` field;
the server uses the phone verified for that token. Selecting Student, Teacher
or Institute before this request only changes frontend state.

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
  "primarySubjectId": 1,
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

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

Successful registration returns HTTP 200 with `registrationCompleted: true`,
`profileCompleted: true`, `autosaveEnabled: true`, `draft`, `user` and `nextStep`.
Students receive `nextStep: "age_verification"`; teachers and institutes receive
`nextStep: "home"`. Passwords/hashes are never returned; `draft.passwordSet`
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
rejected. The parental-consent flow is documented in [API.md](API.md).

## 5. Autosave after registration

Send only changed fields. Debounce about 500ms, serialize requests, and wait
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

For a later password login, use public `POST /auth/login` with
`{"email":"anita@example.com","password":"a-secure-password"}`. Mobile-number
password login is rejected. Registered users cannot sign in with mobile OTP;
that OTP flow is for registration verification. Retain the email login token.
See [EMAIL_AUTH.md](EMAIL_AUTH.md) for password recovery and email verification.

## Errors and checks

| Status | Meaning |
|---|---|
| 401 | Missing or invalid bearer token |
| 403 | Phone not verified, or suspended account |
| 409 | Autosave requested before registration, or registration already complete |
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
the mobile input and enable Send OTP. Both OTP endpoints are public; neither
needs a bearer token. After Verify OTP succeeds on this same page, retain
its response `token` and include it automatically on Register. An invalid
token returns HTTP 401 with the same `OTP_VERIFICATION_REQUIRED` code. For
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
