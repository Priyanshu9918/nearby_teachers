# Feed comments and likes over Socket.IO

Laravel remains the source of truth for feed posts, comments, replies and likes.
The Socket.IO service authenticates users, accepts feed actions, and delivers
committed updates to clients that joined the same post room. REST feed and
comment reads remain the recovery path after reconnecting.

## Connect and ping

The Socket.IO public URL is separate from the Laravel REST base URL. Use the
Socket.IO host and port configured for your deployment (local default:
`http://127.0.0.1:6001`) and the Socket.IO path `/socket.io/`:

```js
import { io } from 'socket.io-client';

const socket = io('http://YOUR_SOCKET_HOST:6001', {
  path: '/socket.io/',
  auth: { token: laravelBearerToken },
  transports: ['websocket'],
  autoConnect: false,
  reconnection: true,
});

socket.on('connect_error', (error) => {
  // error.data.code is UNAUTHORIZED or AUTH_UNAVAILABLE.
});
socket.connect();

const ping = await socket.timeout(7000).emitWithAck('chat.ping', {});
// { ok: true, serverTime: '2026-10-30T...' }
```

The connection handshake checks the bearer through the authenticated
`POST /chat/socket/authorize` endpoint. `chat.ping` is an authenticated
Socket.IO diagnostic; it does not write to the database. It shares the socket
limit of 30 operations per 10 seconds.

## Join a post and send actions

Join a post before sending a comment, reply, or like. The server checks that the
post exists through `POST /feed/socket/authorize`, then joins the private
`feed-post.{postId}` room. `postId` and `commentId` are the numeric IDs returned
by the API. The authenticated account's name, ID and type come from Laravel;
clients must not send actor identity.

| Socket.IO event | Payload | Behavior / REST equivalent |
|---|---|---|
| `feed:join` | `{ "postId": 12 }` | Authorize and join `feed-post.12`; `POST /feed/socket/authorize` |
| `feed:leave` | `{ "postId": 12 }` | Leave that post room |
| `feed:comment` | `{ "postId": 12, "body": "Hello" }` | Add a comment; `POST /feed/12/comments` |
| `feed:reply` | `{ "postId": 12, "parentCommentId": 34, "body": "Reply" }` | Add a reply under a comment on the same post; `POST /feed/12/comments` |
| `feed:post.like` | `{ "postId": 12 }` | Like the post; `POST /feed/12/like` |
| `feed:post.unlike` | `{ "postId": 12 }` | Remove the post like; `DELETE /feed/12/like` |
| `feed:comment.like` | `{ "postId": 12, "commentId": 34 }` | Like a comment or reply; `POST /feed/comments/34/like` with `postId` |
| `feed:comment.unlike` | `{ "postId": 12, "commentId": 34 }` | Remove the comment or reply like; `DELETE /feed/comments/34/like` with `postId` |

All commands acknowledge with `{ok:true,...response}` or
`{ok:false,status,error,errors?}`. Comment and reply bodies must contain 1–5,000
characters. Feed mutations are idempotent for repeated likes and unlikes.
Actions are rate limited to 30 socket operations per 10 seconds, with at most
four in flight.

```js
socket.on('feed:comment.created', ({ postId, comment }) => {
  // Add or upsert by comment.id. A reply has parent_comment_id set.
});
socket.on('feed:post.like_changed', ({ postId, user, liked, likeCount }) => {
  // Update the post's count and likes list using user.user_id.
});
socket.on('feed:comment.like_changed', ({ postId, commentId, user, liked, likeCount }) => {
  // Update the comment/reply count and likes list using user.user_id.
});

const joined = await socket.timeout(7000).emitWithAck('feed:join', { postId });
if (joined.ok) {
  await socket.timeout(7000).emitWithAck('feed:reply', {
    postId, parentCommentId, body: 'Thanks for the details.',
  });
}
```

Like update events include the actor `{user_id, name, type}` where `type` is
`learner`, `teacher` or `institute`, plus the current `likeCount`. A new
comment event includes the saved comment fields, author name/type, zeroed like
state and its `parent_comment_id` when it is a reply. Only committed mutations
are broadcast. A repeated like does not send a duplicate update.

On reconnect, join the open post room again and reload
`GET /feed/{postId}/comments` (and `GET /feed?city=...` for the feed cards) to
recover updates missed while disconnected. Those reads include the current
like counts, liker `{user_id,name,type}` lists and `likedByMe` state.

## Postman

Use the [Feed Socket.IO Postman guide](postman/feed-socketio-postman.md) to
connect, ping, join a post, listen for feed updates and try every feed event.
The importable [`feed-realtime.postman_collection.json`](postman/feed-realtime.postman_collection.json)
contains HTTP authorization checks, feed reads and REST fallback actions; it
cannot send Socket.IO events.
