# Muzztech SMS OTP integration

The Laravel backend supports the account's supplied GET request contract:

```text
https://connect.muzztech.com/api/sms/send
  ?api_key=<server-side credential>
  &phone_number=<recipient>
  &sender_name=BSLONL
  &message=<message containing the generated OTP>
  &template_id=<actual template ID>
```

`OtpSmsSender` URL-encodes the parameters and calls the fixed HTTPS host.
The app continues using existing `/auth/otp/request`, `/auth/otp/verify` and
`/auth/link-phone` APIs; it never receives the gateway key or calls Muzztech.

## Server configuration

Set the following in the untracked server `.env`:

```dotenv
OTP_SMS_PROVIDER=muzztech
OTP_TEST_MODE=false
MUZZTECH_API_KEY=<actual-secret>
MUZZTECH_SENDER_NAME=BSLONL
MUZZTECH_TEMPLATE_ID=<actual-template-id>
MUZZTECH_OTP_MESSAGE="Your Nearby Teacher verification code is {otp}. Valid for 10 minutes. Do not share this code with anyone."
MUZZTECH_PHONE_FORMAT=digits
MUZZTECH_SUCCESS_FIELD=status
MUZZTECH_SUCCESS_VALUE=success
```

The message above is the requested draft, not a confirmation of provider
approval. Match it to the panel's approved template before live delivery.
The actual template ID is mandatory; blanks and obvious placeholders block
sending. `{otp}` is replaced by the backend's randomly generated six-digit code.
No OTP values, API keys or raw gateway replies are returned to the app.

The key, sender and draft message have been set in this workspace's ignored
local `.env`. The template ID is still missing. This does not configure a
separate production server: copy the settings through your secure deployment
process. Keep the credential out of source control and frontend builds.

## Confirm recipient and response formats

The supplied URL did not specify recipient-number formatting or a response
schema. Confirm both from the provider panel before acceptance testing.

`MUZZTECH_PHONE_FORMAT` supports:

- `digits`: E.164 digits without `+`, e.g. `919876543210` (default).
- `e164`: includes `+`, e.g. `+919876543210`.
- `national`: national significant number, e.g. `9876543210` for India.

A successful HTTP status alone is not treated as successful submission.
By default the adapter requires JSON `{"status":"success"}`. This is a
configurable acceptance rule, **not a verified sample from Muzztech**.
For a real response `{"success":true}`, set:

```dotenv
MUZZTECH_SUCCESS_FIELD=success
MUZZTECH_SUCCESS_VALUE=true
```

Nested fields are supported, e.g. `data.accepted`. Set the expected field/value
from an actual success response or provider documentation. Unknown/HTML/error
responses fail closed. Gateway acknowledgement means submission acceptance;
it does not establish handset delivery. No delivery-receipt webhook or SMS
reporting history is added by this adapter.

## Runtime behavior

- The backend generates, hashes, expires and verifies OTPs as before.
- The existing 10-minute expiry, attempt limits and phone-linking flow remain.
- If no channel acknowledges submission, delivery returns `503` and invalidates the
  code. If another enabled channel succeeds, `202` preserves that usable code.
- Redirects and automatic send retries are disabled; ambiguous network failures
  must not automatically send another SMS.
- Muzztech HTTP exceptions are replaced without chaining the original exception,
  which could contain the URL, credential, phone and OTP. Controller logs only
  the exception class. Do not add HTTP request/URL logging for this gateway.
- Local/testing `OTP_TEST_MODE=true` returns the code in JSON without sending messages.
  Production ignores simulation. See [dual-channel delivery](OTP_DELIVERY.md).
- `OTP_SMS_PROVIDER=auto` preserves India→MSG91 and other countries→Twilio.
  Explicit `muzztech`, `msg91` or `twilio` selects that provider for all requests.
  Muzztech failure does not silently route a duplicate SMS through another gateway.

After setting production values:

```sh
php artisan config:clear
php artisan config:cache
```

Acceptance uses one authorized test recipient after the actual template and
response format are known. Confirm receipt, verify through the existing API,
and exercise Google phone linking using its Laravel bearer. No real SMS was
sent during implementation.

Automated tests fake all HTTP responses and use a disposable MySQL database:

```sh
php artisan test --filter=OtpSmsSenderTest
OTP_TEST_DATABASE=nearby_otp_test php artisan test --filter=MuzztechOtpTest
```
