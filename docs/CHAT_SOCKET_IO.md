# Socket.IO chat: Laravel + mobile

Laravel owns users, permission checks, conversation/message persistence and
REST history. The Node.js Socket.IO 4 server delivers live events. Reverb is
not used by this chat connection. The existing chat event name and message
payload remain `message.sent` and `{message}`.

## Setup

Requirements: Node.js 22+, the existing Laravel/MySQL setup, and a queue worker.

```bash
npm ci --prefix realtime
php artisan config:clear
```

The workspace is already configured. On another deployment set:

```dotenv
BROADCAST_CONNECTION=socketio
QUEUE_CONNECTION=database
SOCKET_IO_INTERNAL_URL=http://127.0.0.1:6001
SOCKET_IO_LARAVEL_URL=http://127.0.0.1:8000
SOCKET_IO_SHARED_SECRET=your_random_server_only_secret_at_least_32_characters
SOCKET_IO_HOST=127.0.0.1
SOCKET_IO_PORT=6001
SOCKET_IO_CORS_ORIGINS=http://localhost:8000,http://127.0.0.1:8000
SOCKET_IO_REDIS_URL=
```

Generate the shared secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.
Use the same value in Laravel and Node. Do not put it in the mobile app.
Node reads the project's `.env`; environment variables can override the file.
`SOCKET_IO_LARAVEL_URL` must reach the Laravel API from Node. Laravel's
`SOCKET_IO_INTERNAL_URL` must reach the Socket.IO process from queue workers.

Start each in its own terminal/process:

```bash
php artisan serve --host=0.0.0.0 --port=8000
npm run chat:start
php artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30
```

Do not start Reverb. Restart queue workers after changes with
`php artisan queue:restart`; restart the Node process after Socket.IO env/code
changes. Keep these processes alive with your deployment process manager.
`composer run dev` starts Laravel, the queue listener, logs and Socket.IO for
local development. Health check: `GET http://127.0.0.1:6001/health`.

For a physical phone on your LAN, set `SOCKET_IO_HOST=0.0.0.0`, restart Node,
and connect to `http://YOUR_COMPUTER_LAN_IP:6001`. Android emulator uses
`http://10.0.2.2:6001`. The Socket.IO public URL is separate from the REST
API URL on port 8000. Browser origins must be listed in
`SOCKET_IO_CORS_ORIGINS`; native clients without an Origin header are allowed
but still must authenticate.

## Mobile JavaScript / React Native

### Developer portal live tester

Open `/developer#realtime` after developer login. Enter the public Socket.IO
origin, its path (normally `/socket.io/`), and a real participant's login token.
For direct Node access use its reachable host and port; the portal defaults to
the page's origin for deployments with a reverse proxy. Allow the portal's
browser origin in `SOCKET_IO_CORS_ORIGINS`.

Connect, then use **Ping / round trip**, **conversation.join**,
**conversation.leave**, and **message.send**. Sending saves a real message.
The bounded log shows acknowledgements, timing, `message.sent`, connection
errors and server-triggered `session.expired`. Expiry is observed, not emitted
by the tester. Sessions expire within 15 minutes; reconnect with a valid token.
Use two tabs with the learner/provider tokens to check actual live delivery.
If sending acknowledges but no live event arrives, check the broadcasts worker.
ACK timeouts are ambiguous; inspect REST history before resending.

`chat.ping` is an optional authenticated Socket.IO diagnostic event. Emit `{}`
with an acknowledgement callback; response is
`{ok:true,serverTime:"ISO-8601 timestamp"}`. It shares the socket's limit of
30 operations per 10 seconds and returns `{ok:false,status:429,error}` when
limited. It confirms Node round-trip connectivity, not database or queue health.
App clients can use it for diagnostics; normal Socket.IO heartbeats already
monitor the transport.

**Copy app connection example** copies public URL/path with a token placeholder.
Tokens stay in page memory and are not included in the copied example.
The browser client is vendored under `public/vendor/socket.io/` (MIT license),
so the tester does not require an external CDN or production dev dependencies.

### App example

Install `socket.io-client` in the mobile project. Use a Socket.IO 4 compatible
client (Flutter can use its compatible Socket.IO client package).

```js
import { io } from 'socket.io-client';

const socket = io('http://YOUR_SERVER:6001', {
  auth: { token: laravelBearerToken },
  transports: ['websocket'],
  autoConnect: false,
  reconnection: true,
});

// Register listeners before connecting. Server manages your private user room,
// so new messages also arrive while viewing another screen in the open app.
socket.on('message.sent', ({ message }) => {
  // Deduplicate by message.id, then update chat / unread indicator.
});
socket.on('connect_error', (error) => {
  // error.data.code is UNAUTHORIZED or AUTH_UNAVAILABLE.
  // Refresh/login if needed; update socket.auth.token before reconnecting.
});
socket.on('disconnect', (reason) => {
  if (reason === 'io server disconnect') socket.connect();
});
socket.on('connect', () => {
  // Restore history and unread state via REST after every reconnect.
  // Rejoin the currently viewed conversation if there is one.
});
socket.connect();

socket.timeout(7000).emit('conversation.join', { conversationId }, (err, ack) => {
  if (err || !ack.ok) return; // Handle timeout, forbidden or unavailable API.
});

socket.timeout(7000).emit('message.send', {
  conversationId,
  body: 'Hello!',
}, (err, ack) => {
  if (err || !ack.ok) return;
  // ack.message is persisted by Laravel. The same ID arrives in message.sent.
});

socket.emit('conversation.leave', { conversationId });
// On logout: socket.disconnect(); remove listeners and discard the bearer.
```

Connect before emitting; in real UI code run the join/send calls from the
connected handlers/user actions. Only conversation participants can join or
send. A user cannot join someone else's private user room. `message.send`
forwards the bearer and body to Laravel's existing send endpoint; clients
cannot forge broadcasts. REST `POST /messages/{conversationId}` still works
and causes the same live delivery. Start/reuse a conversation with REST
`POST /messages/start` and load history with cursor-paginated
`GET /messages/{conversationId}`.

Acknowledgements use `{ok:true, message}` for sending, `{ok:true, room}` for
joining, or `{ok:false, status, error, errors?}`. Local per-connection limits
and Laravel per-bearer limits protect joins/sends. Blank or >5,000-character
messages are rejected. Safety/guardian-consent checks remain in Laravel.

## Delivery and session behavior

Messages are committed to MySQL before publishing. The broadcasts worker
calls the private `/internal/events` HTTP bridge with a server-only secret.
If delivery fails, the Laravel job retries. An acknowledgement confirms
persistence, not that the other device has read or received the event.
Both participants' connected devices receive the event. A device subscribed
to both its user room and conversation room receives one packet per publish.
Queue retries can still duplicate an event: deduplicate by message ID.

Socket sessions last at most 15 minutes, then emit `session.expired` and
disconnect. Reconnect to rerun Laravel authorization; refresh the bearer if
it has expired. Authorization is checked again on each room join and send.
After a reconnect, recover messages using REST. Offline/closed apps require
FCM/APNs for operating-system push notifications; Socket.IO is live delivery
for a connected app, not an OS push service.

Password resets increment the account's authentication version. Earlier
bearers stop working immediately for API calls; the broadcasts worker also
disconnects sockets with older versions. Freshly logged-in sockets are kept.
After a reset, log in again with email/password before reconnecting.

## HTTPS and multiple servers

Expose `/socket.io/` through your TLS reverse proxy with WebSocket Upgrade
headers. For example, inside an existing HTTPS Nginx server block:

```nginx
location /socket.io/ {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 75s;
}
location /internal/ { return 404; }
```

The internal bridge should only be reachable from trusted queue workers;
keep the listener on loopback or a private network. It requires the secret
even on that network. Use HTTPS/private networking across machines.

For multiple Socket.IO instances, set the same `SOCKET_IO_REDIS_URL` on each
instance and the same shared secret; the Redis adapter is already included.
Queue workers can publish to any healthy Socket.IO instance. If Redis is
unavailable the internal bridge/health check returns 503 so jobs retry.
Use WebSocket-only clients to avoid long-polling affinity requirements.
If enabling polling across multiple servers, configure sticky sessions.
Monitor connected sockets, queue lag and Redis/database load; scale based
on measured concurrent traffic.

Protocol references: [Socket.IO middleware](https://socket.io/docs/v4/middlewares/),
[rooms/server API](https://socket.io/docs/v4/server-api/), and
[Redis adapter](https://socket.io/docs/v4/redis-adapter/).

## Tests

```bash
npm run chat:test
ONBOARDING_TEST_DATABASE=nearby_onboarding_test php artisan test
```

Node integration tests open loopback sockets and exercise authentication,
membership, sending, private delivery, forged events, origin restrictions,
session expiry and unavailable-adapter handling. Laravel tests exercise
real MySQL membership checks and bridge payload/retry behavior.
