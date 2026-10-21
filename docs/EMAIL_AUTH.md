# Email login and password recovery

Password login uses email only. Mobile OTP is for initial phone verification
during registration; an already registered account receives `409` with
`loginRequired: true` when attempting to sign in through mobile OTP.
Google sign-in remains available.

## SMTP and workers

This workspace's `.env` has the supplied Gmail SMTP settings, with STARTTLS
on port 587. The password is kept only in `.env`. On another deployment:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-sender@gmail.com
MAIL_PASSWORD="your-gmail-app-password"
MAIL_FROM_ADDRESS=your-sender@gmail.com
MAIL_SCHEME=smtp
MAIL_ENCRYPTION=tls
MAIL_REQUIRE_TLS=true
MAIL_TIMEOUT=20
QUEUE_CONNECTION=database
```

```bash
php artisan migrate
php artisan config:clear
php artisan queue:restart
```

Keep the workers running in separate processes:

```bash
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30
php artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30
```

Email codes use the notifications worker; existing Socket.IO sessions are
revoked through the broadcasts worker. Code email jobs are encrypted in the
queue and skipped if their code expired, was used/superseded, or the account's
email changed. Mail transport failures retry; the API response confirms the
request, not delivery. The SMTP log mailer cannot deliver these codes.

## APIs

Base URL example: `http://127.0.0.1:8000`, without `/api` prefix. Always send
`Accept: application/json` and `Content-Type: application/json`.
These three endpoints do not need Authorization:

**POST `/auth/login`**

```json
{"email":"anita@example.com","password":"a-secure-password"}
```

Success includes `token`, `user`, `mobileVerified`, and
`phoneVerificationRequired`. Store the bearer for protected API/socket use.
Phone-number login bodies return `422`. Wrong credentials return `401`;
suspended accounts return `403`. Email addresses are normalized to lowercase.

**POST `/auth/forgot-password`**

```json
{"email":"anita@example.com"}
```

Both known and unknown/suspended addresses receive the same HTTP 200 body:

```json
{
  "ok": true,
  "message": "If an eligible account exists, a password reset code will be sent to its email.",
  "expiresInMinutes": 10
}
```

For an eligible account a random six-digit OTP is emailed. The code is never
returned in JSON, including when `OTP_TEST_MODE=true`. That flag affects only
registration mobile OTP. Resend calls the same endpoint and invalidates the
previous code. Maximum three requests per normalized email per ten minutes,
with a separate IP limit of twenty code requests per minute; excess is `429`.

**POST `/auth/reset-password`**

```json
{
  "email": "anita@example.com",
  "code": "123456",
  "password": "new-secure-password",
  "password_confirmation": "new-secure-password"
}
```

Use the actual emailed code. Password is 8–72 characters and must match its
confirmation. Codes expire after ten minutes, allow five incorrect attempts,
and can be used once. Invalid/expired/consumed codes return `422` with
`errors.code`. Success:

```json
{"ok":true,"message":"Password reset successfully. Log in with your email and new password."}
```

After success, clear the mobile app's old bearer and log in again. All earlier
Laravel session/registration tokens are rejected, including legacy tokens.
Older Firebase authentication sessions require reauthentication. The queue
disconnects older Socket.IO sessions without disconnecting a new login that
has already happened. REST authentication is revoked immediately; socket
disconnect delivery requires the broadcasts worker and Socket.IO server.
Reset also records verified email ownership.

## Separate email verification

Existing accounts can continue email/password login without a new verification
gate. For explicit email verification after registration, use the current bearer:

```http
Authorization: Bearer <login-or-registration-token>
```

- `POST /auth/email/verification/request` with `{}` emails a verification code
  for the account's saved email. No client-supplied alternate email is used.
- `POST /auth/email/verification/verify` with `{"code":"123456"}` returns
  `{"ok":true,"emailVerified":true}` after proving ownership.

Verification and password-reset codes have separate purposes and cannot be
used interchangeably. Changing the saved email clears `email_verified_at`;
verify the new address again. Verified Google sign-in records verification
for its verified email. Fetch `GET /auth/session` to read
`user.email_verified_at`.

## Testing and maintenance

Import the updated [Postman collection](postman/registration.postman_collection.json).
Set `email`, `password`, `emailCode` (from email), and `newPassword` variables.
After successful reset the collection clears its old token and updates its
password variable. Run Email password login again.

```bash
ONBOARDING_TEST_DATABASE=nearby_onboarding_test php artisan test --filter=PasswordRecoveryTest
npm run chat:test
```

Tests use an isolated MySQL database and fake email delivery. The SMTP
connection/authentication was checked separately without sending a message.
For automatic cleanup of old codes, run Laravel's scheduler in your normal
deployment setup (`php artisan schedule:work` locally). A scheduled task
deletes up to 1,000 codes older than a day past expiry every five minutes.
Expiry and attempt checks work even if housekeeping is not running.
