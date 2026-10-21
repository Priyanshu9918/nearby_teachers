'use strict';

const http = require('node:http');
const { createHash, timingSafeEqual } = require('node:crypto');
const { Server } = require('socket.io');

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const isUuid = (value) => typeof value === 'string' && UUID.test(value);

function createChatServer(config) {
  if (typeof config.secret !== 'string' || config.secret.length < 32) {
    throw new Error('SOCKET_IO_SHARED_SECRET must be at least 32 characters.');
  }
  const apiUrl = new URL(config.apiUrl);
  if (!['http:', 'https:'].includes(apiUrl.protocol) || apiUrl.username || apiUrl.password) {
    throw new Error('Invalid SOCKET_IO_LARAVEL_URL.');
  }
  const origins = new Set(config.origins || []);
  const secretHash = createHash('sha256').update(config.secret).digest();
  let io;
  const server = http.createServer(async (req, res) => {
    const respond = (status, data) => {
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(data));
    };
    if (req.method === 'GET' && req.url === '/health') {
      const ready = !config.isReady || config.isReady();
      return respond(ready ? 200 : 503, { ok: ready });
    }
    if (req.method !== 'POST' || !['/internal/events', '/internal/sessions/revoke'].includes(req.url)) return respond(404, { error: 'Not found' });
    const supplied = req.headers['x-socket-secret'];
    if (typeof supplied !== 'string' || supplied.length > 1024 ||
        !timingSafeEqual(secretHash, createHash('sha256').update(supplied).digest())) {
      req.resume();
      return respond(401, { error: 'Unauthorized' });
    }
    if (Number(req.headers['content-length']) > 65536) {
      req.resume();
      return respond(413, { error: 'Payload too large' });
    }
    try {
      const chunks = [];
      let size = 0;
      for await (const chunk of req) {
        size += chunk.length;
        if (size > 65536) throw new Error('Payload too large');
        chunks.push(chunk);
      }
      const packet = JSON.parse(Buffer.concat(chunks).toString('utf8'));
      if (config.isReady && !config.isReady()) return respond(503, { error: 'Event delivery unavailable' });
      if (req.url === '/internal/sessions/revoke') {
        if (!isUuid(packet.userId) || !Number.isSafeInteger(packet.authVersion) || packet.authVersion < 1) {
          return respond(422, { error: 'Invalid session revocation' });
        }
        const sockets = await io.in('user.' + packet.userId).fetchSockets();
        for (const socket of sockets) {
          if ((socket.data.authVersion || 0) < packet.authVersion) socket.disconnect(true);
        }
        return respond(200, { ok: true });
      }
      const { message, recipientIds } = packet;
      if (!message || !isUuid(message.id) || !isUuid(message.conversation_id) || !isUuid(message.sender_id) ||
          typeof message.body !== 'string' || Array.from(message.body).length > 5000 ||
          !Array.isArray(recipientIds) || recipientIds.length !== 2 || !recipientIds.every(isUuid) ||
          !recipientIds.includes(message.sender_id)) {
        return respond(422, { error: 'Invalid chat event' });
      }
      // Room union sends one packet even when a socket belongs to both rooms.
      io.to(['private-conversation.' + message.conversation_id, ...recipientIds.map((id) => 'user.' + id)])
        .emit('message.sent', { message });
      return respond(200, { ok: true });
    } catch {
      if (!res.headersSent) respond(400, { error: 'Invalid event payload' });
    }
  });

  io = new Server(server, {
    cors: { origin: [...origins], methods: ['GET', 'POST'] },
    allowRequest: (req, callback) => callback(null, !req.headers.origin || origins.has(req.headers.origin)),
    maxHttpBufferSize: 65536,
    pingInterval: 25000,
    pingTimeout: 20000,
    serveClient: false,
  });

  async function laravel(token, path, body) {
    const response = await fetch(new URL(path, apiUrl), {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(5000),
      redirect: 'error',
    });
    const data = await response.json();
    if (!response.ok) {
      const error = new Error(response.status === 401 || response.status === 403 ? 'Authentication or access denied' : 'Request rejected');
      error.status = response.status;
      error.details = data.errors;
      throw error;
    }
    return data;
  }

  io.use(async (socket, next) => {
    const token = socket.handshake.auth?.token;
    if (typeof token !== 'string' || !token || token.length > 8192) {
      const error = new Error('Authentication required');
      error.data = { code: 'UNAUTHORIZED' };
      return next(error);
    }
    try {
      const auth = await laravel(token, '/chat/socket/authorize', {});
      if (!isUuid(auth.userId)) throw new Error('Invalid authorization response');
      socket.data.token = token;
      socket.data.userId = auth.userId;
      socket.data.authVersion = Number.isSafeInteger(auth.authVersion) ? auth.authVersion : 0;
      socket.data.sessionMs = Math.min(900000, Math.max(1000, (auth.expiresInSeconds || 900) * 1000));
      next();
    } catch (cause) {
      const error = new Error('Unable to authorize chat connection');
      error.data = { code: cause.status === 401 || cause.status === 403 ? 'UNAUTHORIZED' : 'AUTH_UNAVAILABLE' };
      next(error);
    }
  });

  io.on('connection', (socket) => {
    socket.join('user.' + socket.data.userId);
    const expiry = setTimeout(() => {
      socket.emit('session.expired');
      socket.disconnect(true);
    }, socket.data.sessionMs);
    expiry.unref();
    socket.on('disconnect', () => clearTimeout(expiry));

    let windowStart = Date.now();
    let eventCount = 0;
    let inFlight = 0;
    const limited = () => {
      if (Date.now() - windowStart >= 10000) { windowStart = Date.now(); eventCount = 0; }
      return ++eventCount > 30 || inFlight >= 4;
    };
    const operation = (handler) => async (payload, callback) => {
      const ack = typeof callback === 'function' ? callback : () => {};
      if (limited()) return ack({ ok: false, status: 429, error: 'Too many requests' });
      inFlight++;
      try {
        if (!payload || !isUuid(payload.conversationId)) return ack({ ok: false, status: 422, error: 'Invalid conversationId' });
        const result = await handler(payload);
        if (socket.connected) ack({ ok: true, ...result });
      } catch (error) {
        ack({ ok: false, status: error.status || 503, error: error.message, errors: error.details });
      } finally {
        inFlight--;
      }
    };

    socket.on('conversation.join', operation(async ({ conversationId }) => {
      const room = 'private-conversation.' + conversationId;
      if (!socket.rooms.has(room) && socket.rooms.size >= 52) {
        const error = new Error('Leave another conversation before joining more rooms');
        error.status = 429;
        throw error;
      }
      const auth = await laravel(socket.data.token, '/chat/socket/authorize', { conversationId });
      if (auth.userId !== socket.data.userId || auth.room !== room) throw new Error('Invalid room authorization');
      if (socket.connected) await socket.join(room);
      return { room };
    }));
    socket.on('conversation.leave', operation(async ({ conversationId }) => {
      await socket.leave('private-conversation.' + conversationId);
      return {};
    }));
    socket.on('message.send', operation(async ({ conversationId, body }) => {
      if (typeof body !== 'string' || !body.trim() || Array.from(body).length > 5000) {
        const error = new Error('Message body must contain 1–5000 characters');
        error.status = 422;
        throw error;
      }
      return laravel(socket.data.token, '/messages/' + conversationId, { body });
    }));
  });

  return { server, io, close: () => new Promise((resolve) => io.close(resolve)) };
}

module.exports = { createChatServer };
