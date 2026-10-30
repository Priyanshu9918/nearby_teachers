'use strict';

const http = require('node:http');
const { createHash, timingSafeEqual } = require('node:crypto');
const { Server } = require('socket.io');

const isId = (value) => (typeof value === 'number' || typeof value === 'string') && /^[1-9][0-9]*$/.test(String(value)) && Number.isSafeInteger(Number(value));
function normalizeIds(value) {
  if (!value || typeof value !== 'object') return value;
  if (Array.isArray(value)) return value.map(normalizeIds);
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key,
    key === 'recipientIds' && Array.isArray(item) ? item.map((id) => isId(id) ? Number(id) : id)
      : /(?:^id$|_id$|Id$)/.test(key) && isId(item) ? Number(item) : normalizeIds(item)]));
}

function createChatServer(config) {
  if (typeof config.secret !== 'string' || config.secret.length < 32) {
    throw new Error('SOCKET_IO_SHARED_SECRET must be at least 32 characters.');
  }
  const apiUrl = new URL(config.apiUrl);
  if (!['http:', 'https:'].includes(apiUrl.protocol) || apiUrl.username || apiUrl.password) {
    throw new Error('Invalid SOCKET_IO_LARAVEL_URL.');
  }
  // Keep the application prefix when Laravel is hosted in a subdirectory.
  apiUrl.pathname = apiUrl.pathname.replace(/\/$/, '') + '/';
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
      const packet = normalizeIds(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      if (config.isReady && !config.isReady()) return respond(503, { error: 'Event delivery unavailable' });
      if (req.url === '/internal/sessions/revoke') {
        if (!isId(packet.userId) || !Number.isSafeInteger(packet.authVersion) || packet.authVersion < 1) {
          return respond(422, { error: 'Invalid session revocation' });
        }
        const sockets = await io.in('user.' + packet.userId).fetchSockets();
        for (const socket of sockets) {
          if ((socket.data.authVersion || 0) < packet.authVersion) socket.disconnect(true);
        }
        return respond(200, { ok: true });
      }
      if (packet.postId !== undefined) {
        const supportedFeed = new Set(['feed:comment.created', 'feed:comment.like_changed', 'feed:post.like_changed']);
        const { postId, event, data } = packet;
        if (!isId(postId) || !supportedFeed.has(event) || !data || data.postId !== postId) {
          return respond(422, { error: 'Invalid feed event' });
        }
        if (event === 'feed:comment.created') {
          const comment = data.comment;
          if (!comment || !isId(comment.id) || comment.post_id !== postId || !isId(comment.user_id) ||
              (comment.parent_comment_id !== null && comment.parent_comment_id !== undefined && !isId(comment.parent_comment_id)) ||
              typeof comment.body !== 'string' || Array.from(comment.body).length > 5000 ||
              typeof comment.author_name !== 'string' || comment.author_name.length > 160 ||
              !['learner', 'teacher', 'institute'].includes(comment.author_type)) {
            return respond(422, { error: 'Invalid feed comment event' });
          }
        } else {
          const actor = data.user;
          if ((event === 'feed:comment.like_changed' && !isId(data.commentId)) || !actor || !isId(actor.user_id) ||
              typeof actor.name !== 'string' || actor.name.length > 160 ||
              !['learner', 'teacher', 'institute'].includes(actor.type) || typeof data.liked !== 'boolean' ||
              !Number.isSafeInteger(data.likeCount) || data.likeCount < 0) {
            return respond(422, { error: 'Invalid feed like event' });
          }
        }
        io.to('feed-post.' + postId).emit(event, data);
        return respond(200, { ok: true });
      }
      if (packet.event) {
        const supported = new Set(['chat:delivered', 'chat:read', 'chat:typing', 'chat:stop_typing', 'chat:delete', 'chat:edit', 'chat:reply', 'chat:reaction']);
        const { event, data, recipientIds } = packet;
        if (!supported.has(event) || !data || !isId(data.conversationId) ||
            !Array.isArray(recipientIds) || recipientIds.length !== 2 || !recipientIds.every(isId) ||
            (data.userId !== undefined && !recipientIds.includes(data.userId)) ||
            (data.messageId !== undefined && !isId(data.messageId)) ||
            (data.message && (!isId(data.message.id) || data.message.conversation_id !== data.conversationId || !recipientIds.includes(data.message.sender_id)))) {
          return respond(422, {error: 'Invalid chat event'});
        }
        const needsMessage = ['chat:edit', 'chat:delete', 'chat:reply'].includes(event);
        const needsActor = ['chat:delivered', 'chat:read', 'chat:typing', 'chat:stop_typing', 'chat:reaction'].includes(event);
        if ((needsMessage && (!data.message || typeof data.message.body !== 'string' || Array.from(data.message.body).length > 5000)) ||
            (needsActor && !isId(data.userId)) ||
            (['chat:delivered', 'chat:read', 'chat:reaction'].includes(event) && !isId(data.messageId)) ||
            (event === 'chat:reaction' && data.reaction !== null && !['👍', '👎', '❤️', '😂', '😮', '😢', '🙏', '🎉'].includes(data.reaction)) ||
            (['chat:typing', 'chat:stop_typing'].includes(event) && data.typing !== (event === 'chat:typing'))) {
          return respond(422, {error: 'Invalid chat event'});
        }
        if (['chat:typing', 'chat:stop_typing'].includes(event) &&
            (!Number.isFinite(Date.parse(data.expiresAt)) || Date.parse(data.expiresAt) < Date.now())) {
          return respond(200, {ok: true, expired: true});
        }
        io.to(['private-conversation.' + data.conversationId, ...recipientIds.map((id) => 'user.' + id)]).emit(event, data);
        return respond(200, {ok: true});
      }
      const { message, recipientIds } = packet;
      if (!message || !isId(message.id) || !isId(message.conversation_id) || !isId(message.sender_id) ||
          typeof message.body !== 'string' || Array.from(message.body).length > 5000 ||
          !Array.isArray(recipientIds) || recipientIds.length !== 2 || !recipientIds.every(isId) ||
          !recipientIds.includes(message.sender_id)) {
        return respond(422, { error: 'Invalid chat event' });
      }
      // Room union sends one packet even when a socket belongs to both rooms.
      const recipients = io.to(['private-conversation.' + message.conversation_id, ...recipientIds.map((id) => 'user.' + id)]);
      // Backwards compatibility plus the new names. Clients should subscribe to one or deduplicate by ID/revision.
      recipients.emit('message.sent', {message});
      recipients.emit('chat:receive', {message});
      recipients.emit('chat:new', {message});
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

  async function laravel(token, path, body, method = 'POST') {
    const response = await fetch(new URL(path.replace(/^\//, ''), apiUrl), {
      method,
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(5000),
      redirect: 'error',
    });
    const data = await response.json();
    if (!response.ok) {
      const blocked = response.status === 403 && data.code === 'CHAT_BLOCKED';
      const error = new Error(blocked ? 'Messaging is unavailable for this conversation.' : (response.status === 401 || response.status === 403 ? 'Authentication or access denied' : 'Request rejected'));
      error.status = response.status;
      if (blocked) error.code = 'CHAT_BLOCKED';
      error.details = data.errors;
      throw error;
    }
    return data;
  }

  io.use(async (socket, next) => {
    const authToken = socket.handshake.auth?.token;
    const authorization = socket.handshake.headers.authorization;
    const headerToken = typeof authorization === 'string'
      ? /^Bearer\s+(\S+)$/i.exec(authorization)?.[1]
      : null;
    // Mobile Socket.IO clients use auth.token. Postman can authenticate its
    // Socket.IO handshake with a Bearer Authorization header.
    const token = typeof authToken === 'string' && authToken.length > 0 ? authToken : headerToken;
    if (typeof token !== 'string' || !token || token.length > 8192) {
      const error = new Error('Authentication required');
      error.data = { code: 'UNAUTHORIZED' };
      return next(error);
    }
    try {
      const auth = normalizeIds(await laravel(token, '/chat/socket/authorize', {}));
      if (!isId(auth.userId)) throw new Error('Invalid authorization response');
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
    let sessionCheck;
    const scheduleSessionCheck = (delayMs = socket.data.sessionMs) => {
      sessionCheck = setTimeout(async () => {
        if (!socket.connected) return;
        try {
          const auth = normalizeIds(await laravel(socket.data.token, '/chat/socket/authorize', {}));
          if (auth.userId !== socket.data.userId || !Number.isSafeInteger(auth.authVersion)) {
            const error = new Error('Session authorization changed');
            error.status = 403;
            throw error;
          }
          if (!socket.connected) return;
          socket.data.authVersion = auth.authVersion;
          socket.data.sessionMs = Math.min(900000, Math.max(1000, (auth.expiresInSeconds || 900) * 1000));
          scheduleSessionCheck();
        } catch (error) {
          if (!socket.connected) return;
          if (error.status === 401 || error.status === 403) {
            socket.emit('session.expired');
            socket.disconnect(true);
            return;
          }
          // A temporary API/network failure is not proof that the user's bearer expired.
          scheduleSessionCheck(Math.min(socket.data.sessionMs, 30000));
        }
      }, delayMs);
      sessionCheck.unref();
    };
    scheduleSessionCheck();
    socket.on('disconnect', () => clearTimeout(sessionCheck));

    let windowStart = Date.now();
    let eventCount = 0;
    let inFlight = 0;
    const limited = () => {
      if (Date.now() - windowStart >= 10000) { windowStart = Date.now(); eventCount = 0; }
      return ++eventCount > 30 || inFlight >= 4;
    };
    const operation = (handler, requiresConversation = true) => async (payload, callback) => {
      payload = normalizeIds(payload);
      const ack = typeof callback === 'function' ? callback : () => {};
      if (limited()) return ack({ ok: false, status: 429, error: 'Too many requests' });
      inFlight++;
      try {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload) || (requiresConversation && !isId(payload.conversationId))) return ack({ ok: false, status: 422, error: 'Invalid conversationId' });
        const result = await handler(payload);
        if (socket.connected) ack({ ok: true, ...result });
      } catch (error) {
        ack({ ok: false, status: error.status || 503, error: error.message, errors: error.details, ...(error.code === 'CHAT_BLOCKED' ? { code: 'CHAT_BLOCKED' } : {}) });
      } finally {
        inFlight--;
      }
    };
    const feedOperation = (handler) => async (payload, callback) => {
      payload = normalizeIds(payload);
      const ack = typeof callback === 'function' ? callback : () => {};
      if (limited()) return ack({ ok: false, status: 429, error: 'Too many requests' });
      inFlight++;
      try {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload) || !isId(payload.postId)) {
          return ack({ ok: false, status: 422, error: 'Invalid postId' });
        }
        const result = await handler(payload);
        if (socket.connected) ack({ ok: true, ...result });
      } catch (error) {
        ack({ ok: false, status: error.status || 503, error: error.message, errors: error.details });
      } finally {
        inFlight--;
      }
    };

    socket.on('chat.ping', (_payload, callback) => {
      if (typeof callback !== 'function') return;
      if (limited()) return callback({ ok: false, status: 429, error: 'Too many requests' });
      callback({ ok: true, serverTime: new Date().toISOString() });
    });

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
    socket.on('feed:join', feedOperation(async ({ postId }) => {
      const room = 'feed-post.' + postId;
      if (!socket.rooms.has(room) && socket.rooms.size >= 52) {
        const error = new Error('Leave another room before joining more feed posts');
        error.status = 429;
        throw error;
      }
      const auth = normalizeIds(await laravel(socket.data.token, '/feed/socket/authorize', { postId }));
      if (auth.userId !== socket.data.userId || auth.postId !== postId || auth.room !== room) {
        const error = new Error('Invalid feed room authorization');
        error.status = 403;
        throw error;
      }
      if (socket.connected) await socket.join(room);
      return { room };
    }));
    socket.on('feed:leave', feedOperation(async ({ postId }) => {
      await socket.leave('feed-post.' + postId);
      return {};
    }));
    const requireFeedRoom = (postId) => {
      if (socket.rooms.has('feed-post.' + postId)) return;
      const error = new Error('Join this feed post before sending actions');
      error.status = 403;
      throw error;
    };
    socket.on('feed:comment', feedOperation(async ({ postId, body }) => {
      requireFeedRoom(postId);
      if (typeof body !== 'string' || !body.trim() || Array.from(body).length > 5000) {
        const error = new Error('Comment body must contain 1–5000 characters');
        error.status = 422;
        throw error;
      }
      return laravel(socket.data.token, '/feed/' + postId + '/comments', { body });
    }));
    socket.on('feed:reply', feedOperation(async ({ postId, parentCommentId, body }) => {
      requireFeedRoom(postId);
      if (!isId(parentCommentId)) {
        const error = new Error('Invalid parentCommentId');
        error.status = 422;
        throw error;
      }
      if (typeof body !== 'string' || !body.trim() || Array.from(body).length > 5000) {
        const error = new Error('Reply body must contain 1–5000 characters');
        error.status = 422;
        throw error;
      }
      return laravel(socket.data.token, '/feed/' + postId + '/comments', { parentCommentId, body });
    }));
    socket.on('feed:post.like', feedOperation(async ({ postId }) => {
      requireFeedRoom(postId);
      return laravel(socket.data.token, '/feed/' + postId + '/like', {});
    }));
    socket.on('feed:post.unlike', feedOperation(async ({ postId }) => {
      requireFeedRoom(postId);
      return laravel(socket.data.token, '/feed/' + postId + '/like', {}, 'DELETE');
    }));
    socket.on('feed:comment.like', feedOperation(async ({ postId, commentId }) => {
      requireFeedRoom(postId);
      if (!isId(commentId)) {
        const error = new Error('Invalid commentId');
        error.status = 422;
        throw error;
      }
      return laravel(socket.data.token, '/feed/comments/' + commentId + '/like', { postId });
    }));
    socket.on('feed:comment.unlike', feedOperation(async ({ postId, commentId }) => {
      requireFeedRoom(postId);
      if (!isId(commentId)) {
        const error = new Error('Invalid commentId');
        error.status = 422;
        throw error;
      }
      return laravel(socket.data.token, '/feed/comments/' + commentId + '/like', { postId }, 'DELETE');
    }));
    const chatActions = {
      send: ['conversationId', 'body', 'replyToId'], delivered: ['conversationId', 'messageId'],
      read: ['conversationId', 'messageId'], typing: ['conversationId'], stop_typing: ['conversationId'],
      delete: ['conversationId', 'messageId'], edit: ['conversationId', 'messageId', 'body'],
      reply: ['conversationId', 'replyToId', 'body'], reaction: ['conversationId', 'messageId', 'reaction'],
      history: ['conversationId', 'perPage', 'cursor'], unread_count: ['conversationId'],
    };
    for (const [action, fields] of Object.entries(chatActions)) {
      socket.on('chat:' + action, operation(async (payload) => {
        const body = Object.fromEntries(fields.filter((key) => Object.hasOwn(payload, key)).map((key) => [key, payload[key]]));
        return laravel(socket.data.token, '/chat/events/' + action, body);
      }, action !== 'unread_count'));
    }
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
