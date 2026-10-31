# Reporting architecture

International SEO now uses `teacher.seo_state_changed`, `institute.seo_state_changed`, `seo.route_configured`,
`seo.page_registered` and `seo.page_updated` version 1 events in their source
transactions. Current pages, wording and URL ownership are explicit reports;
legacy teacher-page events stay append-only. See
[TEACHER_SEO_PAGES.md](TEACHER_SEO_PAGES.md) for lifecycle, privacy and gaps.

Institute address/GPS APIs emit version 1 `institute.address_updated` and
`institute.location_updated` events with changed field names and status flags,
never exact address snapshots or credentials. The institute profile report
explicitly registers current private address/enrichment columns. See
[institute address coverage](INSTITUTE_REGISTRATION_ADDRESS.md).

Version 1 `teacher.address_updated` records changed field names only, within
the source address transaction. Current teacher address columns are explicitly
registered in the teacher-profile report; exact past values are not archived.
See [teacher address coverage](TEACHER_REGISTRATION_ADDRESS.md).


Teacher GPS enrichment adds version 1 `teacher.location_updated` history with
only coordinate-presence flags and normalized geocoding status. Exact address
details are current private profile state, not append-only history. The existing
teacher-profile report explicitly exposes/searches `location_details` with its
normal admin access logging and CSV behavior. See
[TEACHER_LOCATION_GEOCODING.md](TEACHER_LOCATION_GEOCODING.md) for data contracts,
Google retention/reuse limitations, privacy and coverage gaps.

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

Current event coverage starts when migration `000029` is deployed. Safety reports
submitted through `/reports` or `/safety/reports` record version 1
`safety_report.submitted` activity in the same transaction as the report. The
event stores only target type, category, initial status and evidence-presence
flags; details, evidence URLs and private screenshot paths are excluded. The
admin status API records `safety_report.status_changed` with previous and new
status in the same transaction. The
reports registry explicitly exposes report details and evidence links plus a
screenshot-available flag. Screenshot downloads use the protected admin session
and access logging. History begins at deployment; earlier reports get no
invented activity rows.

Opening a valid `/download?ref=...` link awards one point to the code owner for
each unique browser token and referrer. A one-year, HTTP-only cookie lets repeat
opens from that browser avoid duplicate points; only its SHA-256 hash is stored
in `referral_clicks`, and the click row, point increment and version 1
`referral.clicked` activity commit together. No IP address, user agent, raw
cookie or request is copied into referral history. Code redemption still
records one joined account in `referrals`, but does not award another point;
version 1 `referral.joined` records this attribution without a point delta.
The unique referred-account constraint prevents duplicate joins. Existing
`referral.recorded` version 1 entries retain their original meaning and are not
rewritten. The points counter is initially seeded from existing referral rows,
so previously credited referrals remain points; future points come from link
opens. Referral activity history is not backfilled.

The allowlisted `users` report exposes the current `referral_points` balance,
the `referral-clicks` report exposes click IDs, referrer IDs and timestamps
(never visitor hashes), and the activity report exposes each point increment
after deployment.

Tracked activity events include:

| Event | Recorded metadata |
|---|---|
| `user.blocked` / `user.unblocked` | From message-blocking enforcement: version 1, target user entity/related ID, blocker actor ID and before/after `blocked` flag; no message bodies or request/token snapshots |
| `identity.phone_verified` | From 22 October 2026: verified flag before/after and phone-changed flag; no phone, OTP or token snapshot |
| `identity.google_linked` | Previous/new link flag, local user ID; Firebase UID/email/token are not duplicated |
| `auth.logged_out` | Previous/new auth version and `scope:all_sessions`; token revocation and history commit together |
| `registration.submitted` | Previous/new account type and `registration_submitted_at`; the full form saved before learner DOB completion |
| `registration.completed` | Previous/new account type and completion timestamp |
| `referral.recorded` | One awarded point; the referred account is actor and the referrer is related user |
| `referral.clicked` | One point for a new browser/referrer pair; the code owner is the related user |
| `referral.joined` | `source: referral_code`; the referred account is actor and the code owner is related user |
| `avatar.reviewed` | Previous/new moderation status only; API actions record the admin user ID and browser admin actions use the admin access log; image URL is excluded |
| `institute.bsl_verified_changed` | Previous/new BSL Verified flag; API actions record the admin user ID and browser admin actions use the admin access log |
| `user.suspended` / `user.unsuspended` | Previous/new suspension and reason-present flags; API actions record the admin user ID and browser admin actions use the admin access log; suspension text is excluded |
| `user.suspension_reason_changed` | API moderation records a reason change and whether a reason remains present; the admin user ID is recorded, but reason text is excluded |
| `teacher.profile_draft_saved` | Version 1: saved step, completed step numbers and next step only; emitted with section data and progress in the same transaction. No field values or credentials. |
| `teacher.profile_step_updated` | Teacher profile step number and changed field names; values such as name, bio, and location are not copied into the event |
| `teacher.profile_published` | Published status, terms acceptance and profile-completion flag, in the same transaction as page creation |
| `institute.seo_state_changed` | Version 1 institute SEO publication state, public slug, normalized country/city slug, selected subject IDs and online flag; no contact/address snapshots |
| `teacher.avatar_submitted` | Pending moderation status only; image URL/content is not copied into history |
| `learner.profile_updated` | Changed profile field names only; no name, email, DOB, phone or `phone_pin` values are copied into history |
| `learner.avatar_submitted` | Pending moderation status only; image URL/content is not copied into history |
| `onboarding.age_verified` | Previous/new age-verified and minor flags; no raw DOB, guardian contact or consent token snapshot |
| `booking.created` | Initial status, lesson mode, requested time and price |
| `booking.status_changed` | Previous/new status; repeated same-status requests add no change event |
| `message.created` | Message ID, conversation ID and participant IDs; message text is not duplicated |
| `message.replied` | Source conversation and original message ID |
| `message.delivered` / `message.read` | Recipient identity and first confirmed receipt timestamp |
| `message.edited` / `message.deleted` | Conversation, previous/new revision and mutation timestamp; no original or edited message text |
| `message.reaction_changed` | Conversation/message references and previous/new allowlisted reaction; null marks removal |
| `comment.like_changed` | Comment ID, acting user, comment author, and previous/new liked flag; duplicate likes and no-op unlikes add no event |
| `post.like_changed` | Post ID, acting user and previous/new liked flag; duplicate likes and no-op unlikes add no event |
| `feed.comment_created` | Comment ID, acting user, post ID and optional parent comment ID; comment text is not copied into history |

Post likes and comment/reply likes are available in current feed reads; their
append-only changes appear in the activity report. Comment creation and all
like/unlike mutations record history in the same transaction as the source
change. Duplicate likes and no-op unlikes do not create history or live events.
No old feed actions are backfilled. There are no comment edit/delete APIs, so
those actions have no history events. Feed post creation also has no activity
event yet. Cascading source deletion can remove current post/comment/like rows
while leaving business activity history intact.

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

Reports use live MySQL data. Interactive pages are bounded; CSV and workbook
exports read in chunks of 500. The owner workbook places each registered report
on its own worksheet and applies the same date, text, participant and SEO scopes
as the report pages. Long values beyond Excel's per-cell limit are preserved on
the workbook's `Long text` worksheet. Counts and text searches can still be expensive on large tables.
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
search/date/participant filters, CSV and XLSX output, transactional rollback,
surviving source deletion and real booking/chat operations.

## Search demand and weekly owner report

`search_analytics` records result counts for provider-search API calls. It stores
search type, optional subject, a safe city/state/country label or coordinates
rounded to a 0.1-degree area, radius, free-only flag and timestamp. It never
stores a user's free-text name query, exact coordinates, account ID, IP address
or user agent. `no-result-searches` explicitly filters this table to zero-result
rows and uses the shared report filters, access logging and exports. These
metrics start after the migration and API rollout; older searches are not
reconstructed. The append-only table has time/location indexes and must be
monitored for growth like page views.

The admin overview compares a selected date range with the equally long period
before it. Its metrics use users, profile-publication/business-activity events,
bookings, messages, search analytics, page views, referrals, reviews, enquiries
and safety reports. Signup, publication and action-event metrics begin when the
respective feature started recording them. Average ratings are calculated from
reviews created in the selected period. Contact requests count phone reveals;
job applications, franchise enquiries and support tickets are reported
separately. Support tickets are used for callback-request counts; contact
requests are phone reveals.

`reports:send-owner-weekly` emails the previous Monday–Sunday against the week
before it. The scheduler runs it Mondays at 08:00 in the application timezone.
Set `OWNER_REPORT_EMAIL` for the recipient; it falls back to `ADMIN_EMAIL`.
`REPORTING_LAUNCH_DATE` controls the “Since launch” preset and defaults to
22 July 2026. The scheduled email needs Laravel's scheduler and configured mail
transport running in production.

OTP delivery adds the `otp-delivery` registry report backed by append-only
`otp_delivery_attempts`. Each per-channel result is paired transactionally with an
`otp.delivery_result` business event; snapshots allow only channel, provider and
status. History includes only the last four phone digits and no code or raw gateway
payload. Results measure submission acknowledgement, not actual delivery. Historical
requests before this feature and receipt webhooks are not covered. See
[OTP delivery](OTP_DELIVERY.md) for partial failure and external-send limitations.

SEO location pages are registered in `seo-location-pages` and recorded with the
allowlisted `seo.location_page_registered` event in the same transaction as the
page row. History contains only path, country, language, page kind and optional
subject ID. Counts are computed from currently eligible teacher profiles; they
are not historical snapshots. Older location pages are not reconstructed into
business history.

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

Teacher six-step editor draft progress is current state in the teacher-profile
report (`completed_profile_steps`, `profile_draft_saved_at`), with existing
admin authorization, filtering and streamed CSV exports. The report timestamp
remains profile `created_at`; use business-activity `created_at` for save history.
`teacher.profile_draft_saved` starts at rollout; legacy unpublished profiles keep
saved fields but have no invented completion history. Generic profile updates,
photo uploads and GET/session reads do not emit draft progress. Final publication
continues to use `teacher.profile_published`; existing completed profiles report
all six steps complete. Section-save history records step metadata, not exact
past names, bio, address or contact values.

Shared teacher/institute profile editor (six-step rollout): institute current
state is explicitly allowlisted in `institute-profiles` with `listing_name`,
`contact_person`, `is_profile_complete`, `completed_profile_steps` and
`profile_draft_saved_at`. Existing report search, participant/date filters,
admin session/access logs, bounded pages and streamed CSV exports apply.
`created_at` remains the report timestamp; activity timestamps describe saves.

Version 1 `institute.profile_step_updated` records step and changed field names;
`institute.profile_draft_saved` records step/completed steps/next step;
`institute.profile_published` records status, terms-accepted and completion flags;
`institute.avatar_submitted` records moderation status only. Each event is recorded
inside the source mutation transaction. No names, exact locations, story text,
contacts, image URLs, requests or credentials are snapshotted. Teacher event names
and payloads stay unchanged. Shared GET/session reads emit no business mutation
history. Generic PUT and photo uploads do not advance editor progress.

The migration preserves existing institute pages with a nonempty slug as complete
for compatibility; this does not attest to historical terms acceptance or
reconstruct step history. No business events or draft timestamps are backfilled.
New profiles default to incomplete and their website pages stay hidden until
publish. Draft data has no expiry. Shared publication validates while holding the
profile lock; failed validation/history rolls back terms and completion together.

Owner-only draft responses additionally project current profile/account/subject
values into six explicitly allowlisted `steps[].data` objects for form prefilling.
This is a response projection, not a new business mutation or historical snapshot.
Business-activity events continue to contain progress metadata only. No draft
field values, credentials or complete user objects are added to history.
