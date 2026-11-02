# App team handoff: realtime chat, unread count and push

This is the mobile implementation contract for realtime chat updates, read
receipts, unread badges and operating-system push notifications.

## Required behavior

1. Connect Socket.IO after login using the Laravel bearer in `auth.token`.
2. Listen for new messages and upsert them by numeric message ID.
3. Send `chat:delivered` when the recipient client receives a message.
4. Send `chat:read` when the recipient actually views the message.
5. Update the app badge from realtime `chat:unread_count` packets.
6. Fetch the authoritative unread total after login and reconnect.
7. Register the device for FCM and handle foreground, background and terminated
   notification states.

All IDs are numeric. Do not model chat, message, user or campaign IDs as UUIDs.

## Socket.IO connection

Use the configured Socket.IO base URL and the default `/socket.io/` path.

```dart
final socket = IO.io(
  socketBaseUrl,
  IO.OptionBuilder()
      .setTransports(['websocket'])
      .setAuth({'token': laravelBearerToken})
      .disableAutoConnect()
      .build(),
);

socket.connect();
```

On logout or session expiry, remove listeners and disconnect the socket. Never
log the bearer token.

## Conversation lifecycle

Join the open conversation and leave it when the screen closes:

```dart
socket.emit('conversation.join', {'conversationId': conversationId});
socket.emit('conversation.leave', {'conversationId': conversationId});
```

Joining a conversation controls room membership. User-scoped realtime packets,
including unread totals, can still arrive while another screen is open.

## New messages

Listen to one primary event, preferably `chat:receive`:

```dart
socket.on('chat:receive', (packet) {
  final message = Map<String, dynamic>.from(packet['message']);
  upsertMessage(message); // deduplicate by message.id and revision
});
```

`message.sent`, `chat:receive` and `chat:new` are aliases for the same saved
message. If existing code listens to more than one, deduplicate by `message.id`
and ignore a lower `revision`.

To send through Socket.IO:

```dart
socket.emitWithAck('chat:send', {
  'conversationId': conversationId,
  'body': text,
}, ack: (response) {
  // Success: {ok: true, message: {...}}
  // Failure: {ok: false, status: 4xx/5xx, error: '...'}
});
```

REST fallback: `POST /messages/{conversationId}` with `body` and optional
`replyToId`.

## Delivered and read receipts

Only the recipient may acknowledge a message. The sender must never acknowledge
their own message.

```dart
socket.emit('chat:delivered', {
  'conversationId': conversationId,
  'messageId': messageId,
});

socket.emit('chat:read', {
  'conversationId': conversationId,
  'messageId': messageId,
});
```

Send `chat:read` when the message is visibly opened, not merely because the app
is connected or a notification arrived. Reading also records delivery if it was
not recorded earlier. Both operations are idempotent.

Listen for receipts and update the matching message:

```dart
socket.on('chat:delivered', (data) => applyReceipt(data));
socket.on('chat:read', (data) => applyReceipt(data));
```

Receipt data includes `conversationId`, `messageId`, `userId`, `deliveredAt`
and `readAt`.

## Realtime unread badge

After a new message is saved and after a read receipt is recorded, the backend
broadcasts the recipient's updated total unread count:

```json
{
  "conversationId": 123,
  "userId": 45,
  "unreadCount": 7
}
```

The Socket.IO server emits this object directly; there is no outer `data`
property.

```dart
socket.on('chat:unread_count', (packet) {
  final data = Map<String, dynamic>.from(packet);
  if (data['userId'] == signedInUserId) {
    setGlobalChatUnreadCount(data['unreadCount'] as int);
  }
});
```

The same conversation room contains both participants, so always check
`userId`. `unreadCount` is the global total across all conversations owned by
that user. It is suitable for the main chat/tab badge.

Fetch an authoritative total after login, socket reconnect and app resume:

```http
POST /chat/events/unread_count
Authorization: Bearer <token>
Content-Type: application/json

{}
```

Response:

```json
{
  "conversationId": null,
  "unreadCount": 7
}
```

To fetch one conversation's count, send `{"conversationId":123}`. Do not add
realtime counts locally because reconnects or duplicate events can drift; replace
the badge with the server value.

## Chat push notifications

The backend queues an FCM push for the other participant's active registered
devices after a message is committed. The sender does not receive that push.
The notification uses privacy-safe generic text:

```json
{
  "notification": {
    "title": "New message",
    "body": "You have a new message."
  },
  "data": {
    "campaign_id": "123",
    "type": "chat_message"
  }
}
```

Handle all app states:

- Foreground: process `FirebaseMessaging.onMessage` and show an in-app banner or
  local notification according to the product UI.
- Background: process `FirebaseMessaging.onMessageOpenedApp`.
- Terminated: process `FirebaseMessaging.instance.getInitialMessage()` after
  navigation is ready.

For `type == chat_message`, open the chat list and refresh conversations/unread
count. The current payload does not contain a conversation ID, so do not invent
direct conversation navigation from `campaign_id`.

Push registration must follow
`docs/APP_TEAM_PUSH_IMPLEMENTATION_HANDOFF.md`: register the permitted signed-in
device with `POST /push/devices`, refresh registration when Firebase changes the
installation ID, and unregister before logout or when notifications are disabled.

FCM provides operating-system notification delivery. Socket.IO remains the
source for live messages, receipts and unread count updates.

## Reconnect and recovery

After every successful reconnect:

1. Rejoin the currently open conversation, if any.
2. Fetch `POST /chat/events/unread_count` with `{}` and replace the badge.
3. Refresh the current conversation history or conversation list as appropriate.
4. Deduplicate messages by ID and revision.

Socket acknowledgements and broadcasts may race. Persisted REST responses are
the recovery source after a disconnect.

## Acceptance checklist

- A recipient sees an incoming message without manually refreshing.
- Duplicate aliases do not create duplicate messages.
- Delivery is acknowledged only by the recipient device.
- Opening a message sends `chat:read` and updates sender receipt UI.
- A new incoming message updates the signed-in recipient's badge in realtime.
- Reading a message reduces the badge to the server-provided total.
- Events for the other participant do not change the signed-in user's badge.
- Login, reconnect and app resume reconcile the badge through the REST count.
- Foreground, background and terminated chat pushes are handled.
- Logout disconnects Socket.IO and unregisters the current push device.
- Bearers, Firebase installation IDs and notification credentials are absent
  from application logs.

## Backend references

- `docs/CHAT_EVENTS.md`
- `docs/CHAT_SOCKET_IO.md`
- `docs/APP_TEAM_PUSH_IMPLEMENTATION_HANDOFF.md`
- `docs/postman/chat-realtime.postman_collection.json`

