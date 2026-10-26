# Project audit and capacity plan

Reviewed: 26 October 2026. Source revision: `42c8c17`.

The confirmed target is **20 lakh total registered users (2,000,000 accounts)**.
The application has useful foundations, but it is not yet validated for the
traffic and data growth associated with that target. Several authorization, privacy and reliability defects
need fixing before increasing traffic. Passing the existing suite does not
cover these defects or establish production capacity.

This was a review, not an application rewrite. Application code, dependencies,
environment settings and deployment services were not changed. Only this report
was added. Integration checks used a newly created disposable database named
`nearby_project_audit_test`; no application database was migrated or cleared.

## Scope and evidence

Reviewed Laravel routing/middleware, authentication and OTP flows, onboarding,
profiles, booking/review/community APIs, search SQL, chat and Socket.IO,
reporting, migrations/indexes, queue/cache/session configuration, public
website generation, deployment scripts and CI. The Express/PostgreSQL tree
under `src/` is explicitly a legacy reference according to README; Laravel
and `realtime/` are the deployed architecture described by this repository.
The legacy implementation was inspected selectively and syntax checked, not
given a separate production security certification.

Checks performed:

| Check | Result |
| --- | --- |
| Complete existing Laravel suite | **184 tests, 4,952 assertions passed**, disposable MySQL database |
| Socket.IO integration suite | **14 tests passed**, temporary localhost servers and API test doubles |
| Developer UI/deployment Node tests | **3 test files passed**; UI interaction tests use their existing harness, not a full browser deployment |
| Targeted temporary audit probe | **10 assertions passed confirming the undesirable behaviors below**, all fixtures rolled back |
| PHP syntax | 121 application/configuration/migration files passed |
| JavaScript syntax | 43 files passed; the three `resources/js` files were checked in ES-module mode |
| Deployment shell syntax | Both deployment scripts passed `bash -n` |
| Composer production dependency audit | Advisory entries found for locked `laravel/framework v11.57.0`; see F1 |
| Realtime npm production audit | No known advisories returned at audit time |

No production load test, external penetration test, backup restore, failover
exercise or production query-plan analysis was performed. Actual web virtual
host configuration, edge TLS, PHP-FPM limits, database sizing, production
metrics and recovery objectives have not been established by this review.
The root legacy Node package has no committed lockfile and was not covered by
the realtime npm audit.

## Findings to address first

### F1 — High: unsupported Laravel version with security advisories

`composer.lock:1451` locks Laravel `v11.57.0`; `composer.json` restricts updates
to major 11. Laravel's published support table ends Laravel 11 security fixes
on **12 March 2026**. The dependency audit returned five advisory entries,
including duplicate records for the same email-validation advisory. They cover
TokenGuard bypass, debug-page XSS, temporary signed URL confusion and email
validation CRLF injection.

These are dependency findings, not proof that each exploit works against this
application. API authentication uses custom JWT/Firebase middleware; the
configured guards do not use TokenGuard. Debug XSS is conditional on debug
exposure, and signed URL use was not established. Email validation is used in
application flows, so it deserves specific regression verification.

Upgrade to a currently supported, fully patched Laravel release compatible
with the chosen PHP runtime, update constraints and lockfile, rerun the full
suite, then repeat `composer audit --locked --no-dev`. Updating within major
11 alone does not resolve its support status.

Sources: [Laravel support policy](https://laravel.com/framework/docs/11.x/releases),
[Laravel TokenGuard advisory](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6),
[Laravel email validation advisory](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq).

### F2 — High if deployed: development settings in the current checkout

Read-only bootstrap inspection returned `app.env=local`, `app.debug=true`,
`cache.default=file`, `session.driver=file`, `session.secure=null`,
`queue.default=database`, and `broadcasting.default=socketio`. The configured
application URL uses HTTP; Socket.IO Redis was unset and the CLI PHP Redis
extension was absent. These observations describe this checkout's configuration,
not a verified external production endpoint or its edge TLS termination.

If this serves real users, configure production mode, disable debug, enforce
HTTPS and secure cookies, and verify that the web root exposes only `public/`.
Test that environment files, source, Git metadata and logs cannot be downloaded.
The deployment script currently accepts HTTP and does not enforce production
mode/debug settings. Add deployment preflight checks. Behind a load balancer,
configure trusted proxies deliberately so HTTPS detection and IP throttles work.

### F3 — High: blocking does not prevent messaging

[`OperationsController::block`](../app/Http/Controllers/Api/OperationsController.php)
stores the pair, but message start/send, chat event handling and socket
authorization do not consult `blocks`. A fixture where the recipient blocked
the sender still returned **HTTP 200** from `POST /messages/{conversationId}`.

Enforce the chosen blocking policy centrally for new and existing conversations,
both REST and Socket.IO paths, and phone reveal/booking where applicable.
Decide whether historical messages remain readable. Record block/unblock events
transactionally and test both directions and existing socket sessions.

Implementation follow-up: message blocking has now been implemented in the
workspace, with Developer chat tester Block/Unblock controls, enforcement on
REST and Socket.IO paths, queued-delivery checks, status fields and transactional
block/unblock history. Existing chat history remains readable. The updated suite
passes 194 Laravel tests and 15 Socket.IO tests. See
[message blocking](CHAT_EVENTS.md#block-and-unblock-messages) for the API and
mobile integration contract. This follow-up has not deployed/restarted services
and does not resolve the other audit findings.

### F4 — High: phone reveal bypasses the contact consent gates

[`ProfilesController::show`](../app/Http/Controllers/Api/ProfilesController.php:151)
reveals a provider's phone to an authenticated, phone-verified viewer within
quota. It does not apply the safety acknowledgment or minor/guardian-consent
checks used for messaging and bookings. A minor with no confirmed parental
consent and no safety acknowledgment received the provider phone in the probe.

Reuse a shared contact authorization policy before revealing contact details;
apply blocking and target availability rules. Keep contact fields out of public
cache entries and test anonymous, minor, blocked and suspended cases.

### F5 — High: booking participants can choose arbitrary status transitions

[`BookingsController::updateStatus`](../app/Http/Controllers/Api/BookingsController.php:99)
checks participant membership, but not which participant may perform an action
or which transitions are allowed. The learner accepted their own booking with
**HTTP 200**, then changed a cancelled booking to completed with **HTTP 200**.
This also makes completed-lesson statistics unreliable.

Define actor-specific transitions, enforce them under the existing row lock,
reject invalid transitions, and make retries idempotent. Keep valid changes in
the existing transactional booking history. Send status notifications only
when a transition actually occurs; the current endpoint can send repeated
accepted/declined notifications for an unchanged status.

### F6 — Medium: review eligibility and duplicate handling

[`CommunityController::createReview`](../app/Http/Controllers/Api/CommunityController.php:138)
requires booking ownership but does not require an eligible booking status.
A review on a `requested` booking returned **HTTP 200**. Repeating that review
returned **HTTP 500**, because the unique booking constraint is not translated
to an expected conflict response. The constraint itself correctly prevents a
second persisted review.

Agree the review eligibility rule, enforce it inside the transaction, and return
a controlled `409` or the existing result for a duplicate. Test simultaneous
submissions and unchanged rating aggregates on failure.

### F7 — Medium: viewing the same profile again produces HTTP 500

[`ProfilesController::recordPhoneReveal`](../app/Http/Controllers/Api/ProfilesController.php:328)
returns only `['allowed' => true]` for an already revealed profile. `show()` then
reads `remainingToday` unconditionally when allowed. First view returned **200**;
second view by the same user on the same day returned **500** in the probe.

Return a consistent quota structure on every branch. Verify repeat visits,
day boundaries, exhausted quota and simultaneous requests.

### F8 — High: suspended/unpublished provider filtering is inconsistent

[`SearchController::dashboard`](../app/Http/Controllers/Api/SearchController.php:17)
checks account type, suspension, phone verification and profile completion.
`nearby`, `counts`, `otherCity` and `bySubject` do not consistently enforce those
conditions. Public profile/website reads also lack equivalent visibility checks.
The same suspended teacher appeared in nearby search and was correctly absent
from dashboard search in the probe.

Apply one explicit publication/availability rule across discovery, counts,
direct profile reads and contact creation. Filter before each nearest-50 limit
so ineligible rows cannot consume result slots. Suspension currently also does
not revoke existing sockets; those can receive pushes until session expiry.
Define and enforce immediate suspension behavior for active sessions.

## Performance and reporting findings

| ID / priority | Evidence and impact | Improvement |
| --- | --- | --- |
| F9 / High | `SearchController::dashboard` fetches **all** matches, loads all their subjects, builds objects and sorts in PHP. Radius allows 500 km. Its test explicitly expects more than 50 rows. Dense areas can exhaust memory/response budgets even though `/search` is limited. | Introduce viewport/zoom-based map clusters, bounded marker/detail responses and pagination. Coordinate the API contract with clients; silently adding a limit would break existing expectations. |
| F10 / High | `WebsiteController::city` loads every teacher/institute in the city. `sitemap()` plucks every slug, accumulates all URLs and renders one XML response. Both grow with the full dataset. | Paginate city listings. Generate static, partitioned sitemaps in background jobs and serve a sitemap index. The protocol limits a sitemap to 50,000 URLs and 50 MB uncompressed. [Protocol](https://www.sitemaps.org/protocol.html). |
| F11 / High | Moderation conversations/messages/bookings/photos/contact stats and several support/report/suggestion/referral lists use unbounded `get()`. One admin action can scan/serialize a large fraction of chat or user data. | Add bounded pagination, date filters and explicit projections. Preserve shared reporting contracts, admin authorization and access logging when moving report-like reads into the registry. |
| F12 / Medium | City/state/country search uses `lower(column)`, other-city search uses leading-wildcard `LIKE` plus JSON mode checks, and ranking computes referral counts. A result `LIMIT 50` does not bound candidate scanning/sorting. | Normalize location identifiers and lesson modes, measure branch query plans, and evaluate subject-first composite indexes/materialized ranking counters. Retain exact geographical filtering; choose spatial/geocell indexing only after measurement. |
| F13 / Medium | Reports run exact full-table counts for every registry entry on dashboard load. CSV uses `lazy(500)`, which bounds PHP memory but uses increasingly deep offsets. Exports do not represent a fixed snapshot under concurrent writes. | Cache/maintain dashboard summaries, use stable keyset iteration or a defined export snapshot, and move measured heavy exports/aggregates to dedicated workers/read replicas. Keep page/export filters and allowlists consistent. |
| F14 / Medium | Public catalogue/translations are regenerated per request; website rendering reads multiple translation files. Public profile and website views write analytics synchronously. Existing authenticated profile reads also lock the viewer row for phone quota. | Cache versioned catalogue/language data; separate cached public profile content from private contact/quota operations. Batch analytics with an explicit durability policy. Preserve synchronous consent/quota enforcement. |
| F15 / Medium | `LocationLookupController` calls public Nominatim with a per-IP one-second limiter and no result cache or application-wide limiter. Many IPs can generate concurrent external calls occupying PHP workers. | The public endpoint permits **at most 1 request/second for the entire application**, not per user/IP. Add an application-wide limiter and cache for any retained use; for high traffic configure a suitable hosted or self-run service and a switchable URL. [Nominatim policy](https://operations.osmfoundation.org/policies/nominatim/). |
| F16 / Medium | Only expired email-code cleanup is scheduled; phone OTPs and several operational tables have no demonstrated retention plan. History growth and deletion gaps are already acknowledged in reporting documentation. | Define retention/anonymization for operational/security data, schedule bounded cleanup, and monitor growth. Do not automatically delete append-only business history; preserve approved historical questions and audit requirements. |

Additional correctness/reporting gaps found by inspection:

- `AdminReportController::user` uses `$profile->id` to retrieve subjects, while
  operational profile paths write `profile_subjects.profile_id = user_id`.
  Independent numeric profile/user sequences can show missing or wrong subjects.
- The reporting dashboard read does not call `ReportAccessLog`, unlike the
  report index, user and conversation reads.
- `classifyInstitute` can change a `bsl_direct` institute to another type without
  clearing `is_bsl_official`; classification and badge changes need one enforced
  invariant and transactional history.
- Admin edits/deletes, profile verification, moderation, support transitions,
  blocks and other uninstrumented mutations cannot all be reconstructed from
  business history. These are documented coverage gaps, not historical evidence
  that can be backfilled accurately. Add versioned, allowlisted events in the
  source transactions as each path is fixed.
- Source rows and some older access logs cascade-delete with users/conversations.
  `business_activity` survives, but its limited snapshots are not a complete copy
  of deleted conversations/bookings. Define deletion/anonymization behavior
  against the intended reporting requirements.

## What is already useful

Chat history, inbox, bookings, reviews, comments and app feeds already have
bounded cursor pagination. Message sends check membership without fetching
conversation history. Nearby search uses geographical bounds and a nearest-50
shortlist. Existing indexes cover several participant/time access patterns.
Notifications and persistent chat broadcasts are queued separately, and jobs
such as email codes/revocation explicitly defer until commit. Business history
has versioned event allowlists and transactional writes; report columns are
explicit and CSV output guards against formula injection. CI exercises MySQL,
chat, deployment recovery and developer interactions.

The existing performance document includes useful **serial, synthetic SQL**
measurements. They do not measure HTTP concurrency, queue throughput or a
20-lakh-user production workload.

## A practical plan for 20 lakh registered users

### First define the workload

The owner confirmed **20 lakh total registered users** after the initial review.
DAU, peak API requests/second and simultaneous socket connections remain unknown.
Size and test the user/profile dataset for 2,000,000 accounts, with realistic
learner/teacher/institute proportions. Size messages, bookings and event history
separately using expected usage and retention. Capture active sockets,
message/read/typing events per second, search radius/density and response sizes.

Illustration only: 2,000,000 registered accounts, 10% DAU and 40 API requests per
active user/day gives 8,000,000 requests/day, about **93 average RPS**. A 10x peak
would be about **930 RPS**. These are assumptions, not this application's measured
traffic. At 20,000 active sockets, socket memory/reconnects and chat event rates
must be measured separately from HTTP request concurrency.

### Phase 1 — correctness and bounded work

Resolve F1–F8, production settings, unbounded dashboard/map responses, city pages
and moderation lists first. Add regression coverage for each reproduced defect.
Introduce request idempotency for message/booking creation and expected duplicate
handling. After-commit dispatch does not eliminate the failure window between a
committed source mutation and successfully enqueuing its delivery: evaluate a
transactional outbox for required notifications/broadcasts and retry-safe clients.

### Phase 2 — separate and scale runtime components

- Put HTTPS/load balancing in front of multiple Laravel instances; use production
  PHP-FPM, OPcache, deployment caching and measured process/connection budgets.
- Move shared rate-limit/cache state and sessions to a suitable shared store
  before multiple app nodes. This checkout uses file-backed state. Install a PHP
  Redis client/extension before choosing `REDIS_CLIENT=phpredis` here.
- Evaluate Redis or another suitable queue backend as queue volume grows. The
  existing service installer **requires `QUEUE_CONNECTION=database`**; update its
  validation/operations workflow before changing that setting. Scale broadcasts
  separately from OTP/email jobs; monitor queue age, retries and failed jobs.
- Scale Socket.IO processes with the already supported Redis adapter setting.
  HTTP long-polling requires sticky load balancing across nodes, even with the
  Redis adapter; websocket-only clients have different routing requirements.
  Test reconnect storms and Redis interruptions.
  [Socket.IO adapters](https://socket.io/docs/v4/adapter),
  [Redis adapter behavior](https://socket.io/docs/v4/redis-adapter).
- Keep MySQL on appropriately sized, durable infrastructure with tested backups
  and recovery. Optimize measured hot queries before adding replicas. Send
  suitable reporting/search reads to replicas only with an explicit lag policy;
  authorization, consent, quotas and immediate post-write reads need consistency.
- Cache static assets/public anonymous content through a CDN where appropriate.
  Keep bearer responses, admin pages and phone/contact results private. Use object
  storage/CDN delivery for controlled media assets as those are introduced.

The existing deployment script updates one checkout in maintenance and stops
chat/queues. At this scale, move toward release directories, rolling/draining
instances, backward-compatible migrations and automated health gates. This needs
deployment design work; adding servers alone does not make the current deployment
process operate as a coordinated fleet.

### Phase 3 — prove capacity on staging

Use a staging environment with representative profile density, users,
conversations, message history and reporting volume. Test search, inbox, message
send/read, map loading, auth and reports together; isolate real SMS/email costs
with provider test integrations. Test high-density cities as well as sparse data.

Ramp through increasing RPS/socket stages and then run a sustained soak and
failure/reconnect scenarios. Example initial acceptance goals to agree with the
product team: common read API p95 under 300 ms, dense search p95 under 500 ms,
chat end-to-end p95 under 1 second and server error rate under 0.1%. These are
proposed targets, not measured results or guarantees.

Observe p95/p99 latency, errors, DB examined rows/slow queries/lock waits,
connections, PHP worker saturation, Node event-loop lag, socket memory, Redis
latency, queue age, CPU/RAM/disk and replication lag. Use real query plans to
select additional indexes. Allocate node counts only after finding throughput
per instance and required failover headroom. No fixed server count or claim of
20-lakh concurrent-user support is justified by the current evidence.
