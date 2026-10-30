# Test chat Socket.IO in Postman

Use a **Socket.IO** request for live connection, ping and chat events. The
HTTP-only collection at `chat-realtime.postman_collection.json` contains REST
checks and fallbacks; it cannot hold Socket.IO requests. Postman saves the
Socket.IO request in its own multi-protocol collection from the desktop UI.

## Create and connect

1. In Postman, choose **New → Socket.IO**.
2. Enter the reachable Socket.IO URL. If port `6001` is exposed on the host in
   the screenshot, use `ws://157.245.125.79:6001`. Otherwise replace it with
   the host/port or reverse-proxy URL configured for your deployment. Use
   `wss://` when the endpoint is served through TLS.
3. Open **Settings** and set **Handshake path** to `/socket.io/` and the
   Socket.IO client version to `4`.
4. Open **Headers** and add:

   ```text
   Authorization: Bearer <Laravel login token>
   ```

5. Click **Connect**. The Node server checks this bearer against Laravel. The
   connection is accepted only if the token belongs to an active, verified
   account.

The app's Socket.IO client normally sends the token as `auth.token`. Postman
uses the `Authorization` handshake header; the Socket.IO server accepts both.
Do not put the internal Socket.IO shared secret in Postman.

## Check ping

With the connection open, set the event name beside **Send** to `chat.ping`,
add one argument containing `{}`, enable **Ack**, and send. Expected ACK:

```json
{
  "ok": true,
  "serverTime": "2026-10-30T12:00:00.000Z"
}
```

Ping checks the authenticated Socket.IO round trip. It does not query database
or queue health. It shares the limit of 30 socket operations per 10 seconds.

## Join and test a conversation

Use a real conversation ID accessible to the bearer. Send event
`conversation.join` with one argument and **Ack** enabled:

```json
{
  "conversationId": 123
}
```

Expected ACK is `{ "ok": true, "room": "private-conversation.123" }`.
After joining, test these events by setting the event name and sending one JSON
argument:

| Event | Example argument |
|---|---|
| `chat:send` | `{"conversationId":123,"body":"Postman test message"}` |
| `chat:reply` | `{"conversationId":123,"replyToId":456,"body":"Reply from Postman"}` |
| `chat:history` | `{"conversationId":123,"perPage":20}` |
| `chat:delivered` | `{"conversationId":123,"messageId":456}` |
| `chat:read` | `{"conversationId":123,"messageId":456}` |
| `chat:typing` / `chat:stop_typing` | `{"conversationId":123}` |
| `chat:edit` | `{"conversationId":123,"messageId":456,"body":"Updated text"}` |
| `chat:delete` | `{"conversationId":123,"messageId":456}` |
| `chat:reaction` | `{"conversationId":123,"messageId":456,"reaction":"❤️"}` |
| `chat:unread_count` | `{}` or `{"conversationId":123}` |

Use the **Events** panel to listen for `chat:receive`, `chat:read`,
`chat:delivered`, `chat:typing`, `chat:stop_typing`, `chat:edit`,
`chat:delete` and `chat:reaction`. The new-message aliases `message.sent`,
`chat:receive` and `chat:new` represent the same saved message; listen to one
or compare message IDs to avoid counting duplicates.

## Save the Socket.IO request

Click **Save → New Collection**, name it `Nearby Teachers — Chat Socket.IO`,
and save the request. You can save the event messages with that request for
reuse. Keep it separate from the HTTP `chat-realtime.postman_collection.json`:
Postman multi-protocol collections contain Socket.IO/WebSocket requests and
cannot contain HTTP requests. Postman does not export multi-protocol
collections, so this request must be saved inside the Postman workspace.

The bearer token is a real credential. Keep it in Postman's local variables or
Vault and do not save a live token in shared examples. See the project
[chat event contract](../CHAT_EVENTS.md) and [Socket.IO setup](../CHAT_SOCKET_IO.md)
for permissions, reconnect and session-expiry behavior.
