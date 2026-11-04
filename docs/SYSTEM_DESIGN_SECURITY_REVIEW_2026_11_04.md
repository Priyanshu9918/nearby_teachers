# Repository review — 4 November 2026

Scope: Laravel routes, authentication, public profiles/search/feed, geography,
reporting, queues, Socket.IO bridge, deployment workflow and selected tests.
This is a source review plus targeted tests, not a production penetration test,
load benchmark or dependency advisory scan. No production data was modified.

## Assessment

The Laravel modular monolith with a separate Socket.IO transport is a reasonable
foundation. Shared reporting services, explicit report columns, business event
allowlists, transactional OTP consumption and after-commit delivery are useful
boundaries. Retain this structure; first make eligibility policies and public
response contracts consistent, then measure expensive reads. Splitting into
microservices would not resolve the issues below.

## Findings, ordered by priority

### 1. High: public profile/search eligibility differs across endpoints

`app/Http/Controllers/Api/ProfilesController.php:387` fetches a profile by user ID
without checking target account type, suspension, phone verification or publication.
The route permits anonymous reads. `SearchController.php:127` similarly selects
nearby candidates without the account eligibility conditions used by
`SearchController.php:38`. `counts()` counts profiles without joining eligible
users. A suspended/incomplete provider with a stored profile/location can remain
retrievable through these paths even though dashboard excludes it.

Create one reusable eligibility query/policy based on the actual product's
publication rules, and apply it to detail, search, counts and SEO readers.
Owner/admin preview should be explicit. Add tests for suspended, draft,
unverified, mismatched-account and unpublished profiles on each reader.

### 2. Medium: public responses use subtraction instead of field allowlists

`ProfilesController.php:413` selects `p.*`, then unsets a handful of fields.
`AuthenticationService.php:43` returns the complete user row after removing
password_hash and parental_consent_token; login/OTP/Google responses serialize
that object. Authenticated users receiving their own fields is not evidence of
cross-account compromise, but internal fields and newly added columns can become
response fields automatically. `/auth/session` already uses an explicit field
list, illustrating the inconsistency.

Use separate allowlisted public-profile and session/login serializers. Keep
internal authenticated-user state separate from the serialized API representation.
Test response keys, including when new private columns are introduced.

### 3. Medium: bounded post pages contain unbounded like lists

`CommunityController.php:58` loads every liker for all posts in a page;
`CommunityController.php:224` uses the same pattern for comment likes. Cursor
pagination limits parent records but does not bound these nested arrays. A viral
post can still cause large responses and high PHP memory usage.

Return counts, liked_by_me and a small preview. Put the complete liker list behind
a separate bounded endpoint. Also review write/read throttles on feed routes:
several public reads and authenticated content writes have no route throttle.
No blanket API throttle is installed in `bootstrap/app.php`.

### 4. Medium: geography pagination contract is broken

`GeographyController.php:43` validates state/search only, then returns every
matching city. `page`/`perPage` are ignored despite the docs and feature test
expecting bounded pages. Reproduced: `perPage=1` returns four fixture cities.

Restore bounded pagination and validation, align Postman/docs, and run
GeographyApiTest in CI. Country/state lists can stay small reference lists;
city results need the documented response contract.

### 5. Medium: dashboard loads all matching providers before sorting

`SearchController.php:56` materializes every teacher/institute within a radius
(up to 500 km), then bulk-loads subjects and sorts the result in PHP. Nearby
search already limits candidates to 50, showing an available bounded pattern.

Bound dashboard results in SQL. If a map must represent all matching providers,
return clusters or viewport aggregates plus a bounded detail page; compute counts
separately. Measure EXPLAIN ANALYZE, rows examined, peak PHP memory and response
size against realistic density before selecting indexes or cache lifetimes.

### 6. Medium: analytics write failure can fail otherwise successful searches

`app/Reporting/SearchAnalyticsRecorder.php:22` inserts synchronously with no
failure isolation. Search endpoints call it before returning results. A missing
analytics migration or failed analytics write therefore propagates to users.
`TrackAuthActivity` already catches reporting write failures, but search does not.

Define which analytics can be best-effort, isolate failures and alert on loss.
For guaranteed collection, design a durable delivery mechanism explicitly.
Do not weaken transactional business activity recording for source mutations.

### 7. Medium: administrative mutations bypass business activity history

`AdminController.php:105`, `:144`, `:186` create/update/delete users, profiles or
subjects without calling BusinessActivityRecorder. This allows changes through
the generic admin UI that cannot be reconstructed through the business history
contract. Hard deletes may also remove dependent source records according to the
active schema; this review did not inspect production foreign keys.

Record allowlisted, versioned events in the same transaction as each mutation.
Define archival/deletion semantics and test rollback plus history survival.
Record changed-field names and approved state, not raw requests or credentials.

### 8. Medium: CI has known failing tests and geography is not enabled

Observed test failures:

- Developer portal: 0/8 pass. Its VM fixture replaces catalog/categories Blade
  directives, but does not replace the newer `@js($workflows)` expression at
  `resources/views/developer/portal.blade.php:254`, causing a syntax error in the
  fixture. This does not establish a production browser JavaScript failure.
- Deployment tests: 4/6 pass. Failure-path expectations still require broadcasts,
  notifications and chat services to be adjacent; deploy.sh now includes push.
  This is an expectation mismatch, not evidence that workers stay running.
- Geography: 4/5 pass using a root APP_URL, with the pagination failure above.
- `.github/workflows/deploy.yml:46` sets several feature database variables but
  omits GEOGRAPHY_TEST_DATABASE, so geography tests skip in the current CI setup.
  WorldGeographyImportTest requires empty dedicated tables and should get its own
  disposable database/job instead of sharing fixture schemas indiscriminately.

Repair fixtures and failure-path assertions, provide isolated test databases,
and make unexpected skips visible. Keep deployment gated on the corrected suite.

## Verification performed

- PHP unit suite: 35 tests, 126 assertions, all pass.
- Socket.IO suite: 19 tests, all pass. Initial sandbox loopback EPERM failures
  were environmental; rerun with local socket access passed.
- Developer chat interaction file passed in the combined Node run.
- Developer portal: eight fixture syntax failures, as described above.
- Deployment: four pass, two failure-path assertion failures.
- Geography API: four pass, one pagination failure; disposable
  `nearby_world_geography_test` only. Root `APP_URL=http://localhost` is required
  for this local test run; the installation's subdirectory URL initially caused
  unrelated 404s. Tests roll back fixture rows; created test tables remain.

## Operational checks still needed

No production latency, throughput or maximum-user count can be established from
this review. Benchmark representative search/feed/chat traffic on staging and
capture p50/p95/p99 latency, error rate, SQL time, rows examined, memory, queue age
and worker retries. Set explicit service objectives before sizing servers.

Verify actual production HTTPS/cookie/proxy settings, APP_DEBUG=false,
APP_ENV=production, document root restricted to public/, database privileges,
shared cache/rate-limiter configuration for multiple instances, backup restoration,
scheduler/queue supervision and dependency advisories. Workflow fallback URLs use
HTTP; these defaults do not prove the live deployment uses HTTP. No secret values
or production environment contents were exposed during this review.

For growth, keep stateless PHP instances, use a shared limiter/session cache,
scale queue workers by workload, and use the existing Redis Socket.IO adapter
when running multiple chat processes. Plan database replication/search services
only when measurements justify the added consistency and operational complexity.

Recommended order: fix public visibility and serializers; repair CI contracts;
bound nested/feed/dashboard/geography responses; complete admin history and
analytics resilience; then stage load testing and production configuration checks.
