# Reporting architecture

The owner may need new reports as the product grows. Reporting is therefore a
shared module with explicit data contracts and a business history stream.
This enables new reports on recorded data; it cannot reconstruct events or
attributes that were never captured.

## Components

| Component | Responsibility |
|---|---|
| `config/reports.php` | Stable report keys, source tables, output columns, timestamp, participant links, searchable fields and computed values |
| `ReportDefinition` / `ReportRegistry` | Immutable report descriptors and allowlisted key lookup |
| `ReportQuery` | Common joins, search, participant filtering and deterministic ordering |
| `ReportFilters` | Validation and timezone-aware, inclusive date ranges |
| `CsvExporter` | Shared streamed CSV output with spreadsheet formula escaping |
| `ReportAccessLog` | Record report reads, details and exports for browser admins |
| `BusinessActivityRecorder` | Versioned, allowlisted before/after event metadata in the source transaction |
| `AdminReportController` | HTTP orchestration and specialized user/conversation detail screens |

Pages and exports use the same query. New columns in a database table do not
automatically appear in reports. Registry SQL expressions are developer-authored
source code; HTTP clients cannot supply expressions, joins, tables or columns.
Views receive descriptors from the registry and do not depend on controller
constants. All report endpoints retain admin-session protection and no-store
response headers.

## Add a report

For example, to offer a focused booking timeline, add a config entry:

```php
'booking-timeline' => [
    'title' => 'Booking timeline',
    'table' => 'bookings',
    'columns' => ['id', 'learner_id', 'provider_id', 'status', 'created_at'],
    'timestamp' => 'created_at',
    'participants' => ['learner_id', 'provider_id'],
    'search' => ['status'],
],
```

It appears automatically in report selection and dashboard totals. Search,
date/user/type filters, pagination, audit logging and CSV export work without
adding a route/controller/view. `participants` columns reference users and
gain current names, emails and account types. A sender-filtered message report
shows sent messages; conversation reports cover both participants.

Complex aggregates with different semantics should get a dedicated query
service and view, reusing filters/export/audit services. Specify the time field,
units, denominator and status rules for each metric. Never sum prices in
different currencies or label booking requests as paid revenue. Do not add an
unrestricted SQL/report-builder endpoint.

## Historical data

`business_activity` contains immutable-by-application event records with:

- Event name and schema version.
- Entity type/ID and actor/related user IDs.
- Explicit `before_data` and `after_data` snapshots.
- Event timestamp and indexes for entity, participant and event timelines.

Developer chat diagnostics reuse the existing conversation/message APIs and
their authorization and history behavior. Messages sent by the tester follow
the same transactional `message.created` recording and post-commit delivery as
app messages. Health checks, pings, socket lifecycle and the diagnostic log are
transient observations; they are not retained as business events or available
for historical uptime/latency reporting. Conversation start/leave/end remains without business events; missing history
is not reconstructed. Named persistent chat mutations are recorded as described
below; typing hints remain transient.

Current event coverage starts when migration `000029` is deployed:

| Event | Recorded metadata |
|---|---|
| `user.blocked` / `user.unblocked` | From message-blocking enforcement: version 1, target user entity/related ID, blocker actor ID and before/after `blocked` flag; no message bodies or request/token snapshots |
| `identity.phone_verified` | From 22 October 2026: verified flag before/after and phone-changed flag; no phone, OTP or token snapshot |
| `identity.google_linked` | Previous/new link flag, local user ID; Firebase UID/email/token are not duplicated |
| `auth.logged_out` | Previous/new auth version and `scope:all_sessions`; token revocation and history commit together |
| `registration.submitted` | Previous/new account type and `registration_submitted_at`; the full form saved before learner DOB completion |
| `registration.completed` | Previous/new account type and completion timestamp |
| `onboarding.age_verified` | Previous/new age-verified and minor flags; no raw DOB, guardian contact or consent token snapshot |
| `booking.created` | Initial status, lesson mode, requested time and price |
| `booking.status_changed` | Previous/new status; repeated same-status requests add no change event |
| `message.created` | Message ID, conversation ID and participant IDs; message text is not duplicated |
| `message.replied` | Source conversation and original message ID |
| `message.delivered` / `message.read` | Recipient identity and first confirmed receipt timestamp |
| `message.edited` / `message.deleted` | Conversation, previous/new revision and mutation timestamp; no original or edited message text |
| `message.reaction_changed` | Conversation/message references and previous/new allowlisted reaction; null marks removal |

Migration `000033` adds persisted receipt/edit/delete metadata, reply links and
per-user reactions. Existing rows gain revision 1 and nullable new fields; no
past delivery, edit or delete events are invented. Message edit/delete replaces
or clears source text, so historical body versions cannot be reconstructed.
Deleted messages retain tombstones and receipt metadata. Reaction rows remain
for reporting, even when removed (null reaction) or their source message is
deleted. The `messages`, `message-reactions` and activity reports expose explicit
columns through the shared admin filters, CSV exporter and access logger.
These events use schema version 1 and are recorded inside the source transaction;
notifications dispatch only after commit. Typing and stop-typing have no retained
business history. See [chat event contracts](CHAT_EVENTS.md).

Registration and booking changes record history inside existing source
transactions. Booking creation and message sends wrap source writes and event
recording together. A failure rolls both back. Socket broadcasts remain after
the transaction commits. Source deletion does not cascade to event records;
user joins may become empty while stored IDs and metadata remain available.
There is no automatic backfill and no complete source-record archive.

To add an event, declare its entity type and allowed snapshot keys in
`BusinessActivityRecorder::EVENTS`, then call the recorder from the source
mutation transaction. Unknown events/fields and out-of-transaction calls are
rejected. For a changed schema, define and implement a new version; do not
reinterpret existing version-1 snapshots. Rejected operations must not produce
success events. Provide appropriate indexes and tests alongside the mutation.

Existing `auth_activity` remains a separate stream for successful or
controller-rejected login/OTP/registration responses, with IP/user-agent
metadata. Its best-effort logging must not break authentication. Business
state history has stronger transactional consistency. Neither stream is an
online-session tracker, tamper-proof ledger or full history of every module.
Google-bearer OTP verification reuses phone linking; its response is logged as
`otp_verification`. Phone linking now records version-1 `identity.phone_verified`
in the same transaction as OTP consumption and the user update. Failed history
writes roll all three back; conflicts do not consume OTPs. Earlier phone links
and normal signup OTP verification have no dedicated phone event, so historical
phone ownership cannot be fully reconstructed. The OTP row binds Google
requests via `google_user_id` (migration `000031`); it stores no bearer. Tokens,
OTP codes, full phones and Google IDs are never added to business history.
Password login rejected for incomplete registration produces an unsuccessful
`email_login` entry in `auth_activity` with status `409`. It issues no token and
changes no business state, so no new business-history event is added. Reading
saved onboarding details for autofill does not create a registration event.
Form submission records `registration.submitted` transactionally; a learner
without DOB remains incomplete, while teacher/institute form submission also
records `registration.completed`. DOB records `onboarding.age_verified` and,
when it completes a submitted learner registration, `registration.completed`
in the same transaction. A pending-DOB registration retry does not duplicate
submission/completion events. Migration `000032` adds the submission timestamp;
legacy completed records count as submitted without invented historical events.
Successful API logout records version-1 `auth.logged_out` in the same transaction
as auth-version/cutoff updates. Rejected and rolled-back logout attempts do not
produce this event. No bearer, Firebase UID, password or request snapshot is
recorded. Active chat revocation is queued after commit. This event describes
API session revocation, not a Google-provider logout or a historical online
presence signal. Rejected logout attempts are not included in `auth_activity`.
Admin edits/deletes, moderation, support transitions and uninstrumented paths
need explicit events if historical questions about those actions are required.

## Operations and growth

App presence is ephemeral operational telemetry stored in expiring Redis keys,
not a source business mutation. Presence heartbeats/activity/background and
automatic expiry do not write business events or alter SQL users/profiles.
Current provider search flags do not establish historical online duration,
last-seen history or past active counts. These are explicit coverage gaps;
new historical reporting would need approved aggregates and retention, without
raw device IDs, requests or tokens. See [presence coverage](PRESENCE.md#reporting-coverage).

Run migrations before deploying code that writes new event types/tables:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
```

Reports use live MySQL data. Interactive pages are bounded; exports read in
chunks of 500. Counts and text searches can still be expensive on large tables.
Measure query plans and latency before adding indexes, cached aggregates,
summary tables, read replicas or asynchronous exports. Do not call this an
analytics warehouse or assume chunking removes query cost. Chunked exports are
not point-in-time snapshots under concurrent writes.

History growth must be monitored. No automatic history deletion is installed;
define retention/anonymization rules before adding a cleanup job. New snapshots
need field-level review so tokens and unnecessary personal data are not copied.

Tests accept `REPORTING_TEST_DATABASE` and `ONBOARDING_TEST_DATABASE` pointing
to a disposable MySQL database ending in `_test`. They exercise report
extension without controller changes, explicit columns, access control,
search/date/participant filters, CSV output, transactional rollback, surviving
source deletion and real booking/chat operations.

OTP delivery adds the `otp-delivery` registry report backed by append-only
`otp_delivery_attempts`. Each per-channel result is paired transactionally with an
`otp.delivery_result` business event; snapshots allow only channel, provider and
status. History includes only the last four phone digits and no code or raw gateway
payload. Results measure submission acknowledgement, not actual delivery. Historical
requests before this feature and receipt webhooks are not covered. See
[OTP delivery](OTP_DELIVERY.md) for partial failure and external-send limitations.

Message blocking uses the existing `blocks` report for current relationships and
the `activity` report for append-only block/unblock history. Each real block or
unblock is recorded in the same transaction as its relationship change; retries
that leave state unchanged create no duplicate event. History-write failures
roll back the relationship mutation. Unblocking deletes the current relationship,
not its historical events. Earlier blocks/unblocks have no invented history.
Blocked chat attempts create no message/activity mutation; they are not recorded
as a new security-attempt report. Generic report participant/date/search filters,
CSV exports, admin access checks and access logging apply to these events.

## Parent consent decisions

`parental_consent.requested` and `parental_consent.decided` version 1 retain
before/after status only, in the same transaction as the learner mutation.
Parent decisions have no authenticated actor ID; related participant is the
learner. Users reports explicitly expose consent confirmation/rejection/expiry
timestamps and activity reports cover request/decision history. Token hashes
and raw links are never reportable. Read-time expiry has no synthetic event;
app-team SMS delivery and guardian identity verification are not captured.
Legacy decisions are not reconstructed. See [consent contract](PARENTAL_CONSENT.md).
