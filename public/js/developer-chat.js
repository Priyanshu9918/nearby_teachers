/* Developer-only chat diagnostics. No credentials or diagnostic logs are persisted. */
(() => {
    'use strict';
    const field = (id) => document.getElementById('chat-' + id);
    const tester = field('tester');
    if (!tester) return;
    const token = field('token');
    const validId = (value) => /^[1-9][0-9]*$/.test(String(value)) && Number.isSafeInteger(Number(value));
    const exampleConversationId = 4;
    const message = {id: 5, conversation_id: exampleConversationId, sender_id: 1, body: 'Example message', created_at: '2026-10-23 10:00:00'};
    const operations = {
        health: ['Check chat server', 'GET /developer/socket/health', {}, {ok: true, service: 'Socket.IO'}, 'Checks the configured internal Node/Redis readiness. Does not test the public proxy or database.'],
        authorize: ['Verify user token', 'POST /chat/socket/authorize', {}, {userId: message.sender_id, room: null, expiresInSeconds: 900, authVersion: 0}, 'Uses the real user bearer. Requires the same verified account access as the app.'],
        connect: ['Connect socket', 'Socket.IO connect', {auth: {token: '[user login token]'}, transports: ['websocket']}, {socketId: 'fictional-socket-id', transport: 'websocket'}, 'Opens the public socket and authenticates through Laravel.'],
        ping: ['Ping / round trip', 'chat.ping', {}, {ok: true, serverTime: '2026-10-23T10:00:00.000Z'}, 'An authenticated round trip to Node. Auto ping runs every 5 seconds and stops on disconnect.'],
        reconnect: ['Reconnect lifecycle (automatic)', 'reconnect_attempt / reconnect / reconnect_error / reconnect_failed', {}, {event: 'reconnect', attempt: 1, action: 'Reload history and rejoin the active conversation.'}, 'Up to 3 automatic retries after transport failure. Server disconnect/expiry needs an explicit reconnect with a valid token.'],
        heartbeat: ['Transport heartbeat (automatic)', 'Engine.IO ping → pong', {}, {packet: 'pong', note: 'Transport heartbeat reply; not a message acknowledgement.'}, 'The server sends heartbeats automatically. These are separate from chat.ping and are logged while connected.'],
        start: ['Start / find conversation', 'POST /messages/start', {providerId: 2}, {conversationId: exampleConversationId}, 'Any account can start or reuse a conversation with another user. providerId is the other user ID; this tester sends no initial body.'],
        inbox: ['Load my inbox', 'GET /messages?perPage=20', {}, {conversations: [{id: exampleConversationId, other_name: 'Example Teacher', last_message: 'Hello'}], nextCursor: null}, 'Shows a bounded inbox page. Pick a conversation to fill its ID.'],
        history: ['Load message history', 'GET /messages/{conversationId}?perPage=20', {}, {messages: [message], nextCursor: null}, 'Loads the latest saved messages for this participant. No read-receipt event exists.'],
        'room-auth': ['Check conversation access', 'POST /chat/socket/authorize', {conversationId: exampleConversationId}, {userId: message.sender_id, room: 'private-conversation.' + exampleConversationId, expiresInSeconds: 900, authVersion: 0}, 'Confirms that the bearer is a participant before joining.'],
        join: ['Join conversation', 'conversation.join', {conversationId: exampleConversationId}, {ok: true, room: 'private-conversation.' + exampleConversationId}, 'Joins an authorized conversation room. User-room delivery is automatic after connecting.'],
        send: ['Send via socket', 'message.send', {conversationId: exampleConversationId, body: 'Example message'}, {ok: true, message}, 'Saves a real message through Laravel. ACK confirms persistence; the queue delivers message.sent separately.'],
        'rest-send': ['Send via REST', 'POST /messages/{conversationId}', {body: 'Example message'}, {message}, 'Alternative send path; also saves and broadcasts a real message. Do not use both sends for the same test.'],
        receive: ['Receive a live message (listen only)', 'message.sent', {}, {message}, 'Delivered to both participants. Deduplicate by message.id. A second tab tests the other device.'],
        leave: ['Leave conversation', 'conversation.leave', {conversationId: exampleConversationId}, {ok: true}, 'Leaves the selected conversation room. The socket remains connected to its private user room.'],
        expired: ['Session expired (listen only)', 'session.expired', {}, {action: 'Reconnect with a valid login token.'}, 'Server sessions last at most 15 minutes. Observe expiry; the tester cannot emit or force it.'],
        error: ['Connection / access errors', 'connect_error or failed ACK', {}, {ok: false, status: 404, error: 'Conversation not found'}, '401/403: check login/access. 404: check membership. 422: check input. 429: slow down. 503: check API/Node. ACK timeout: check history before retrying.'],
        disconnect: ['Disconnect / end test', 'socket.disconnect()', {}, {reason: 'io client disconnect'}, 'Closes this connection and stops auto ping. It does not delete the conversation or revoke the login token.'],
    };
    const chatContracts = JSON.parse(field('event-contracts')?.textContent || '{}');
    for (const [event, contract] of Object.entries(chatContracts)) {
        if (Array.isArray(contract.body)) contract.body = {};
        operations[event] = [contract.label, event, contract.body, {ok: true, ...contract.mock}, contract.guide];
    }
    const views = {
        overview: ['Getting started', 'Test live chat one step at a time. Choose a task below or from the WebSockets sidebar.', Object.keys(operations)],
        connection: ['Connect & verify', 'Start here: check your chat service, verify a user token and connect.', ['health', 'authorize', 'connect', 'error']],
        ping: ['Ping & heartbeat', 'Check connectivity and round-trip time without sending a chat message.', ['ping', 'heartbeat', 'error']],
        conversations: ['Conversations', 'Find a conversation, check your access and join its live room.', ['inbox', 'start', 'room-auth', 'join', 'error']],
        send: ['Send a message', 'Send one test message through Socket.IO or the REST API.', ['send', 'rest-send', 'error']],
        receive: ['Receive messages', 'Watch incoming messages from the other participant in real time.', ['receive', 'error']],
        events: ['Chat events', 'Run the named chat events or inspect incoming notifications. Every event has a fictional preview.', Object.keys(chatContracts)],
        history: ['Saved history', 'Confirm which messages were saved for your conversation.', ['history', 'error']],
        session: ['Session & reconnect', 'Understand reconnect attempts, errors and session expiry.', ['reconnect', 'expired', 'error']],
        end: ['Leave & end test', 'Leave a room or close the connection when your test is finished.', ['leave', 'disconnect', 'error']],
        full: ['Run full check', 'Run a guided check with a result for each step. Sending is optional.', Object.keys(operations)],
    };
    let selectedView = 'overview', selectedOperation = 'health';
    const latestResults = new Map();
    const viewOperations = new Map();
    const lines = [];
    const secrets = new Set();
    const deliveries = new Map();
    let socket, pingTimer, pingBusy = false, running = false, manualBusy = false, flowController;
    let sent = 0, received = 0, lastPing = '—';
    field('url').value = window.location.origin;
    const apiBase = new URL(tester.dataset.apiBase, window.location.origin);

    function clean(data) {
        if (token.value.trim()) secrets.add(token.value.trim());
        return JSON.stringify(data, (key, value) => {
            if (/token|password|secret|authorization|otp|consent/i.test(key)) return '[redacted]';
            if (typeof value === 'string') {
                for (const secret of secrets) value = value.split(secret).join('[redacted]');
            }
            return value;
        }, 2);
    }
    function log(event, data = {}) {
        lines.push(`${new Date().toISOString()} ${event}\n${clean(data).slice(0, 12000)}`);
        if (lines.length > 150) lines.shift();
        field('log').textContent = lines.join('\n\n');
        field('log').scrollTop = field('log').scrollHeight;
    }
    function paintResult(label, data, mock) {
        field('result-label').textContent = (mock ? 'Mock response · fictional · ' : 'Actual result · ') + label;
        field('result').textContent = clean(data).slice(0, 24000);
    }
    function result(label, data, mock = false, key = selectedOperation) {
        if (!mock) latestResults.set(key, {label, data});
        if (key === selectedOperation || (selectedView === 'full' && (running || key === 'full'))) paintResult(label, data, mock);
    }
    function selectOperation(key) {
        const op = operations[key];
        selectedOperation = key;
        if (views[selectedView][2].includes(key)) viewOperations.set(selectedView, key);
        field('operation').value = key;
        field('operation-help').textContent = op[4];
        field('request-example').textContent = op[1] + '\n' + JSON.stringify(op[2], null, 2);
        updateEventFields();
    }
    function preview(key) {
        selectOperation(key);
        result(operations[key][0], operations[key][3], true);
    }
    function showOperation(key) {
        selectOperation(key);
        const previous = latestResults.get(key);
        if (previous) paintResult(previous.label, previous.data, false);
        else preview(key);
    }
    function showView(key, navigate = false, eventKey = null) {
        if (!Object.hasOwn(views, key)) key = 'overview';
        selectedView = key;
        const [title, description, keys] = views[key];
        field('view-title').textContent = title;
        field('view-description').textContent = description;
        document.querySelectorAll('[data-chat-views]').forEach((panel) => { panel.hidden = !panel.dataset.chatViews.split(' ').includes(key); });
        document.querySelectorAll('.socket-nav-links [data-chat-view]').forEach((link) => {
            const active = link.dataset.chatView === key && (key !== 'events' || link.dataset.chatEvent === (eventKey || viewOperations.get('events') || keys[0]));
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        field('operation').replaceChildren();
        for (const operation of keys) { const option = document.createElement('option'); option.value = operation; option.textContent = operations[operation][0]; field('operation').append(option); }
        showOperation(key === 'events' && keys.includes(eventKey) ? eventKey : (viewOperations.get(key) || keys[0]));
        if (key === 'events') document.querySelectorAll('.socket-event-navigation').forEach((group) => { group.open = true; });
        if (key === 'full' && latestResults.has('full')) { const summary = latestResults.get('full'); paintResult(summary.label, summary.data, false); }
        field('connection-settings').open = key === 'connection';
        field('log-panel').open = ['session', 'full'].includes(key);
        field('step-navigation').hidden = key === 'overview';
        const order = Object.keys(views), index = order.indexOf(key);
        for (const [direction, nextIndex] of [['previous', Math.max(0, index - 1)], ['next', Math.min(order.length - 1, index + 1)]]) {
            const target = order[nextIndex], link = field(direction);
            link.dataset.chatView = target;
            link.setAttribute('href', '#realtime/' + target);
            link.textContent = direction === 'previous' ? '← ' + views[target][0] : 'Next: ' + views[target][0] + ' →';
            link.hidden = direction === 'next' && index === order.length - 1;
        }
        if (navigate) {
            const navigation = field('navigation');
            if (navigation) { navigation.open = true; navigation.classList.add('is-active'); }
            document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.id === 'realtime'));
            const pageHeading = document.querySelector?.('.page-heading');
            if (pageHeading) pageHeading.hidden = true;
            document.querySelectorAll('.nav [data-tab]').forEach((link) => link.classList.toggle('active', link.dataset.chatView === key && link.dataset.tab === 'realtime' && (key !== 'events' || link.dataset.chatEvent === selectedOperation)));
            window.history.replaceState(null, '', '#realtime/' + key + (key === 'events' ? '/' + selectedOperation : ''));
            field('view-title').focus?.({preventScroll: true});
            field('view-title').scrollIntoView?.({block: 'start'});
        }
        metrics();
    }
    function showMessages() {
        const feed = field('message-feed');
        feed.replaceChildren();
        for (const packet of [...deliveries.values()].slice(-30).reverse()) {
            const message = JSON.parse(clean(packet.message));
            const card = document.createElement('article');
            card.className = 'chat-message';
            card.textContent = `${message.deleted_at ? '(message deleted)' : (message.body || '(empty message)')}\n\nFrom: ${message.sender_id || 'Participant'} · ${message.created_at || 'Received just now'}\nMessage ID: ${message.id}`;
            feed.append(card);
        }
    }
    function updateEventFields() {
        const contract = chatContracts[selectedOperation];
        if (selectedView !== 'events' || !contract) return;
        field('event-title').textContent = selectedOperation + ' · ' + contract.label;
        field('event-description').textContent = contract.guide;
        document.querySelectorAll('[data-chat-event-field]').forEach((element) => {
            element.hidden = !Object.hasOwn(contract.body, element.dataset.chatEventField) && !(selectedOperation === 'chat:history' && element.dataset.chatEventField === 'cursor');
        });
        field('event-run').textContent = contract.direction === 'server' ? (socket?.connected ? 'Listening for ' + selectedOperation : 'Connect a user to listen') : 'Run ' + selectedOperation;
        field('event-run').disabled = running || manualBusy || !socket?.connected || contract.direction === 'server';
        field('event-rest').disabled = running || manualBusy || contract.direction === 'server';
        field('event-note').textContent = contract.direction === 'server' ? 'This event is sent by the server. Connect a participant and send from another tab; the actual packet replaces the mock.' : 'REST: POST /chat/events/' + contract.action + '. Mutations affect real application data. Conversation ID is optional only for unread_count.';
    }
    function chatPayload(key) {
        const contract = chatContracts[key];
        if (!contract || contract.direction !== 'client') throw new Error('This is a server notification. Connect and listen; clients cannot publish it.');
        const payload = {};
        if (Object.hasOwn(contract.body, 'conversationId') || (key === 'chat:unread_count' && field('conversation').value.trim())) payload.conversationId = conversationId();
        for (const [name, id] of [['messageId', 'event-message'], ['replyToId', 'event-reply']]) {
            if (!Object.hasOwn(contract.body, name)) continue;
            const value = field(id).value.trim();
            if (!validId(value)) throw new Error('Enter a valid ' + name + ' from saved history.');
            payload[name] = Number(value);
        }
        if (Object.hasOwn(contract.body, 'body')) payload.body = messageBody();
        if (Object.hasOwn(contract.body, 'reaction')) payload.reaction = field('event-reaction').value || null;
        if (key === 'chat:history') { payload.perPage = Number(field('event-page').value || 20); if (field('event-cursor').value.trim()) payload.cursor = field('event-cursor').value.trim(); }
        return payload;
    }
    function metrics() {
        field('needs-connection').hidden = Boolean(socket?.connected);
        field('listening-status').textContent = socket?.connected ? 'Listening · Send from the other participant’s tab to see their message here.' : 'Not connected · Open Connect & verify to start listening.';
        field('metrics').textContent = `Socket: ${socket?.connected ? socket.id : '—'} · Transport: ${socket?.io.engine?.transport?.name || '—'} · Last ping: ${lastPing} · Sent: ${sent} · Received: ${received}`;
    }
    function refresh() {
        const active = Boolean(socket?.connected || socket?.active);
        const busy = running || manualBusy;
        document.querySelectorAll('[data-chat-action]').forEach((button) => { button.disabled = busy; });
        document.querySelectorAll('[data-chat-connected]').forEach((button) => { button.disabled = busy || !socket?.connected; });
        field('connect').disabled = busy || active;
        field('disconnect').disabled = busy || !active;
        field('run').disabled = busy;
        field('stop').disabled = !running;
        field('ping-start').disabled = busy || !socket?.connected || Boolean(pingTimer);
        field('ping-stop').disabled = !pingTimer;
        document.querySelectorAll('[data-chat-config]').forEach((input) => { input.disabled = busy || active; });
        document.querySelectorAll('[data-chat-flow-input]').forEach((input) => { input.disabled = busy; });
        metrics();
        updateEventFields();
    }
    function state(label) { field('status').textContent = label; refresh(); }
    function stopPing() {
        clearInterval(pingTimer); pingTimer = null; refresh();
    }
    function bearer() {
        const value = token.value.trim();
        if (!value) throw new Error('Paste a real user login token first.');
        secrets.add(value);
        return value;
    }
    function conversationId() {
        const value = field('conversation').value.trim();
        if (!validId(value)) throw new Error('Enter a valid conversation ID, or load your inbox and choose one.');
        return Number(value);
    }
    function messageBody() {
        const value = field('body').value;
        if (!value.trim() || Array.from(value).length > 5000) throw new Error('Message must contain 1–5000 characters.');
        return value;
    }
    function options() {
        let endpoint;
        try { endpoint = new URL(field('url').value.trim()); }
        catch { throw new Error('Enter a full Socket.IO origin, for example ' + window.location.origin + '.'); }
        if (!['http:', 'https:'].includes(endpoint.protocol) || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) throw new Error('Enter only an http(s) origin; set the Socket.IO path separately.');
        if (endpoint.pathname !== '/') throw new Error('Remove the application path from Socket server URL. Use ' + endpoint.origin + ' and put the WebSocket proxy path in Socket path (usually /socket.io/).');
        if (window.location.protocol === 'https:' && endpoint.protocol !== 'https:') throw new Error('Use an HTTPS Socket.IO endpoint from this HTTPS page.');
        const path = field('path').value.trim();
        if (!path.startsWith('/') || path.startsWith('//') || /[\\?#\s]/.test(path)) throw new Error('Enter a Socket.IO path such as /socket.io/.');
        return {origin: endpoint.origin, path};
    }
    function aborted(signal) { if (signal?.aborted) throw new Error('Check stopped. A submitted message may still have been saved; inspect history before retrying.'); }
    async function rest(key, route, method = 'GET', payload, signal) {
        aborted(signal);
        const endpoint = key === 'health' ? new URL(tester.dataset.healthUrl, window.location.origin) : new URL(route.replace(/^\//, ''), apiBase);
        if (endpoint.origin !== window.location.origin) throw new Error('REST checks must use this application origin.');
        const headers = {Accept: 'application/json'};
        if (key !== 'health') headers.Authorization = 'Bearer ' + bearer();
        if (payload !== undefined) headers['Content-Type'] = 'application/json';
        result(operations[key][0], {state: 'Waiting for API response…'}, false, key);
        log('→ ' + operations[key][1], payload || {});
        const controller = new AbortController();
        const cancel = () => controller.abort();
        signal?.addEventListener('abort', cancel, {once: true});
        const timer = setTimeout(cancel, 10000);
        try {
            const response = await fetch(endpoint.href, {method, headers, body: payload === undefined ? undefined : JSON.stringify(payload), credentials: 'same-origin', redirect: 'error', signal: controller.signal});
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); } catch { throw new Error('Expected JSON. Check the API base URL and developer login session.'); }
            aborted(signal);
            result(`${operations[key][0]} · HTTP ${response.status}`, data, false, key);
            log('← HTTP ' + response.status + ' ' + key, data);
            if (!response.ok || (key === 'health' && data.ok !== true)) {
                const error = new Error(`HTTP ${response.status}: ${data.error || data.message || data.note || 'Request rejected'}`);
                error.responseData = data;
                error.httpStatus = response.status;
                throw error;
            }
            return data;
        } finally { clearTimeout(timer); signal?.removeEventListener('abort', cancel); }
    }
    function emit(key, payload, signal) {
        aborted(signal);
        if (!socket?.connected) throw new Error('Connect the socket first.');
        const current = socket, started = performance.now(), event = operations[key][1];
        result(operations[key][0], {state: 'Waiting for socket acknowledgement…'}, false, key);
        log('→ ' + event, payload);
        return new Promise((resolve, reject) => {
            const cancel = () => reject(new Error('Check stopped. Inspect history before retrying a send.'));
            signal?.addEventListener('abort', cancel, {once: true});
            current.timeout(7000).emit(event, payload, (error, ack) => {
                signal?.removeEventListener('abort', cancel);
                if (signal?.aborted) return;
                if (socket !== current || !current.connected) return reject(new Error('Connection changed before acknowledgement. Check history before retrying.'));
                if (error) return reject(new Error('ACK timeout: no acknowledgement. Check saved history before resending.'));
                const roundTripMs = Math.round(performance.now() - started);
                log('← ACK ' + event, {roundTripMs, ack});
                result(`${event} · ${roundTripMs} ms`, ack, false, key);
                if (!ack || ack.ok !== true) return reject(new Error(`${ack?.status || 'Error'}: ${ack?.error || 'Operation rejected'}`));
                if (key === 'ping') lastPing = roundTripMs + ' ms';
                if (['send', 'chat:send', 'chat:reply'].includes(key)) sent++;
                metrics(); resolve(ack);
            });
        });
    }
    function disconnect() {
        stopPing();
        socket?.disconnect();
        state('Disconnected');
    }
    function connect(signal) {
        aborted(signal);
        const config = options(), accessToken = bearer();
        if (typeof window.io !== 'function') throw new Error('Socket.IO client could not load. Check deployed public assets.');
        stopPing();
        if (socket) { socket.removeAllListeners(); socket.io.removeAllListeners(); socket.disconnect(); }
        const current = socket = window.io(config.origin, {path: config.path, auth: {token: accessToken}, transports: ['websocket'], autoConnect: false, forceNew: true, reconnection: true, reconnectionAttempts: 3, timeout: 7000});
        const valid = () => socket === current;
        current.on('connect', () => {
            if (!valid()) return;
            state('Connected');
            const data = {socketId: current.id, transport: current.io.engine?.transport?.name, note: 'Load REST history and rejoin the active conversation after reconnecting.'};
            log('connect', data); result('Connected', data, false, 'connect');
            const engine = current.io.engine;
            engine?.on('packet', (packet) => { if (valid() && packet.type === 'ping') { log('← heartbeat ping', {note: 'Engine.IO will reply automatically.'}); result('Heartbeat ping', {packet: 'ping', automatic: true}, false, 'heartbeat'); } });
            engine?.on('packetCreate', (packet) => { if (valid() && packet.type === 'pong') { log('→ heartbeat pong', {}); result('Heartbeat pong', {packet: 'pong', automatic: true}, false, 'heartbeat'); } });
        });
        current.on('connect_error', (error) => {
            if (!valid()) return;
            log('connect_error', {error: error.message, code: error.data?.code});
            result('Connection error', {error: error.message, code: error.data?.code}, false, 'connect');
            state(current.active ? 'Reconnecting' : 'Connection failed');
        });
        current.on('disconnect', (reason) => {
            if (!valid()) return;
            stopPing(); log('disconnect', {reason}); result('Disconnected', {reason}, false, 'disconnect');
            state(current.active ? 'Reconnecting' : 'Disconnected');
        });
        current.on('message.sent', (payload) => {
            if (!valid()) return;
            const id = payload?.message?.id;
            const previous = deliveries.get(id)?.message;
            if (previous && Number(payload.message.revision || 1) < Number(previous.revision || 1)) return;
            const duplicate = id && deliveries.has(id);
            if (id) { deliveries.set(id, payload); if (deliveries.size > 100) deliveries.delete(deliveries.keys().next().value); }
            if (!duplicate) received++; metrics(); showMessages(); log('← message.sent', payload); result('Live message received', payload, false, 'receive');
        });
        for (const event of Object.keys(chatContracts)) {
            if (['chat:send', 'chat:history', 'chat:unread_count'].includes(event)) continue;
            current.on(event, (payload) => {
                if (!valid()) return;
                const previous = deliveries.get(payload?.message?.id)?.message;
                if (previous && Number(payload.message.revision || 1) < Number(previous.revision || 1)) return;
                if (['chat:typing', 'chat:stop_typing'].includes(event) && Date.parse(payload?.expiresAt) < Date.now()) { log('Expired typing hint ignored', {event}); return; }
                if (['chat:receive', 'chat:new', 'chat:reply'].includes(event) && payload?.message?.id) {
                    if (!deliveries.has(payload.message.id)) received++;
                    deliveries.set(payload.message.id, payload);
                    if (deliveries.size > 100) deliveries.delete(deliveries.keys().next().value);
                    showMessages(); metrics();
                }
                if (['chat:edit', 'chat:delete'].includes(event) && payload?.message?.id) {
                    deliveries.set(payload.message.id, {message: payload.message}); showMessages();
                }
                log('← ' + event, payload); result(event, payload, false, event);
            });
        }
        current.on('session.expired', () => { if (valid()) { stopPing(); log('← session.expired', operations.expired[3]); result('Session expired', operations.expired[3], false, 'expired'); } });
        for (const event of ['reconnect_attempt', 'reconnect', 'reconnect_error', 'reconnect_failed']) {
            current.io.on(event, (detail) => {
                if (!valid()) return;
                log(event, {detail: detail instanceof Error ? detail.message : detail});
                result('Connection lifecycle', {event, detail: detail instanceof Error ? detail.message : detail}, false, 'reconnect');
                if (event === 'reconnect_failed') { current.disconnect(); stopPing(); state('Disconnected: retries exhausted'); }
            });
        }
        result('Connect', {state: 'Connecting…'}, false, 'connect'); state('Connecting'); log('connecting', config);
        return new Promise((resolve, reject) => {
            let timer;
            const cleanup = () => { clearTimeout(timer); current.off('connect', success); current.off('connect_error', failure); current.off('disconnect', closed); signal?.removeEventListener('abort', cancel); };
            const success = () => { cleanup(); resolve(); };
            const failure = (error) => { cleanup(); reject(new Error(error.message)); };
            const closed = () => { cleanup(); reject(new Error('Disconnected before the connection completed.')); };
            const cancel = () => { cleanup(); current.disconnect(); reject(new Error('Check stopped.')); };
            current.once('connect', success); current.once('connect_error', failure); current.once('disconnect', closed);
            signal?.addEventListener('abort', cancel, {once: true});
            timer = setTimeout(() => { cleanup(); current.disconnect(); reject(new Error('Connection timed out. Check the public origin, proxy and allowed browser origins.')); }, 10000);
            current.connect();
        });
    }
    function populateInbox(data) {
        field('inbox-select').replaceChildren();
        const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Choose a conversation'; field('inbox-select').append(placeholder);
        for (const item of data.conversations || []) {
            const option = document.createElement('option'); option.value = item.id; option.textContent = `${item.other_name || 'Participant'} · ${item.id}`; field('inbox-select').append(option);
        }
    }
    async function perform(key, signal) {
        if (chatContracts[key]) return emit(key, chatPayload(key), signal);
        switch (key) {
            case 'health': return rest(key, '', 'GET', undefined, signal);
            case 'authorize': return rest(key, '/chat/socket/authorize', 'POST', {}, signal);
            case 'connect': return connect(signal);
            case 'disconnect': disconnect(); result('Disconnected', {disconnected: true, note: 'This socket is closed. Your account token is unchanged.'}, false, 'disconnect'); return;
            case 'ping': return emit(key, {}, signal);
            case 'inbox': { const data = await rest(key, '/messages?perPage=20', 'GET', undefined, signal); populateInbox(data); return data; }
            case 'start': {
                const providerId = field('provider').value.trim();
                if (!validId(providerId)) throw new Error('Enter the other user ID first.');
                const data = await rest(key, '/messages/start', 'POST', {providerId: Number(providerId)}, signal);
                field('conversation').value = data.conversationId; return data;
            }
            case 'room-auth': return rest(key, '/chat/socket/authorize', 'POST', {conversationId: conversationId()}, signal);
            case 'history': return rest(key, '/messages/' + conversationId() + '?perPage=20', 'GET', undefined, signal);
            case 'rest-send': { const data = await rest(key, '/messages/' + conversationId(), 'POST', {body: messageBody()}, signal); sent++; metrics(); return data; }
            default: return emit(key, {conversationId: conversationId(), ...(key === 'send' ? {body: messageBody()} : {})}, signal);
        }
    }
    function fail(error, key = selectedOperation) {
        log('error', {error: error.message});
        result(error.httpStatus ? `HTTP ${error.httpStatus}` : 'Error', error.responseData ?? {error: error.message}, false, key);
    }
    async function manual(key) {
        if (running || manualBusy) return;
        manualBusy = true; selectOperation(key); refresh();
        try { await perform(key); } catch (error) { fail(error, key); } finally { manualBusy = false; refresh(); }
    }
    async function waitForDelivery(id, signal) {
        const deadline = Date.now() + 7000;
        while (!deliveries.has(id)) {
            aborted(signal);
            if (Date.now() >= deadline) throw new Error('Message was saved but no matching live event arrived within 7 seconds. Check the broadcasts worker; do not resend automatically.');
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        return deliveries.get(id);
    }
    async function runFlow() {
        if (running || manualBusy) return;
        if (selectedView !== 'full') showView('full', true);
        stopPing();
        const sendMessage = field('flow-send').checked;
        try { bearer(); options(); conversationId(); if (sendMessage) messageBody(); } catch (error) { return fail(error); }
        running = true; flowController = new AbortController(); refresh();
        const signal = flowController.signal;
        field('checklist').replaceChildren();
        const steps = ['health', 'authorize', 'connect', 'ping', 'room-auth', 'join', ...(sendMessage ? ['send', 'receive'] : []), 'history', 'leave', 'disconnect'];
        const rows = new Map();
        for (const key of steps) { const row = document.createElement('li'); row.textContent = operations[key][0] + ' · Waiting'; rows.set(key, row); field('checklist').append(row); }
        let savedMessage, joined = false, failed = false, failureError;
        try {
            for (const key of steps) {
                aborted(signal);
                if (selectedView === 'full') selectOperation(key);
                const row = rows.get(key); row.textContent = operations[key][0] + ' · Running…';
                try {
                    const data = key === 'receive' ? await waitForDelivery(savedMessage.id, signal) : await perform(key, signal);
                    if (key === 'send') { savedMessage = data.message; if (!savedMessage?.id) throw new Error('Send acknowledged without a message ID. Inspect history before retrying.'); }
                    if (key === 'join') joined = true;
                    if (key === 'leave') joined = false;
                    if (key === 'history' && savedMessage && !(data.messages || []).some((item) => item.id === savedMessage.id)) throw new Error('Sent message was not in the latest history page. Inspect history before retrying.');
                    row.textContent = operations[key][0] + ' · Passed';
                } catch (error) {
                    row.textContent = operations[key][0] + ' · Failed: ' + error.message;
                    if (key === 'receive' && !signal.aborted) { failed = true; failureError = error; log('Delivery check failed', {error: error.message}); continue; }
                    throw error;
                }
            }

        } catch (error) { failed = true; failureError = error; fail(error, 'full'); }
        finally {
            // Local disconnect also removes room membership, including after cancellation.
            if (joined) log('cleanup', {note: 'Disconnecting also leaves the joined room.'});
            disconnect(); running = false; flowController = null; refresh();
            for (const [key, row] of rows) if (row.textContent.endsWith(' · Waiting')) row.textContent = operations[key][0] + ' · Skipped';
            if (failed) {
                const data = {ok: false, error: failureError?.message, disconnected: true, note: 'Check the failed step above. Inspect history before resending.'};
                log('Full check ended', data); result('Full check failed / stopped', data, false, 'full');
            }
            else result('Full check passed', {ok: true, messageSent: Boolean(savedMessage), disconnected: true, note: 'ACK, live event and history are separate checks. Verify the other participant in a second tab.'}, false, 'full');
        }
    }
    document.querySelectorAll('[data-chat-view]').forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); showView(link.dataset.chatView, true, link.dataset.chatEvent); }));
    window.addEventListener('hashchange', () => { if (window.location.hash.startsWith('#realtime')) showView(window.location.hash.split('/')[1] || 'overview', true, window.location.hash.split('/')[2]); });
    for (const key of ['health', 'authorize', 'connect', 'disconnect', 'ping', 'start', 'inbox', 'history', 'room-auth', 'join', 'send', 'rest-send', 'leave']) field(key).addEventListener('click', () => manual(key));
    field('event-run').addEventListener('click', () => manual(selectedOperation));
    field('event-rest').addEventListener('click', async () => {
        if (running || manualBusy) return;
        const key = selectedOperation;
        manualBusy = true; refresh();
        try { const data = await rest(key, '/chat/events/' + chatContracts[key].action, 'POST', chatPayload(key)); if (['chat:send', 'chat:reply'].includes(key)) { sent++; metrics(); } }
        catch (error) { fail(error, key); } finally { manualBusy = false; refresh(); }
    });
    field('operation').addEventListener('change', () => { if (selectedView === 'events') showView('events', true, field('operation').value); else showOperation(field('operation').value); });
    field('show-mock').addEventListener('click', () => preview(field('operation').value));
    field('inbox-select').addEventListener('change', () => { if (!running && field('inbox-select').value) field('conversation').value = field('inbox-select').value; });
    field('use-token').addEventListener('click', () => { token.value = document.getElementById('bearer-token')?.value.trim() || ''; log('token selected', {available: Boolean(token.value)}); });
    field('ping-start').addEventListener('click', () => {
        if (running || manualBusy || !socket?.connected || pingTimer) return;
        pingTimer = setInterval(async () => { if (running || manualBusy || pingBusy || !socket?.connected) return; pingBusy = true; try { await perform('ping'); } catch (error) { fail(error); stopPing(); } finally { pingBusy = false; } }, 5000);
        log('Auto ping started', {intervalSeconds: 5}); refresh();
    });
    field('ping-stop').addEventListener('click', () => { stopPing(); log('Auto ping stopped'); });
    field('run').addEventListener('click', runFlow);
    field('stop').addEventListener('click', () => { flowController?.abort(); disconnect(); });
    field('clear').addEventListener('click', () => { lines.length = 0; field('log').textContent = 'Log cleared.'; });
    field('copy-log').addEventListener('click', async () => { try { await navigator.clipboard.writeText(field('log').textContent); } catch { fail(new Error('Clipboard unavailable. Select and copy the log manually.')); } });
    field('copy-example').addEventListener('click', async () => {
        try {
            const config = options();
            const example = `import { io } from 'socket.io-client';\nconst socket = io(${JSON.stringify(config.origin)}, { path: ${JSON.stringify(config.path)}, auth: { token: userLoginToken }, transports: ['websocket'], autoConnect: false });\nsocket.on('connect', () => { /* Reload REST history and rejoin */ });\nsocket.on('message.sent', ({ message }) => { /* Deduplicate by message.id */ });\nsocket.on('session.expired', () => { /* Log in again and reconnect */ });\nsocket.on('connect_error', (error) => console.log(error.message, error.data?.code));\nsocket.on('disconnect', (reason) => console.log(reason));\nsocket.connect();\n// After connect: await socket.timeout(7000).emitWithAck('chat.ping', {});\n// await socket.timeout(7000).emitWithAck('conversation.join', { conversationId });\n// await socket.timeout(7000).emitWithAck('message.send', { conversationId, body: 'Hello' });\n// await socket.timeout(7000).emitWithAck('conversation.leave', { conversationId });\n// socket.disconnect();`;
            await navigator.clipboard.writeText(example); log('App example copied', {note: 'Uses a token placeholder.'});
        } catch (error) { fail(error); }
    });
    window.addEventListener('pagehide', () => { flowController?.abort(); disconnect(); });
    const initialHash = window.location.hash || '';
    showView(initialHash.startsWith('#realtime/') ? initialHash.split('/')[1] : 'overview', initialHash.startsWith('#realtime'), initialHash.split('/')[2]);
    refresh();
})();
