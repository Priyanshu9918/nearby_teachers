# Learner parental consent

Learners aged 13–17 need parent/guardian approval. Under 13 is rejected; adults
return `parentalConsentStatus: "not_required"`. Existing registration submission
and DOB completion semantics are preserved. DOB cannot be changed after it is
saved through this API; corrections need support so a minor cannot switch to an
adult DOB to bypass consent.

## DOB and app-team delivery

Authenticated `POST /auth/date-of-birth`:

```json
{
  "dateOfBirth": "2010-01-01",
  "guardianName": "Example Parent",
  "guardianContact": "+919876543211"
}
```

`guardianContact` is a valid international phone number, normalized to E.164.
Email contacts are no longer accepted. A minor response includes:

```json
{
  "isMinor": true,
  "parentalConsentStatus": "pending",
  "parentalConsentPending": true,
  "parentalConsentRequired": true,
  "nextScreen": "guardian_consent",
  "parentalConsent": {
    "status": "pending",
    "link": "https://your-backend.example/parental-consent/ONE_TIME_TOKEN",
    "expiresAt": "2026-10-28 10:00:00",
    "parent": {"name": "Example Parent", "phone": "+919876543211"}
  }
}
```

The app team sends `parentalConsent.link` to `parentalConsent.parent.phone`.
The backend does not send SMS or email for consent and does not claim delivery.
The link uses the backend's hosted web route; configure `APP_URL` and HTTPS/proxy
handling for the publicly accessible backend. `APP_BASE_URL` is not used here.
`expiresAt` uses the application's configured timezone (UTC by default).

The raw token is returned only in this DOB response. Only its SHA-256 hash is
stored. Login/session do not return the token or link. Repeating DOB with the
same birth date creates a new request, replaces the old link and resets any
previous approval/rejection. The app should retain the generated link only for
delivery; do not call DOB just to poll. A failed delivery can retry using the
same link, or deliberately generate a replacement via the same DOB request.

## Parent web page and decision

`GET /parental-consent/{token}` shows the learner name, guardian name and
Approve/Reject controls. GET never changes consent. Decisions use POST with
normal Laravel web CSRF protection. No parent app or login is required. The
page contains no analytics or external assets and sets no-store, no-referrer,
noindex and frame restrictions. Links expire 48 hours after creation and are
consumed atomically under a row lock. A second decision cannot overwrite the
first. Invalid/replaced/consumed links return 404; expired links return 410.

The existing public API remains available:

```http
POST /auth/parental-consent/confirm
Content-Type: application/json
```

```json
{"token":"48-character-token-from-link","decision":"reject"}
```

Decision is `approve` or `reject`; omitted decision defaults to `approve` for
existing clients. Response: `{"ok":true,"parentalConsentStatus":"rejected"}`.
The web page uses the same service and state machine. Neither path requires the
learner bearer token. Both paths have request limits.

This is a link-possession consent flow. Because the DOB response is available
to the learner and returned to the app team as requested, it does not independently
prove parent identity or ownership of the parent phone. OTP verification or
server-to-server-only link delivery is outside this implementation. Do not
present link possession as verified guardian identity. Exclude/redact consent
paths from proxy access logs and avoid logging the DOB response or SMS body.

## Student login/session handling

Password login, OTP/Google auth and `GET /auth/session` share these status fields:

| `parentalConsentStatus` | `parentalConsentPending` | `parentalConsentRequired` | UI |
|---|---|---|---|
| `pending` | true | true | Show waiting for parent |
| `approved` | false | false | Home, after other onboarding steps |
| `rejected` | false | true | Show parent rejected; disable messaging/booking |
| `expired` | false | true | Show expired; offer a deliberate replacement request |
| `not_required` | false | false | Normal onboarding/home |

Incomplete phone/profile/DOB steps retain priority. Otherwise pending, rejected
and expired users route to `guardian_consent`. Login stays available so they can
see status. Messaging and booking continue enforcing consent on the server.
Refresh session on app foreground or after returning from the parent page;
there is no status push event. Never infer approval from successful login or
`registrationCompleted`. Expiry is calculated at read time, requiring no cron.

## Reporting and deployment

Run migration `2026_10_26_000035_add_parental_consent_decisions` through the normal
deployment migration process. It adds expiry/rejection timestamps and a token
lookup index. No application database was migrated as part of implementation.
Existing confirmed minors remain approved. Legacy pending links without expiry
must be replaced by a new DOB request with a parent phone; old raw tokens are
not accepted by the new hash lookup.

Version 1 `parental_consent.requested` and `parental_consent.decided` events store
only before/after status in the source transaction. Guardian decisions have no
invented authenticated actor ID and link to the learner as related participant.
The shared admin users report exposes confirmation/rejection/expiry timestamps;
the activity report provides request/decision history, using existing admin
access logs, bounded pages and streamed exports. Tokens, hashes, contacts and
DOB are absent from consent event snapshots. Users reports already allowlist
DOB and guardian contact for admins; consent tokens/hashes are never exported.
Read-time expiry has no synthetic history event. SMS delivery and guardian
identity verification are not recorded, and legacy decisions are not backfilled.

Test on a disposable MySQL database whose name ends in `_test`:

```bash
ONBOARDING_TEST_DATABASE=nearby_consent_test php artisan test --filter=StudentRegistrationCompletionTest
```
