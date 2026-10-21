# API performance changes and deployment

These changes address the identified code bottlenecks. They do not establish
a maximum user capacity: that also depends on the production database,
hardware, concurrent traffic, dataset distribution and query plans.

## What changed

- Sending a message uses an indexed conversation-membership existence check.
  It does not fetch the conversation history. Only the newly inserted message
  is loaded for the response/event.
- Inbox looks up the latest message through the existing
  `(conversation_id, created_at)` index for the caller's conversations.
  It no longer ranks the global messages table with a window function.
  The other participant is joined by primary key.
- Chat history, inbox, bookings, comments, provider reviews and city feeds
  use cursor pagination (default 50, maximum 100). Created time plus ID
  provides stable ordering for tied timestamps, without deep OFFSET scans
  or whole-list count queries. The frontend must follow the returned cursors.
- Nearby search and counts apply latitude/longitude bounds to both provider
  tables before exact spherical distance filtering. Bounds handle date-line
  crossings and polar searches. Exact distance still decides inclusion;
  bounding-box corners outside the radius are excluded. The existing
  `(latitude, longitude)` indexes support the candidate range filters.
- Nearby results use an index-covered shortlist of profile IDs and distance,
  with all requested filters applied before each type's nearest-50 limit.
  At most 100 candidates are merged into the global nearest 50; full provider
  and user details are joined afterward. The existing location indexes suffice;
  no new migration is required for this query change. `Server-Timing` measures
  the search phase after validation, excluding startup and network latency.
- Booking and review list indexes cover their owner/provider and creation
  time. Existing message/comment/feed indexes already cover their filters
  and creation time; InnoDB secondary indexes include the primary key.
- Public profile lesson counts are restricted to the requested provider,
  rather than grouping every provider's booking history.
- Booking, comment-reply, support and guardian-consent emails are queued.
  Delivery failures propagate to the worker, with three attempts and backoff.
  Chat broadcasts are queued separately so API responses do not wait on
  the Socket.IO server/network round trip.

## Apply and run

```bash
php artisan migrate
php artisan config:clear
```

Keep `QUEUE_CONNECTION=database` in `.env`. Existing deployments should change
`sync` to `database`. The workspace and `.env.example` are configured for this.
The jobs/failed-jobs tables come from the existing jobs migration; the new
migration adds pagination indexes without changing UUIDs or deleting records.

Keep these workers and the Socket.IO server running in separate processes (see [CHAT_SOCKET_IO.md](CHAT_SOCKET_IO.md)):

```bash
php artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30
npm run chat:start
```

Use a process manager to restart workers after crashes/reboots. Separate
broadcast workers prevent slow email delivery from delaying chat updates.
After deploying code or changing configuration, reload workers with:

```bash
php artisan queue:restart
```

`composer run dev` listens to all three queues for local development. With
workers stopped, messages remain saved, but broadcasts and emails wait in
the queue. Configure real SMTP for delivery; the log mailer intentionally
skips notifications. A transport outage should create retries/failed jobs,
not make the API wait for SMTP. Inspect failures using:

```bash
php artisan queue:failed
```

Only retry failed jobs after resolving the delivery/configuration issue.

## Frontend pagination

Example:

```text
GET /messages/{conversationId}?perPage=50
GET /messages/{conversationId}?perPage=50&cursor=<pagination.nextCursor>
```

The original array keys remain and `pagination` is added. Do not expect the
whole history in one request. Chat starts with the latest page; each page is
chronological. Prepend older pages when following `nextCursor`. Other sort
orders and cursor details are in [API.md](API.md#cursor-pagination).

## Verification and remaining capacity validation

Database tests require a disposable MySQL 8 database ending in `_test`:

```bash
ONBOARDING_TEST_DATABASE=nearby_onboarding_test php artisan test
```

Tests verify message sends against a conversation containing 1,000 messages
without reading its history, latest-message selection and inbox pagination,
pagination without skipped tied records, membership checks, exact-radius
searches including date-line/pole cases, background broadcasts and queued
notifications. They also verify that mail transport errors remain retryable.

The reproducible database-only comparison uses an existing disposable
database ending in `_test`. It migrates that test database, inserts generated
profiles/messages inside a transaction, compares old and optimized query
patterns, prints the geographical query plan, and rolls back the seed records:

```bash
API_BENCHMARK_DATABASE=nearby_performance_test php tests/benchmarks/api.php --rows=20000
```

Use the configured MySQL credentials/socket. `--rows` supports 1,000–500,000;
this is deliberately separate from the application database. A local run on
20,000 generated profiles and 20,000 messages produced these median times
across five warm serial samples:

| Database operation | Previous query pattern | Optimized pattern |
|---|---|---|
| Conversation history read vs membership existence check | 57.761 ms | 0.300 ms |
| Nearby provider filtering | 44.590 ms | 2.002 ms |

The bounded geographical query used `idx_teacher_profiles_location` with
range access and an estimate of 219 candidate rows. These are local query
measurements, not full API latency, production benchmarks or concurrent-user
capacity estimates. Results vary with data distribution and hardware.

To compare the complete old and current nearby SQL in a dense location:

```bash
API_BENCHMARK_DATABASE=nearby_performance_test php tests/benchmarks/api.php --rows=20000 --dense
```

The output includes `full_nearby_before`, `full_nearby_after` and
`full_nearby_plan`. With all 20,000 generated teacher profiles at the same
location, a local run measured a median of 137.859 ms before and 107.620 ms
after, across five warm serial SQL samples. The new candidate scan used the
covering location index, and user details were joined by primary key after
shortlisting. This fixture contains teachers only; mixed provider types and
filters are covered by functional tests. These figures exclude HTTP overhead
and do not predict production response times. Dense areas still require
calculating distance for matching candidates.

Before committing to a traffic capacity, run load tests against a staging
deployment with representative provider/message density and concurrent users.
Measure p95/p99 latency, database examined rows/slow queries, CPU/memory and
queue wait time. Use `EXPLAIN ANALYZE` on the real data distribution to check
query plans. This is separate from functional/regression testing; a passing
test suite does not prove support for 20 lakh concurrent users.
