# SMS and WhatsApp OTP delivery

The backend generates one six-digit code and submits the same code to every enabled
channel. Google phone-linking and normal registration use the existing request and
verify endpoints. Live API responses never include the code. Local/testing `OTP_TEST_MODE=true`
returns the generated `otp` in JSON without sending messages. The database stores only the code hash.

## Required server configuration

Keep credentials in the server `.env`, never in the app or Git:

```dotenv
OTP_TEST_MODE=false
OTP_DELIVERY_CHANNELS=sms,whatsapp
OTP_SMS_PROVIDER=muzztech
MUZZTECH_API_KEY=<server-only-key>
MUZZTECH_SENDER_NAME=BSLONL
MUZZTECH_TEMPLATE_ID=<approved-SMS-template-id>
MUZZTECH_OTP_MESSAGE="<exact approved text containing {otp}>"
MUZZTECH_PHONE_FORMAT=digits
MUZZTECH_SUCCESS_FIELD=status
MUZZTECH_SUCCESS_VALUE=success
WHATSAPP_ACCESS_TOKEN=<server-only-Meta-token>
WHATSAPP_PHONE_NUMBER_ID=<Meta-business-phone-number-id>
WHATSAPP_OTP_TEMPLATE_NAME=<approved-authentication-template-name>
WHATSAPP_TEMPLATE_LANGUAGE=en_US
WHATSAPP_GRAPH_VERSION=<supported-version-for-your-Meta-app>
```

Confirm the SMS recipient format and actual success response with Muzztech. The
current marker `status=success` is configurable and has not been verified live.
The existing draft SMS text must match an approved provider template before use.

WhatsApp currently uses the official Meta Cloud API, not a guessed Muzztech endpoint.
Create/approve an authentication template with a copy-code button in the WhatsApp
business account; configure its exact name and language, registered sender number
ID, supported Graph API version (format `vNN.N`), and an access token permitted to
send WhatsApp messages. The adapter sends the OTP as the body parameter and the
URL-button parameter at index 0. Other template structures need a corresponding
adapter change. See [Meta's message-template API example](https://www.postman.com/meta/whatsapp-business-platform/request/lwtlz1k/send-message-template-interactive).

After configuring the values on the deployment server:

```bash
php artisan migrate --force
php artisan config:clear
```

All enabled channels are checked for required configuration before any request is
sent. Missing WhatsApp credentials therefore also prevent SMS when both are enabled.
For an explicitly SMS-only deployment, select `OTP_DELIVERY_CHANNELS=sms`.

## App handoff

Call `POST /auth/otp/request` with `{ "phone": "+919876543210" }`. No provider
credentials, template IDs or channel selectors belong in the client request.

- `200`: `{"ok":true,"expiresInMinutes":10,"channels":{"sms":"accepted","whatsapp":"accepted"}}`
- `202`: one channel accepted, another failed; `partialDelivery:true` and channel
  statuses are included. Let the user enter the received code. Do not auto-resend.
- `503`: no channel accepted; code invalidated. Channels report `failed` or
  `not_sent`. A generic error does not expose provider diagnostics.
- `429`: request limit reached (three per number per ten minutes).

Use the received code in `POST /auth/otp/verify`, or `PATCH /auth/link-phone` for an
authenticated Google user. Existing ten-minute expiry, five attempts and one-time
use remain. Use only the latest requested code. SMS and WhatsApp can arrive at
different times. `accepted` means provider acknowledgement, not handset delivery.
No delivery webhooks or receipt reconciliation are implemented.

`OTP_TEST_MODE=true` only simulates in local/testing, with `testMode:true` and
`simulated` statuses and the generated `otp`; it sends no messages. Production
ignores test mode and never includes the code in responses.
Tests capture the generated code inside the test process using fake transports.

## Reporting and operational limits

Admin → Insights & activity → OTP channel submissions shows per-channel submission
history, including provider, status, OTP request ID, last four phone digits and time.
No code, message, full phone, credential or raw provider response is stored in this
history. `otp.delivery_result` events are recorded in the same database transaction
as each result and any failure invalidation. Older requests have no backfilled history.

Requests run synchronously with bounded timeouts and without send retries or
redirects. A timeout can mean an ambiguous provider outcome. External sends cannot
be rolled back if result persistence later fails; do not automatically retry such
requests. Live delivery still requires completed account configuration and a real
number test. The implementation tests use fake gateway responses and send no messages.
