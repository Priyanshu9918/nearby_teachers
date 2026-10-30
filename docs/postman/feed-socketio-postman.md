# Feed Socket.IO in Postman

Share this guide with the app team to connect to the feed Socket.IO service,
join a post, and try live comments, replies and likes. Feed records are still
saved by Laravel; Socket.IO sends actions to Laravel and broadcasts committed
changes to clients in the same post room.

## Before connecting

- Use a valid Laravel login bearer token for an active account.
- Get an existing `postId` from the feed API. For comment-like tests, also get
  a `commentId` on that same post.
- Use the Socket.IO host and port for the environment you are testing. This is
  separate from the Laravel REST API URL. If port `6001` is publicly exposed,
  the URL looks like `ws://<SOCKET_HOST>:6001`; if the service is behind TLS
  reverse proxy, use its `wss://` URL.

## Create and connect in Postman

1. Choose **New → Socket.IO**.
2. Enter the Socket.IO URL for your environment.
3. In **Settings**, set **Handshake path** to `/socket.io/` and the client
   version to `4`.
4. In **Headers**, add `Authorization` with value `Bearer <Laravel login token>`.
5. Click **Connect**. A successful connection means the Node service accepted
   the token through Laravel's chat socket authorization endpoint.

Do not use the internal Socket.IO shared secret as the app token. App clients
may send the same bearer as `auth.token`; in Postman, use the handshake header.

## Check the connection

With the socket connected, send event `chat.ping`, add one JSON argument, and
enable **Ack**:

```json
{}
```

Expected acknowledgement:

```json
{
  "ok": true,
  "serverTime": "2026-10-30T12:00:00.000Z"
}
```

This confirms the authenticated Socket.IO round trip. It does not check the
database or queue worker.

## Join a feed post

Send `feed:join` with the event argument and **Ack** enabled:

```json
{
  "postId": 123
}
```

Expected acknowledgement:

```json
{
  "ok": true,
  "room": "feed-post.123"
}
```

Join must succeed before sending comments, replies or likes. The server checks
that the post exists and adds this socket to its private room. Use a real
numeric post ID; do not send the actor's user ID, name or account type because
the server derives those values from the authenticated token.

## Listen for live feed updates

Before sending actions, add these event names in Postman's **Events** panel:

- `feed:comment.created`
- `feed:post.like_changed`
- `feed:comment.like_changed`

The server broadcasts an event only after Laravel commits a new comment or a
like-state change. Example payloads:

```json
{
  "postId": 123,
  "comment": {
    "id": 456,
    "post_id": 123,
    "user_id": 7,
    "parent_comment_id": null,
    "body": "Hello from Postman",
    "author_name": "Example Learner",
    "author_type": "learner",
    "like_count": 0,
    "likedByMe": false,
    "likes": []
  }
}
```

```json
{
  "postId": 123,
  "user": { "user_id": 7, "name": "Example Learner", "type": "learner" },
  "liked": true,
  "likeCount": 4
}
```

The `type` value is `learner`, `teacher` or `institute`. Comment-like updates
also include `commentId`. The client can update the matching likes list using
`user.user_id` and the new `likeCount`; `liked` tells whether this actor added
or removed their like.

## Send feed actions

Set the event name, add the JSON object as its single argument, and enable
**Ack** for each request. Replace the example IDs with records from your test
environment.

| Event | Argument |
|---|---|
| `feed:comment` | `{"postId":123,"body":"Hello from Postman"}` |
| `feed:reply` | `{"postId":123,"parentCommentId":456,"body":"Reply from Postman"}` |
| `feed:post.like` | `{"postId":123}` |
| `feed:post.unlike` | `{"postId":123}` |
| `feed:comment.like` | `{"postId":123,"commentId":456}` |
| `feed:comment.unlike` | `{"postId":123,"commentId":456}` |
| `feed:leave` | `{"postId":123}` |

Successful ACKs have `ok: true`. Like ACKs include `liked` and `likeCount`;
comment/reply ACKs include the saved `comment`. On an error, the ACK has
`ok: false`, `status`, and `error` (and may include `errors`). Comment and reply
bodies must contain 1–5,000 characters. Repeated likes/unlikes are safe to
retry, and a repeated no-op does not broadcast a duplicate like change.

After `feed:leave`, join the post again before sending more actions. On
reconnect, join the post room again and reload `GET /feed/{postId}/comments`
to retrieve the saved comments/replies, current like counts, liker names/types
and the authenticated user's `likedByMe` state.

## Save and share the request

In Postman, choose **Save → New Collection** and name it
`Nearby Teachers — Feed Socket.IO`. Save the Socket.IO request and save the
event messages for reuse. Keep it separate from
[`feed-realtime.postman_collection.json`](feed-realtime.postman_collection.json),
which contains HTTP authorization/read/fallback requests and cannot send
Socket.IO events. Postman multi-protocol collections are saved in the Postman
workspace and cannot be exported as a standard collection JSON, so share this
Markdown guide with app developers and have them save the request in their
Postman workspace.

See the full [feed event contract](../FEED_SOCKET_IO.md) and the
[chat Socket.IO Postman guide](chat-socketio-postman.md) for shared connection
and ping details.
