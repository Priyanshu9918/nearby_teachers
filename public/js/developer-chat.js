/* Developer portal Socket.IO tester. Credentials stay in page memory. */
(() => {
    'use strict';
    const field = (id) => document.getElementById('chat-' + id);
    if (!field('tester')) return;
    const url = field('url');
    const path = field('path');
    const token = field('token');
    const conversation = field('conversation');
    const body = field('body');
    const lines = [];
    let socket;
    url.value = window.location.origin;

    function log(event, data = {}) {
        let detail = JSON.stringify(data, null, 2);
        // Never print the bearer even if a server error echoes it.
        if (token.value.trim()) detail = detail.split(token.value.trim()).join('[redacted]');
        lines.push(`${new Date().toISOString()} ${event}\n${detail.slice(0, 12000)}`);
        if (lines.length > 150) lines.shift();
        field('log').textContent = lines.join('\n\n');
        field('log').scrollTop = field('log').scrollHeight;
    }

    function state(label, active) {
        field('status').textContent = label;
        document.querySelectorAll('[data-chat-connected]').forEach((button) => {
            button.disabled = !socket?.connected;
        });
        field('connect').disabled = active;
        field('disconnect').disabled = !active;
        [url, path, token, field('use-token')].forEach((input) => { input.disabled = active; });
    }

    function options() {
        const endpoint = new URL(url.value.trim());
        if (!['http:', 'https:'].includes(endpoint.protocol) || endpoint.username || endpoint.password ||
            endpoint.pathname !== '/' || endpoint.search || endpoint.hash) {
            throw new Error('Enter only the http(s) origin. Set the Socket.IO path separately.');
        }
        if (window.location.protocol === 'https:' && endpoint.protocol !== 'https:') {
            throw new Error('Use an HTTPS Socket.IO endpoint from this HTTPS page.');
        }
        const socketPath = path.value.trim();
        if (!socketPath.startsWith('/') || socketPath.startsWith('//') || /[?#\s]/.test(socketPath)) {
            throw new Error('Enter a Socket.IO path such as /socket.io/.');
        }
        return { origin: endpoint.origin, path: socketPath };
    }

    function emit(event, payload) {
        if (!socket?.connected) return log('error', { error: 'Connect first.' });
        const current = socket;
        const started = performance.now();
        log('→ ' + event, payload);
        current.timeout(7000).emit(event, payload, (error, ack) => {
            if (socket !== current) return;
            if (error) {
                return log('ACK timeout: ' + event, {
                    error: 'No acknowledgement. This does not prove the operation failed. Check history before resending.',
                });
            }
            log('← ACK ' + event, { roundTripMs: Math.round(performance.now() - started), ack });
        });
    }

    field('connect').addEventListener('click', () => {
        try {
            const config = options();
            if (!token.value.trim()) throw new Error('Paste a user login token first.');
            if (typeof window.io !== 'function') throw new Error('Socket.IO client could not load. Check deployed public assets.');
            if (socket) { socket.removeAllListeners(); socket.disconnect(); }
            socket = window.io(config.origin, {
                path: config.path, auth: { token: token.value.trim() },
                transports: ['websocket'], autoConnect: false, forceNew: true,
                reconnection: true, reconnectionAttempts: 3, timeout: 7000,
            });
            socket.on('connect', () => {
                state('Connected', true);
                log('connect', { socketId: socket.id, note: 'Load REST history and rejoin the active conversation after reconnecting.' });
            });
            socket.on('connect_error', (error) => {
                log('connect_error', { error: error.message, code: error.data?.code });
                state(socket.active ? 'Reconnecting' : 'Connection failed', socket.active);
            });
            socket.on('disconnect', (reason) => {
                log('disconnect', { reason });
                state(socket.active ? 'Reconnecting' : 'Disconnected', socket.active);
            });
            socket.on('message.sent', (payload) => log('← message.sent', payload));
            socket.on('session.expired', () => log('← session.expired', { action: 'Reconnect with a valid login token.' }));
            socket.io.on('reconnect_failed', () => state('Disconnected: retries exhausted', false));
            state('Connecting', true);
            log('connecting', config);
            socket.connect();
        } catch (error) { log('error', { error: error.message }); }
    });

    field('disconnect').addEventListener('click', () => { socket?.disconnect(); state('Disconnected', false); });
    field('use-token').addEventListener('click', () => {
        token.value = document.getElementById('bearer-token')?.value.trim() || '';
        log('token', { copied: Boolean(token.value) });
    });
    field('ping').addEventListener('click', () => emit('chat.ping', {}));
    for (const [button, event] of [['join', 'conversation.join'], ['leave', 'conversation.leave'], ['send', 'message.send']]) {
        field(button).addEventListener('click', () => {
            const conversationId = conversation.value.trim();
            if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(conversationId)) {
                return log('error', { error: 'Enter a valid conversation UUID.' });
            }
            const payload = { conversationId };
            if (event === 'message.send') {
                if (!body.value.trim() || Array.from(body.value).length > 5000) {
                    return log('error', { error: 'Message must contain 1–5000 characters.' });
                }
                payload.body = body.value;
            }
            emit(event, payload);
        });
    }
    field('clear').addEventListener('click', () => { lines.length = 0; field('log').textContent = 'Log cleared.'; });
    field('copy-example').addEventListener('click', async () => {
        try {
            const config = options();
            const example = `import { io } from 'socket.io-client';\nconst socket = io(${JSON.stringify(config.origin)}, {\n  path: ${JSON.stringify(config.path)},\n  auth: { token: userLoginToken },\n  transports: ['websocket'],\n  autoConnect: false\n});\nsocket.on('message.sent', ({ message }) => { /* Deduplicate by message.id */ });\nsocket.on('session.expired', () => { /* Reconnect with a valid token */ });\nsocket.on('connect', () => { /* Reload REST history and rejoin */ });\nsocket.connect();\n// After connecting:\n// socket.timeout(7000).emit('chat.ping', {}, (err, ack) => console.log(err, ack));\n// socket.timeout(7000).emit('conversation.join', { conversationId }, (err, ack) => console.log(err, ack));\n// socket.timeout(7000).emit('message.send', { conversationId, body: 'Hello' }, (err, ack) => console.log(err, ack));\n// socket.emit('conversation.leave', { conversationId });`;
            await navigator.clipboard.writeText(example);
            log('example copied', { note: 'Includes public connection settings and a token placeholder.' });
        } catch (error) { log('error', { error: error.message }); }
    });
    window.addEventListener('pagehide', () => socket?.disconnect());
})();
