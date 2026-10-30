'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { once } = require('node:events');
const { io: connect } = require('socket.io-client');
const { createChatServer } = require('../server');

const learner = 1;
const provider = 2;
const outsider = 3;
const conversation = 4;
const postId = 12;
const commentId = 34;
const secret = 'test-socket-secret-'.repeat(4);
const message = {
  id: 5, conversation_id: conversation,
  sender_id: learner, body: 'Hello from Laravel', created_at: '2026-01-01 00:00:00',
};

async function setup(t, options = {}) {
  const tokens = { 'learner-token': learner, 'fresh-token': learner, 'provider-token': provider, 'outsider-token': outsider };
  const requests = [];
  const laravel = http.createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = JSON.parse(Buffer.concat(chunks).toString() || '{}');
    requests.push({ path: req.url, method: req.method, body });
    if (options.apiPrefix) {
      if (!req.url.startsWith(options.apiPrefix + '/')) {
        res.writeHead(404); res.end('{}'); return;
      }
      req.url = req.url.slice(options.apiPrefix.length);
    }
    const id = tokens[req.headers.authorization?.replace('Bearer ', '')];
    const reply = (status, data) => {
      res.writeHead(status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(data));
    };
    if (!id) return reply(401, { error: 'Invalid token' });
    if (options.blocked && (
      (req.url === '/chat/socket/authorize' && body.conversationId) ||
      req.url === '/messages/' + conversation ||
      (req.url.startsWith('/chat/events/') && !['history', 'unread_count', 'delete'].includes(req.url.split('/').pop()))
    )) return reply(403, {error: 'Messaging is unavailable for this conversation.', code: 'CHAT_BLOCKED'});
    if (req.url === '/chat/socket/authorize') {
      if (options.expireAfterAuthChecks && requests.filter((request) => request.path.endsWith('/chat/socket/authorize')).length > options.expireAfterAuthChecks) {
        return reply(403, { error: 'Invalid or expired token' });
      }
      if (body.conversationId && (body.conversationId !== conversation || id === outsider)) {
        return reply(404, { error: 'Conversation not found' });
      }
      return reply(200, {
        userId: id, room: body.conversationId ? 'private-conversation.' + conversation : null,
        expiresInSeconds: options.expiresInSeconds || 900,
        authVersion: req.headers.authorization === 'Bearer fresh-token' ? 1 : 0,
      });
    }
    if (req.url === '/feed/socket/authorize') {
      if (!Number.isSafeInteger(body.postId) || body.postId < 1) return reply(404, { error: 'Post not found' });
      return reply(200, { userId: id, postId: body.postId, room: 'feed-post.' + body.postId, expiresInSeconds: 900 });
    }
    if (req.url === '/feed/' + postId + '/comments' || req.url === '/feed/' + postId + '/like' ||
        req.url === '/feed/comments/' + commentId + '/like') {
      return reply(200, { ok: true, comment: { id: commentId, post_id: postId, parent_comment_id: body.parentCommentId || null } });
    }
    if (req.url.startsWith('/chat/events/')) {
      if (id === outsider || (body.conversationId && body.conversationId !== conversation)) return reply(404, {error: 'Conversation not found'});
      const action = req.url.split('/').pop();
      if (action === 'send' || action === 'reply') return reply(200, {message: {...message, sender_id: id, body: body.body, reply_to_id: body.replyToId || null}});
      if (action === 'history') return reply(200, {messages: [message], pagination: {perPage: 20, hasMore: false, nextCursor: null}});
      if (action === 'unread_count') return reply(200, {unreadCount: 1, conversationId: body.conversationId || null});
      return reply(200, {conversationId: body.conversationId, messageId: body.messageId, userId: id, reaction: body.reaction});
    }
    if (req.url === '/messages/' + conversation && id !== outsider) {
      return reply(200, { message: { ...message, sender_id: id, body: body.body } });
    }
    return reply(404, { error: 'Conversation not found' });
  });
  laravel.listen(0, '127.0.0.1');
  await once(laravel, 'listening');
  const chat = createChatServer({
    secret, apiUrl: 'http://127.0.0.1:' + laravel.address().port + (options.apiPrefix || ''),
    origins: ['https://app.example.com'], isReady: options.isReady,
  });
  chat.server.listen(0, '127.0.0.1');
  await once(chat.server, 'listening');
  const baseUrl = 'http://127.0.0.1:' + chat.server.address().port;
  const sockets = [];
  t.after(async () => {
    for (const socket of sockets) socket.disconnect();
    await chat.close();
    await new Promise((resolve) => laravel.close(resolve));
  });
  const client = (token, extra = {}) => {
    const socket = connect(baseUrl, {
      auth: token === undefined ? {} : { token }, transports: ['websocket'],
      reconnection: false, autoConnect: false, ...extra,
    });
    sockets.push(socket);
    return socket;
  };
  const connected = async (token) => {
    const socket = client(token);
    const ready = once(socket, 'connect');
    socket.connect();
    await ready;
    return socket;
  };
  const publish = (body, headers = { 'X-Socket-Secret': secret }) => fetch(baseUrl + '/internal/events', {
    method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body),
  });
  return { client, connected, publish, requests, baseUrl };
}

test('Laravel subdirectory is preserved for authorization, joins and message APIs', {timeout: 10000}, async (t) => {
  const {connected, requests} = await setup(t, {apiPrefix: '/nearby_teachers/public'});
  const socket = await connected('learner-token');
  assert.equal((await socket.timeout(2000).emitWithAck('conversation.join', {conversationId: conversation})).ok, true);
  assert.equal((await socket.timeout(2000).emitWithAck('message.send', {conversationId: conversation, body: 'Subdirectory test'})).ok, true);
  assert.equal((await socket.timeout(2000).emitWithAck('chat:history', {conversationId: conversation})).ok, true);
  assert.ok(requests.some((request) => request.path === '/nearby_teachers/public/messages/' + conversation));
  assert.ok(requests.every((request) => request.path.startsWith('/nearby_teachers/public/')));
});

test('missing and invalid credentials cannot connect', { timeout: 10000 }, async (t) => {
  const { client } = await setup(t);
  for (const token of [undefined, 'invalid-token']) {
    const socket = client(token);
    const rejected = once(socket, 'connect_error');
    socket.connect();
    const [error] = await rejected;
    assert.equal(error.data.code, 'UNAUTHORIZED');
    assert.equal(socket.connected, false);
  }
});

test('Postman Socket.IO request authenticates with a Bearer handshake header', {timeout: 10000}, async (t) => {
  const {client, requests} = await setup(t);
  const socket = client(undefined, {extraHeaders: {Authorization: 'Bearer learner-token'}});
  const ready = once(socket, 'connect');
  socket.connect();
  await ready;

  const ping = await socket.timeout(2000).emitWithAck('chat.ping', {});
  assert.equal(ping.ok, true);
  assert.deepEqual(requests.map((request) => request.path), ['/chat/socket/authorize']);
});

test('authenticated chat ping acknowledges without calling the message API and is rate limited', { timeout: 10000 }, async (t) => {
  const { connected, requests } = await setup(t);
  const socket = await connected('learner-token');
  for (let index = 0; index < 30; index++) {
    const ack = await socket.timeout(2000).emitWithAck('chat.ping', {});
    assert.equal(ack.ok, true);
    assert.ok(Number.isFinite(Date.parse(ack.serverTime)));
  }
  const limited = await socket.timeout(2000).emitWithAck('chat.ping', {});
  assert.equal(limited.status, 429);
  assert.equal(limited.ok, false);
  assert.deepEqual(requests.map((request) => request.path), ['/chat/socket/authorize']);
});

test('numeric conversation IDs accept canonical strings and reject legacy or unsafe IDs', {timeout: 10000}, async (t) => {
  const {connected, requests} = await setup(t);
  const socket = await connected('learner-token');
  for (const conversationId of [0, -1, 1.5, true, [], '01', '1e0', Number.MAX_SAFE_INTEGER + 1, '00000000-0000-4000-8000-000000000001']) {
    const ack = await socket.timeout(2000).emitWithAck('conversation.join', {conversationId});
    assert.equal(ack.ok, false);
    assert.equal(ack.status, 422);
  }
  assert.equal(requests.length, 1);
  assert.equal((await socket.timeout(2000).emitWithAck('conversation.join', {conversationId: String(conversation)})).ok, true);
  assert.equal(requests.at(-1).body.conversationId, conversation);
});

test('private room joins and message sending are authorized through Laravel', { timeout: 10000 }, async (t) => {
  const { connected, requests } = await setup(t);
  const socket = await connected('learner-token');
  const joined = await socket.timeout(2000).emitWithAck('conversation.join', { conversationId: conversation });
  assert.equal(joined.ok, true);
  const sent = await socket.timeout(2000).emitWithAck('message.send', { conversationId: conversation, body: 'Saved by Laravel' });
  assert.equal(sent.ok, true);
  assert.equal(sent.message.body, 'Saved by Laravel');
  assert.ok(requests.some((request) => request.path === '/messages/' + conversation));
  const other = await connected('outsider-token');
  assert.equal((await other.timeout(2000).emitWithAck('conversation.join', { conversationId: conversation })).status, 404);
  assert.equal((await other.timeout(2000).emitWithAck('message.send', { conversationId: conversation, body: 'Forbidden' })).status, 404);
  assert.equal((await socket.timeout(2000).emitWithAck('conversation.join', { conversationId: 'user.' + outsider })).status, 422);
});

test('already joined sockets honor a subsequent API block and recover after unblock', {timeout: 10000}, async (t) => {
  const options = {blocked: false};
  const {connected} = await setup(t, options);
  const first = await connected('learner-token');
  const second = await connected('provider-token');
  assert.equal((await first.timeout(2000).emitWithAck('conversation.join', {conversationId: conversation})).ok, true);
  options.blocked = true;
  for (const socket of [first, second]) {
    for (const event of ['message.send', 'chat:send', 'chat:reply', 'chat:typing', 'chat:read', 'chat:reaction', 'conversation.join']) {
      const result = await socket.timeout(2000).emitWithAck(event, {conversationId: conversation, body: 'Blocked', messageId: message.id, replyToId: message.id, reaction: '👍'});
      assert.equal(result.ok, false, event);
      assert.equal(result.status, 403, event);
      assert.equal(result.code, 'CHAT_BLOCKED', event);
    }
    assert.equal((await socket.timeout(2000).emitWithAck('chat:history', {conversationId: conversation})).ok, true);
  }
  options.blocked = false;
  assert.equal((await first.timeout(2000).emitWithAck('message.send', {conversationId: conversation, body: 'Allowed'})).ok, true);
});

test('server event reaches both participant devices once and never outsiders', { timeout: 10000 }, async (t) => {
  const { connected, publish } = await setup(t);
  const a = await connected('learner-token');
  const b = await connected('provider-token');
  const c = await connected('outsider-token');
  await a.timeout(2000).emitWithAck('conversation.join', { conversationId: conversation });
  let aCount = 0;
  let outsiderCount = 0;
  a.on('message.sent', () => aCount++);
  c.on('message.sent', () => outsiderCount++);
  const first = once(a, 'message.sent');
  const second = once(b, 'message.sent');
  const response = await publish({ message, recipientIds: [learner, provider] });
  assert.equal(response.status, 200);
  assert.deepEqual((await first)[0], { message });
  assert.deepEqual((await second)[0], { message });
  await new Promise((resolve) => setTimeout(resolve, 80));
  assert.equal(aCount, 1);
  assert.equal(outsiderCount, 0);
});

test('clients cannot publish forged events or spoof recipients', { timeout: 10000 }, async (t) => {
  const { publish } = await setup(t);
  assert.equal((await publish({ message, recipientIds: [learner, provider] }, {})).status, 401);
  assert.equal((await publish({ message, recipientIds: [provider, outsider] })).status, 422);
  assert.equal((await publish({ message: { ...message, id: 'invalid' }, recipientIds: [learner, provider] })).status, 422);
  assert.equal((await publish({ message: { ...message, body: '🙂'.repeat(5000) }, recipientIds: [learner, provider] })).status, 200);
});

test('websocket handshake rejects an unapproved browser origin', { timeout: 10000 }, async (t) => {
  const { client } = await setup(t);
  const socket = client('learner-token', { extraHeaders: { Origin: 'https://unapproved.example' } });
  const rejected = once(socket, 'connect_error');
  socket.connect();
  await rejected;
  assert.equal(socket.connected, false);
});

test('redis readiness failure returns 503 so the Laravel queue can retry', { timeout: 10000 }, async (t) => {
  const { publish, baseUrl } = await setup(t, { isReady: () => false });
  assert.equal((await fetch(baseUrl + '/health')).status, 503);
  assert.equal((await publish({ message, recipientIds: [learner, provider] })).status, 503);
});

test('socket sessions reauthorize and stay connected while the bearer remains valid', { timeout: 10000 }, async (t) => {
  const { connected, requests } = await setup(t, { expiresInSeconds: 1 });
  const socket = await connected('learner-token');
  await new Promise((resolve) => setTimeout(resolve, 1100));
  assert.equal(socket.connected, true);
  assert.equal(requests.filter((request) => request.path.endsWith('/chat/socket/authorize')).length, 2);
});

test('socket sessions expire only when periodic bearer reauthorization fails', { timeout: 10000 }, async (t) => {
  const { connected } = await setup(t, { expiresInSeconds: 1, expireAfterAuthChecks: 1 });
  const socket = await connected('learner-token');
  const expired = once(socket, 'session.expired');
  const disconnected = once(socket, 'disconnect');
  await expired;
  assert.equal((await disconnected)[0], 'io server disconnect');
});

test('password reset disconnects only older socket sessions', { timeout: 10000 }, async (t) => {
  const { connected, baseUrl } = await setup(t);
  const old = await connected('learner-token');
  const fresh = await connected('fresh-token');
  const disconnected = once(old, 'disconnect');
  const response = await fetch(baseUrl + '/internal/sessions/revoke', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Socket-Secret': secret },
    body: JSON.stringify({ userId: learner, authVersion: 1 }),
  });
  assert.equal(response.status, 200);
  assert.equal((await disconnected)[0], 'io server disconnect');
  assert.equal(fresh.connected, true);
});

test('all client chat events are forwarded to allowlisted Laravel APIs with authenticated ownership', {timeout: 10000}, async (t) => {
  const {connected, requests} = await setup(t);
  const socket = await connected('learner-token');
  for (const action of ['send', 'delivered', 'read', 'typing', 'stop_typing', 'delete', 'edit', 'reply', 'reaction', 'history', 'unread_count']) {
    const ack = await socket.timeout(2000).emitWithAck('chat:' + action, {conversationId: conversation, messageId: message.id, replyToId: message.id, body: 'Test', reaction: '👍', perPage: 20, userId: outsider, token: 'forged'});
    assert.equal(ack.ok, true, action);
    const forwarded = requests.find((request) => request.path === '/chat/events/' + action);
    assert.ok(forwarded, action);
    assert.equal(forwarded.body.userId, undefined);
    assert.equal(forwarded.body.token, undefined);
  }
  assert.equal((await socket.timeout(2000).emitWithAck('chat:unread_count', {})).unreadCount, 1);
  const stranger = await connected('outsider-token');
  assert.equal((await stranger.timeout(2000).emitWithAck('chat:read', {conversationId: conversation, messageId: message.id})).status, 404);
});

test('feed rooms authorize membership and live actions use authenticated REST mutations', {timeout: 10000}, async (t) => {
  const {connected, requests} = await setup(t);
  const socket = await connected('learner-token');
  const denied = await socket.timeout(2000).emitWithAck('feed:comment', {postId, body: 'Before join'});
  assert.equal(denied.ok, false);
  assert.equal(denied.status, 403);

  const joined = await socket.timeout(2000).emitWithAck('feed:join', {postId});
  assert.equal(joined.ok, true);
  assert.equal(joined.room, 'feed-post.' + postId);

  const actions = [
    ['feed:comment', {postId, body: 'Hello', userId: outsider, token: 'forged'}, 'POST', '/feed/' + postId + '/comments', {body: 'Hello'}],
    ['feed:reply', {postId, parentCommentId: commentId, body: 'Reply'}, 'POST', '/feed/' + postId + '/comments', {parentCommentId: commentId, body: 'Reply'}],
    ['feed:post.like', {postId}, 'POST', '/feed/' + postId + '/like', {}],
    ['feed:post.unlike', {postId}, 'DELETE', '/feed/' + postId + '/like', {}],
    ['feed:comment.like', {postId, commentId}, 'POST', '/feed/comments/' + commentId + '/like', {postId}],
    ['feed:comment.unlike', {postId, commentId}, 'DELETE', '/feed/comments/' + commentId + '/like', {postId}],
  ];
  for (const [event, payload, method, path, body] of actions) {
    assert.equal((await socket.timeout(2000).emitWithAck(event, payload)).ok, true, event);
    const request = requests.at(-1);
    assert.equal(request.method, method, event);
    assert.equal(request.path, path, event);
    assert.deepEqual(request.body, body, event);
  }
  const ping = await socket.timeout(2000).emitWithAck('chat.ping', {});
  assert.equal(ping.ok, true);
  assert.equal((await socket.timeout(2000).emitWithAck('feed:leave', {postId})).ok, true);
  const afterLeave = await socket.timeout(2000).emitWithAck('feed:post.like', {postId});
  assert.equal(afterLeave.status, 403);
});

test('feed bridge events reach only authorized post subscribers and reject invalid payloads', {timeout: 10000}, async (t) => {
  const {connected, publish} = await setup(t);
  const member = await connected('learner-token');
  const otherPostMember = await connected('provider-token');
  await member.timeout(2000).emitWithAck('feed:join', {postId});
  await otherPostMember.timeout(2000).emitWithAck('feed:join', {postId: postId + 1});
  const payload = {
    postId,
    commentId,
    user: {user_id: learner, name: 'Learner', type: 'learner'},
    liked: true,
    likeCount: 2,
  };
  const received = once(member, 'feed:comment.like_changed');
  let otherPostCount = 0;
  otherPostMember.on('feed:comment.like_changed', () => otherPostCount++);
  assert.equal((await publish({postId, event: 'feed:comment.like_changed', data: payload})).status, 200);
  assert.deepEqual((await received)[0], payload);
  assert.equal((await publish({postId, event: 'feed:comment.like_changed', data: {...payload, likeCount: -1}})).status, 422);
  const comment = {
    id: commentId + 1, post_id: postId, user_id: learner, parent_comment_id: null,
    body: 'New feed comment', author_name: 'Learner', author_type: 'learner',
  };
  const newComment = once(member, 'feed:comment.created');
  assert.equal((await publish({postId, event: 'feed:comment.created', data: {postId, comment}})).status, 200);
  assert.deepEqual((await newComment)[0], {postId, comment});
  assert.equal((await publish({postId, event: 'feed:comment.created', data: {
    postId, comment: {...comment, author_type: 'admin'},
  }})).status, 422);
  await new Promise((resolve) => setTimeout(resolve, 60));
  assert.equal(otherPostCount, 0);
});

test('new message aliases reach participants once each and cannot be published by clients', {timeout: 10000}, async (t) => {
  const {connected, publish} = await setup(t);
  const a = await connected('learner-token'), b = await connected('provider-token'), c = await connected('outsider-token');
  let outsiderPackets = 0;
  c.on('chat:receive', () => outsiderPackets++);
  const received = once(a, 'chat:receive'), fresh = once(b, 'chat:new');
  assert.equal((await publish({message, recipientIds: [learner, provider]})).status, 200);
  assert.deepEqual((await received)[0], {message});
  assert.deepEqual((await fresh)[0], {message});
  a.emit('chat:new', {message: {...message, body: 'forged'}});
  await new Promise((resolve) => setTimeout(resolve, 50));
  assert.equal(outsiderPackets, 0);
});

test('persistent chat notifications and typing are private, validated and server-authenticated', {timeout: 10000}, async (t) => {
  const {connected, publish} = await setup(t);
  const socket = await connected('provider-token');
  for (const event of ['chat:delivered', 'chat:read', 'chat:typing', 'chat:stop_typing', 'chat:delete', 'chat:edit', 'chat:reply', 'chat:reaction']) {
    const data = {conversationId: conversation, messageId: message.id, userId: learner, message, reaction: '👍', typing: event === 'chat:typing', expiresAt: new Date(Date.now() + 5000).toISOString()};
    const incoming = once(socket, event);
    assert.equal((await publish({event, data, recipientIds: [learner, provider]})).status, 200);
    assert.deepEqual((await incoming)[0], data);
    assert.equal((await publish({event, data, recipientIds: [learner, provider]}, {})).status, 401);
  }
  assert.equal((await publish({event: 'arbitrary', data: {conversationId: conversation}, recipientIds: [learner, provider]})).status, 422);
  assert.equal((await publish({event: 'chat:typing', data: {conversationId: conversation, userId: outsider, typing: true}, recipientIds: [learner, provider]})).status, 422);
  const expired = await publish({event: 'chat:typing', data: {conversationId: conversation, userId: learner, typing: true, expiresAt: new Date(Date.now() - 1000).toISOString()}, recipientIds: [learner, provider]});
  assert.equal((await expired.json()).expired, true);
});
