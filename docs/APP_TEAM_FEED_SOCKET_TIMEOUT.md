# App team handoff: Feed Socket.IO timeout

The log `Feed Socket.IO connect error: timeout` is raised while opening the
Socket.IO connection. It happens before `feed:join`, likes or comments run, so
increasing an event acknowledgement timeout will not fix this connection error.

## Backend connection details

- Laravel REST API base URL is
  `http://157.245.125.79/nearby_teachers/public/`. Socket.IO uses a separate
  origin; do not append `/nearby_teachers/public` to the Socket.IO URL.
- Deployed Socket.IO origin is `http://157.245.125.79` with path
  `/socket.io/`. Apache proxies that path to Node on `127.0.0.1:6001`.
- Socket.IO path is `/socket.io/` (the Socket.IO default). The service uses
  Socket.IO protocol v4; a plain WebSocket client cannot connect to it.
- Port `6001` is internal and direct external connections are refused by
  design. Do not configure the mobile app to connect to `:6001`.
- The deployed HTTPS endpoint presents a self-signed certificate. Use the
  working HTTP origin above for this deployment; do not disable certificate
  validation. The app can switch to `https://` after a valid TLS certificate
  is installed.

## Backend smoke check (31 Oct 2026)

- Laravel `/health`, `/subjects` and read-only `/feed?city=Delhi&perPage=1`
  returned HTTP 200.
- Socket.IO Engine.IO handshake at `http://157.245.125.79/socket.io/` returned
  HTTP 200. A Socket.IO client with a deliberately invalid bearer received
  `UNAUTHORIZED` promptly, confirming the public proxy and Laravel auth path
  respond instead of timing out.
- Direct external access to port `6001` was refused; the app must use the
  Apache proxy origin/path above.
- A valid-token `chat.ping` and feed mutation were not sent, so production like
  and comment writes were not changed by this check.

Use the Socket.IO origin supplied for the app environment. Examples:

| Environment | Socket.IO origin | Path |
|---|---|---|
| Deployed app | `http://157.245.125.79` | `/socket.io/` |
| Local emulator with local Node process | `http://10.0.2.2:6001` | `/socket.io/` |

`127.0.0.1` in the Android app means the phone itself. For an Android emulator,
`10.0.2.2` refers to the development computer; physical phones need a reachable
LAN/server address.

## Flutter connection

Use the Socket.IO Dart client, not `WebSocketChannel` or a raw WebSocket. The
backend runs Socket.IO 4.8.x; `socket_io_client` 3.x supports Socket.IO server
4.7 and later. Check the resolved version in `pubspec.lock`; upgrade from the
2.x client if needed. In native Flutter, select the WebSocket transport and
register listeners before calling `connect()`.

```dart
import 'package:socket_io_client/socket_io_client.dart' as IO;

IO.Socket createFeedSocket(String socketOrigin, String bearerToken) {
  final socket = IO.io(
    socketOrigin, // deployed: http://157.245.125.79
    IO.OptionBuilder()
        .setTransports(['websocket'])
        .setExtraHeaders({'Authorization': 'Bearer $bearerToken'})
        .disableAutoConnect()
        .enableReconnection()
        .build(),
  );

  socket.on('connect_error', (error) {
    // Log the error and its code/message only. Never log bearerToken.
    print('Socket connect_error: $error');
  });
  socket.on('disconnect', (reason) {
    print('Socket disconnected: $reason');
  });
  socket.onConnect((_) {
    print('Socket connected: ${socket.id}');
  });

  socket.connect();
  return socket;
}
```

The backend accepts the Laravel bearer as the handshake
`Authorization: Bearer ...` header (it also accepts `auth.token`). Never send
`SOCKET_IO_SHARED_SECRET` to the app; it is only for server-to-server events.

## Verify in order

1. Confirm the app has a valid, current Laravel bearer token and the exact
   Socket.IO origin for this environment.
2. Confirm the Socket.IO service is reachable from the phone's network. The
   backend operator checks the service and `/health`; the app then waits for
   `connect` and records any `connect_error`/`disconnect` reason without
   recording credentials.
3. Once connected, send one diagnostic ping and inspect its ACK:

   ```dart
   socket.emitWithAck('chat.ping', {}, ack: (response) {
     print('Socket ping ACK: $response');
   });
   ```

   Expected: `{ok: true, serverTime: ...}`. This confirms the authenticated
   Socket.IO round trip; it does not test the feed database.

4. On opening a post and after every reconnect, join its room and wait for a
   successful ACK before emitting feed actions:

   ```dart
   socket.emitWithAck('feed:join', {'postId': postId}, ack: (response) {
     if (response is Map && response['ok'] == true) {
       // The post room is ready.
     } else {
       print('Feed join failed: $response');
     }
   });
   ```

5. Use the existing event names and await the ACK for each mutation:

   | Action | Event | Payload |
   |---|---|---|
   | Add comment | `feed:comment` | `{'postId': postId, 'body': body}` |
   | Reply | `feed:reply` | `{'postId': postId, 'parentCommentId': commentId, 'body': body}` |
   | Like post | `feed:post.like` | `{'postId': postId}` |
   | Unlike post | `feed:post.unlike` | `{'postId': postId}` |
   | Like comment | `feed:comment.like` | `{'postId': postId, 'commentId': commentId}` |
   | Unlike comment | `feed:comment.unlike` | `{'postId': postId, 'commentId': commentId}` |

   For example:

   ```dart
   socket.emitWithAck('feed:post.like', {'postId': postId}, ack: (response) {
     print('Post like result: $response');
   });
   ```

   A successful action ACK contains `ok: true`; an error ACK contains
   `ok: false`, `status` and `error`. Do not send user ID, name or account type:
   Laravel derives the actor from the bearer token.

6. Listen for `feed:comment.created`, `feed:post.like_changed` and
   `feed:comment.like_changed`. Deduplicate/upsert comments by comment ID and
   update likes using the server-provided count. After reconnect, rejoin the
   open post and reload `GET /feed/{postId}/comments` to recover any missed
   updates.

## What to send backend if connect still times out

Send the environment, timestamp/time zone, app version, Socket.IO client
version, exact Socket.IO origin and path, and the `connect_error` message/code.
Do not send access tokens. Backend operations should verify the service is
active, port/proxy is reachable from an external network, and the listener is
bound to the interface used by the configured endpoint. The REST feed APIs
remain available while Socket.IO is unavailable.

References: [feed event contract](FEED_SOCKET_IO.md),
[Socket.IO operations](CHAT_SOCKET_IO.md),
[`socket_io_client` Flutter setup](https://pub.dev/packages/socket_io_client),
[Socket.IO path and transport options](https://socket.io/docs/v4/client-options/).
