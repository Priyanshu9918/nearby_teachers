'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { once } = require('node:events');
const { io: connect } = require('socket.io-client');
const { createChatServer } = require('../server');

const learner = '11111111-1111-4111-8111-111111111111';
const provider = '22222222-2222-4222-8222-222222222222';
const outsider = '33333333-3333-4333-8333-333333333333';
const conversation = '44444444-4444-4444-8444-444444444444';
const secret = 'test-socket-secret-'.repeat(4);
const message = {
  id: '55555555-5555-4555-8555-555555555555', conversation_id: conversation,
  sender_id: learner, body: 'Hello from Laravel', created_at: '2026-01-01 00:00:00',
};

async function setup(t, options = {}) {
  const tokens = { 'learner-token': learner, 'fresh-token': learner, 'provider-token': provider, 'outsider-token': outsider };
  const requests = [];
  const laravel = http.createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = JSON.parse(Buffer.concat(chunks).toString() || '{}');
    requests.push({ path: req.url, body });
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
    if (req.url === '/chat/socket/authorize') {
      if (body.conversationId && (body.conversationId !== conversation || id === outsider)) {
        return reply(404, { error: 'Conversation not found' });
      }
      return reply(200, {
        userId: id, room: body.conversationId ? 'private-conversation.' + conversation : null,
        expiresInSeconds: options.expiresInSeconds || 900,
        authVersion: req.headers.authorization === 'Bearer fresh-token' ? 1 : 0,
      });
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

test('bounded sessions disconnect and require a fresh authorization', { timeout: 10000 }, async (t) => {
  const { connected } = await setup(t, { expiresInSeconds: 1 });
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
