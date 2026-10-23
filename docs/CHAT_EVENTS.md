# Named chat events and APIs

All REST endpoints below require `Authorization: Bearer <user login token>`.
Connect Socket.IO with `auth.token` using the same token. Only conversation
participants can access messages. The existing phone verification, suspension,
safety acknowledgement and guardian consent gates continue to apply. Existing
`message.send`, `message.sent` and conversation join/leave events remain supported.

| Socket.IO event | REST equivalent | Direction / behavior |
|---|---|---|
| `chat:send` | `POST /chat/events/send` | Client request; saves a message |
| `chat:receive` | No publish endpoint | Server notification of a saved message |
| `chat:new` | No publish endpoint | Server alias of the same new-message notification |
| `chat:delivered` | `POST /chat/events/delivered` | Recipient confirms receipt; server notifies participants |
| `chat:read` | `POST /chat/events/read` | Recipient confirms reading; implies delivery |
| `chat:typing` | `POST /chat/events/typing` | Participant sends an ephemeral typing hint |
| `chat:stop_typing` | `POST /chat/events/stop_typing` | Participant clears their typing hint |
| `chat:delete` | `POST /chat/events/delete` | Sender clears the body and leaves a tombstone |
| `chat:edit` | `POST /chat/events/edit` | Sender changes the body and increments revision |
| `chat:reply` | `POST /chat/events/reply` | Saves a linked reply within the same conversation |
| `chat:reaction` | `POST /chat/events/reaction` | Sets/replaces/removes the participant's reaction |
| `chat:history` | `POST /chat/events/history` | Bounded persisted history with replies and reactions |
| `chat:unread_count` | `POST /chat/events/unread_count` | Count of incoming, non-deleted messages with no read receipt |

`chat:receive` and `chat:new` cannot be emitted by clients to publish messages.
They are listen-only events, not new HTTP mutations. REST history is the durable
receive/recovery API. Delivery and reading are explicit client acknowledgements;
connection presence never marks a message delivered or read automatically.
Unread counts are requested, not automatically pushed on every state change.

## Payloads and acknowledgements

Every action except global unread count includes `conversationId` (UUID):

| Action | Additional input |
|---|---|
| send | `body` (nonblank, at most 5,000 characters); optional `replyToId` |
| delivered / read / delete | `messageId` |
| edit | `messageId`, `body` |
| reply | `replyToId`, `body` |
| reaction | `messageId`, `reaction`: `👍`, `👎`, `❤️`, `😂`, `😮`, `😢`, `🙏`, `🎉`, or `null` to remove |
| history | Optional `perPage` (1–100, default 50) and `cursor` |
| unread_count | Optional `conversationId`; omit for all owned conversations |
| typing / stop_typing | No extra input; actor identity comes from the bearer |

Socket acknowledgements are `{ok:true, ...restResponse}` or
`{ok:false,status,error,errors?}`. REST uses normal HTTP status codes.
Send/reply responses contain `{message}`. Message objects include `reply_to_id`,
`revision`, `delivered_at`, `read_at`, `edited_at` and `deleted_at`. History also
includes active `reactions` and the shared `pagination` object. New nullable
fields are not backfilled with invented delivery/read/edit history.

```js
socket.on('chat:receive', ({ message }) => {
  // Upsert by message.id and ignore revisions older than the current one.
});
socket.on('chat:read', (receipt) => { /* messageId, userId, readAt, deliveredAt */ });
socket.on('chat:edit', ({ message }) => { /* upsert current revision */ });
socket.on('chat:delete', ({ message }) => { /* show tombstone, clear text/reactions */ });
socket.on('chat:reaction', (reaction) => { /* upsert by messageId + userId */ });

// After connecting; IDs must come from a real authorized conversation/history.
const sent = await socket.timeout(7000).emitWithAck('chat:send', {
  conversationId, body: 'Hello'
});
if (sent.ok) console.log(sent.message.id);
const history = await socket.timeout(7000).emitWithAck('chat:history', {
  conversationId, perPage: 20
});
```

## Delivery, ordering and limits

`message.sent`, `chat:receive` and `chat:new` represent the same saved message.
Use one new-message listener, or deduplicate by message ID and revision. A reply
also has a `chat:reply` notification; deduplicate that against new-message events.
Room-union delivery avoids duplicate packets within one event name. Queue
retries may repeat notifications. Reconcile receipts monotonically by timestamp
and mutations by revision. Reconnect, fetch history and rejoin active rooms.
ACK timeout does not prove failure; inspect history before retrying a send/reply.

Persistent notifications publish after source commit through the broadcasts
queue. Read can emit both delivered and read notifications if neither receipt
existed. Repeating an unchanged receipt, reaction, edit or delete does not create
another business-history row. A delayed message/edit job reloads current source
content, preventing a queued pre-delete body from reappearing after deletion.

Typing is not persisted or queued: it uses the private bridge immediately,
expires after 5 seconds, and stale bridge hints are discarded. Clients should
clear typing at expiry and ignore older `updatedAt` hints. Send typing at most
once every few seconds and stop on blur/send/disconnect. The existing socket
limit of 30 operations per 10 seconds and per-bearer REST throttles apply to
these operations. Never auto-send a message to implement typing or receipts.

## Developer testing and deployment

Open **Developer → WebSockets → Chat events · 13**, then select the event.
Each event has guidance, a fictional mock, the applicable ID/body/reaction/page
inputs, and Socket/REST buttons. Receive/new are listen-only. Actual responses
replace the selected mock; responses remain separate between operations.
Use a recipient's token for delivered/read and the sender's token for edit/delete.
Keep another participant connected in a second tab to verify private events.
Examples also appear in the app-team Postman collection and API catalog.

Deploy migration `2026_10_23_000033_extend_chat_events.php` before using these
APIs. Restart the Node chat process and broadcasts queue workers after deploying
PHP/Node changes. No migration of application data is performed by the test
suite; integration tests use a disposable database ending in `_test`.
