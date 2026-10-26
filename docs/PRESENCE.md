# App activity and provider presence

`POST /presence/update` reports the authenticated user's foreground device
activity. Teacher and institute search results expose the boolean `isActive`.
This is a short-lived, client-reported availability hint; it does not prove
attendance, teaching availability, or that a person is looking at the screen.

## API

```http
POST /presence/update
Authorization: Bearer <user login token>
Accept: application/json
Content-Type: application/json

{"deviceId":"app-device-unique-id","event":"foreground"}
```

Both fields are required. `deviceId` is a stable installation/browser-tab
identifier of 8–128 ASCII letters, digits, hyphens or underscores. Use different
IDs for independently foregrounded clients. Do not send a push token, device
secret, hardware identifier or bearer as the device ID. The backend hashes it
and scopes it to the bearer user and current authentication version. `userId`,
`isActive` and `lastActiveAt` cannot be supplied as overrides. User identity and
timestamps come from the server. The existing phone verification and suspension
gates apply; learner, teacher and institute accounts may use this API.

| Event | When the client sends it | Effect |
| --- | --- | --- |
| `foreground` | Real app open/resume into foreground | Opens this device and starts its activity and heartbeat clocks |
| `activity` | A real tap, scroll, typing action, search or other interaction | Refreshes this device's activity and heartbeat clocks |
| `heartbeat` | Every 30 seconds while foregrounded | Refreshes heartbeat only; never resets the inactivity clock |
| `background` | App loses foreground / normally closes | Removes this device immediately; repeated background is harmless |

Success response is the aggregate account status, not just this device:

```json
{"isActive":true}
```

A device is active only while **both** conditions hold:

- Last real foreground/activity event is **less than 300 seconds** old.
- Its heartbeat is **less than 90 seconds** old.

At the exact boundary the device is inactive. Any active device makes the
account active. Backgrounding one device cannot mark another active device
offline. Activity from one device cannot combine with another device's
heartbeat. App crash, force-close or loss of connectivity expires within 90
seconds after the last accepted heartbeat/activity; a close event is best effort.
No cleanup scheduler or Socket.IO connection is required for correctness.

Heartbeats may continue while the app is idle in foreground; after five minutes
they must still return `isActive:false`. **Do not send `activity` on timers,
polling, incoming notifications, automatic API calls or socket pings.** Coalesce
frequent real interaction events (for example, one report per 15 seconds with a
trailing report for the latest actual interaction). The idle window uses server
receipt time; reporting delay affects its precision.

Serialize requests for each device and cancel pending heartbeat/activity work
on background. A late heartbeat/activity cannot reopen a removed or expired
device; it returns `409 PRESENCE_FOREGROUND_REQUIRED`. Reinitialize on the next
actual open/resume or interaction. A heartbeat-only retry must not send repeated
foreground events to keep an unattended app active. On `503`, back off rather
than retrying in a tight loop. A fresh foreground event can recover a device
after Redis state loss when the client next becomes active.

## Search integration

All endpoints returning individual providers add `isActive` to each existing
result: `/search`, `/search/dashboard`, `/search/other-city` and every provider
result tier of `/search/by-subject/{subjectId}`. Counts and country aggregates
keep their existing shapes. Inactive providers remain included, with the same
filters, ranking and pagination behavior. Presence-bearing responses use
`Cache-Control: no-store`; clients need to refresh search to see newer status.
There is no live presence subscription/push event or separate arbitrary-user
presence lookup.

```json
{
  "results": [
    {"profile_type":"teacher","user_id":25,"full_name":"Example Teacher","isActive":true},
    {"profile_type":"institute","user_id":26,"full_name":"Example Institute","isActive":false}
  ]
}
```

No heartbeat records means `false`. Disabled presence or Redis outage also
returns `false` in otherwise successful searches, meaning active status cannot
be confirmed. Presence updates return `503` during an outage/when disabled.
Suspended, unverified or incomplete providers never get an active flag.
Logout/password reset changes the authentication version; search reads current
versions so stale-device keys cannot keep an old login active. Old keys expire.
No device IDs, internal timestamps or authentication versions are exposed in
search responses. Mobile lifecycle and interaction hooks must be added in the
mobile application, which is outside this repository.

## Errors and limits

| Status | Meaning |
| --- | --- |
| `401` / `403` | Existing bearer, verification or suspension access checks |
| `422` | Invalid/missing device/event, or attempted server-owned field override |
| `409`, `PRESENCE_FOREGROUND_REQUIRED` | Device was never opened, backgrounded or lost its fresh heartbeat |
| `429`, `PRESENCE_DEVICE_LIMIT` | Already 20 fresh foreground devices for this auth version; background an unused device or wait 90 seconds |
| `429` | More than 120 presence requests/minute per authenticated user |
| `503`, `PRESENCE_UNAVAILABLE` | Disabled or Redis unavailable |

## Redis, deployment and performance

Presence is disabled by default so existing installations without Redis keep
working. Provision a private Redis instance/connection, install locked Composer
dependencies, then set these values in the server's existing `.env`:

```dotenv
PRESENCE_ENABLED=true
PRESENCE_REDIS_URL=redis://127.0.0.1:6379/2
PRESENCE_REDIS_DB=2
```

Set the URL to the actual service; include deployment credentials through secret
configuration as required. If `PRESENCE_REDIS_URL` is omitted, the connection
uses `REDIS_URL` or `REDIS_HOST`/`REDIS_PORT`/credentials. The URL's database can
override the separate DB setting. It is independent of the Socket.IO adapter's
`SOCKET_IO_REDIS_URL`. Do not change queue/cache/session drivers just to enable
presence. Configure shared rate-limit storage when deploying multiple app nodes.

The locked Predis client supports PHP installations without the Redis extension.
The default selects PhpRedis if available, otherwise Predis; an explicit
`REDIS_CLIENT` setting still takes precedence. Use the intended client on all
nodes (see [Laravel Redis configuration](https://laravel.com/framework/docs/11.x/redis)).
Then rebuild cached configuration through the normal deployment process:

```bash
composer install --no-interaction --prefer-dist
php artisan config:cache
```

No new SQL migration is required. No persistent online flag is written to users
or profiles. One atomic Lua operation updates bounded device state; per-user
Redis hash/sorted-set keys expire after 600 seconds without updates. Background
removes only its device. Lua keys share a Redis Cluster hash tag. Search batches
current user versions from MySQL and pipelines Redis status reads in groups of
500, avoiding per-result SQL/network round trips. The existing unbounded map
dashboard is still unbounded; this feature does not establish traffic capacity.
Keep clocks synchronized across app nodes and monitor Redis latency/memory and
presence update failures. Redis ACLs must permit the used hash/sorted-set,
expiry and Lua commands on this application namespace. Private infrastructure
and load testing remain part of deployment.

## Reporting coverage

Presence is ephemeral operational telemetry, like typing hints. No business
record changes on a presence update, and no business-history event is emitted
per heartbeat, automatic expiry or background. Existing registry reports and
append-only business history are unchanged. **Historical online duration,
last-seen history and past active counts are not retained or reconstructible.**
If historical availability reports are required, define approved aggregates,
sampling semantics and retention separately; do not infer offline events from
missing heartbeats, fabricate past state or append raw device/request/token
payloads to business history. See [reporting architecture](REPORTING_ARCHITECTURE.md).

## Tests

`PresenceTest` requires a disposable MySQL database ending in `_test` and an
isolated Redis instance; it uses random test key prefixes and never `FLUSHDB`.

```bash
ONBOARDING_TEST_DATABASE=nearby_presence_test \
PRESENCE_TEST_REDIS_HOST=127.0.0.1 PRESENCE_TEST_REDIS_PORT=16389 \
php artisan test --filter=PresenceTest
```

Tests advance server time rather than sleeping five minutes. They cover exact
idle/heartbeat boundaries, background and delayed events, independent devices,
auth/access and server-owned input validation, bounded keys, all provider search
shapes/order, version/suspension invalidation, bulk queries, outages, disabled
mode and the absence of persistent business mutations. CI supplies its own
MySQL and Redis services. Application data is never migrated/cleared for tests.

Verified on 26 October 2026: full suite on a fresh disposable database passed
208 tests / 5,503 assertions, including 14 presence tests / 347 assertions.
Socket.IO's 15 tests and the three developer UI/deployment test files passed.
The production dependency audit still reports the pre-existing Laravel framework
advisories; it returned no advisory for the added Predis dependency.
