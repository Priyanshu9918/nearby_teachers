const {test} = require('node:test');
const assert = require('node:assert/strict');
const {EventEmitter} = require('node:events');
const fs = require('node:fs');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');
const chatContracts = JSON.parse(spawnSync('php', ['-r', 'echo json_encode(require "config/chat_events.php");'], {encoding: 'utf8'}).stdout);
const conversationId = 4;
const message = {id: 5, conversation_id: conversationId, body: 'Real test message'};

function tester(config = {}) {
    class Element {
        _value = ''; textContent = ''; checked = false; disabled = false; hidden = false; dataset = {}; children = []; listeners = {}; attributes = {};
        get value() { return this._value; }
        set value(value) { this._value = String(value); }
        classes = new Set();
        classList = {toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name), add: (name) => this.classes.add(name)};
        append(child) { this.children.push(child); }
        replaceChildren() { this.children = []; }
        addEventListener(event, callback) { this.listeners[event] = callback; }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
    }
    const elements = new Map(), selectors = new Map();
    function parseTemplate(file, sidebar = false) {
        let template = fs.readFileSync(file, 'utf8');
        if (sidebar) template = template.replace(/@foreach[\s\S]*?@endforeach/, Object.entries(chatContracts).map(([event, contract]) => `<a data-tab="realtime" data-chat-view="events" data-chat-event="${event}">${contract.label}</a>`).join(''));

        for (const match of template.matchAll(/<[a-z][a-z0-9-]*\b([^>]*)>/g)) {
            const id = match[1].match(/\bid="([^"]+)"/);
            const el = new Element();
            if (id) { el.id = id[1]; elements.set(id[1], el); }
            el.hidden = /\shidden(?:\s|$)/.test(match[1]);
            for (const attr of match[1].matchAll(/\bdata-([a-z-]+)(?:="([^"]*)")?/g)) {
                const name = 'data-' + attr[1];
                el.dataset[attr[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = attr[2] || '';
                if (!selectors.has(name)) selectors.set(name, []); selectors.get(name).push(el);
                if (sidebar && name === 'data-chat-view') { if (!selectors.has('sidebar-links')) selectors.set('sidebar-links', []); selectors.get('sidebar-links').push(el); }
            }
        }
    }
    parseTemplate('resources/views/developer/chat-tester.blade.php');
    parseTemplate('resources/views/developer/chat-navigation.blade.php', true);
    const field = (id) => elements.get('chat-' + id);
    field('tester').dataset = {apiBase: 'https://example.test/nearby/public/', healthUrl: 'https://example.test/nearby/public/developer/socket/health'};
    field('event-contracts').textContent = JSON.stringify(chatContracts);
    field('path').value = '/socket.io/';
    field('token').value = 'private-user-bearer';
    field('conversation').value = conversationId;
    field('body').value = message.body;
    const requests = [], emits = [], sockets = [], intervals = new Map();
    let nextInterval = 1, copied = '', slowResolve;
    class Socket extends EventEmitter {
        connected = false; active = false; id = 'socket-one';
        io = Object.assign(new EventEmitter(), {engine: Object.assign(new EventEmitter(), {transport: {name: 'websocket'}})});
        connect() {
            this.active = true;
            queueMicrotask(() => {
                if (config.connectError) {
                    this.active = false;
                    this.dispatch('connect_error', Object.assign(Error('Token denied'), {data: {code: 'UNAUTHORIZED'}}));
                } else { this.connected = true; this.dispatch('connect'); }
            });
        }
        disconnect() { const active = this.active || this.connected; this.active = false; this.connected = false; if (active) this.dispatch('disconnect', 'io client disconnect'); }
        dispatch(...args) { return super.emit(...args); }
        timeout() {
            return {emit: (event, payload, callback) => {
                emits.push({event, payload});
                if (config.deferAck === event) { this.finishAck = callback; return; }
                queueMicrotask(() => {
                    if (config.ackTimeout === event) return callback(Error('timeout'));
                    if (config.rejectEvent === event) return callback(null, {ok: false, status: 404, error: 'Conversation not found'});
                    if (['message.send', 'chat:send', 'chat:reply'].includes(event)) {
                        // Delivery may beat the send acknowledgement.
                        if (!config.noDelivery) this.dispatch('message.sent', {message});
                        return callback(null, {ok: true, message});
                    }
                    callback(null, {ok: true, ...(event === 'chat.ping' ? {serverTime: '2026-10-23T10:00:00.000Z'} : {})});
                });
            }};
        }
    }
    const windowListeners = {};
    let clock = 0;
    const context = {
        URL, AbortController, performance, clearTimeout,
        Date: config.fastClock ? class extends Date { static now() { clock += 4000; return clock; } } : Date,
        setTimeout: (callback, delay) => setTimeout(callback, config.fastClock && delay === 100 ? 0 : delay),
        setInterval: (callback) => { const id = nextInterval++; intervals.set(id, callback); return id; },
        clearInterval: (id) => intervals.delete(id),
        window: {location: {origin: 'https://example.test', protocol: 'https:', hash: config.hash || ''},
            history: {replaceState: (state, unused, hash) => { context.window.location.hash = hash; }},
            addEventListener: (name, cb) => { windowListeners[name] = cb; },
            io: (origin, options) => { const socket = new Socket(); socket.options = options; sockets.push(socket); return socket; }},
        navigator: {clipboard: {writeText: async (value) => { copied = value; }}},
        document: {getElementById: (id) => elements.get(id) || null, createElement: () => new Element(), querySelectorAll: (selector) => selectors.get(selector === '.socket-nav-links [data-chat-view]' ? 'sidebar-links' : selector.slice(1, -1)) || []},
        fetch: async (url, options) => {
            requests.push({url, options});
            if (config.slowHealth && url.endsWith('/developer/socket/health')) {
                await new Promise((resolve, reject) => { slowResolve = resolve; options.signal.addEventListener('abort', () => reject(Error('aborted')), {once: true}); });
            }
            if (config.healthDown && url.endsWith('/developer/socket/health')) return {ok: false, status: 503, text: async () => JSON.stringify({ok: false, status: 'unreachable', next_steps: ['Start the chat service.']})};
            let body = {ok: true};
            if (url.includes('/chat/events/')) body = chatContracts['chat:' + url.split('/').pop()].mock;

            if (url.endsWith('/chat/socket/authorize')) body = {userId: 'user-id', room: null};
            if (url.endsWith('/messages/start')) body = {conversationId};
            if (url.endsWith('/messages?perPage=20')) body = {conversations: [{id: conversationId, other_name: 'Example Teacher'}]};
            if (url.includes('/messages/' + conversationId)) body = options.method === 'GET' ? {messages: config.missingHistory ? [] : [message]} : {message};
            return {ok: !config.httpError, status: config.httpError ? 403 : 200, text: async () => JSON.stringify(config.httpError ? {error: 'Access denied', token: 'must-not-log', nested: {password: 'must-not-log'}} : body)};
        }
    };
    vm.runInNewContext(fs.readFileSync('public/js/developer-chat.js', 'utf8'), context);
    return {field, sockets, emits, requests, intervals, windowListeners,
        click: (id) => field(id).listeners.click({preventDefault() {}}),
        navigate: (view, event) => selectors.get('sidebar-links').find((link) => link.dataset.chatView === view && (!event || link.dataset.chatEvent === event)).listeners.click({preventDefault() {}}),
        panels: selectors.get('data-chat-views'), sidebarLinks: selectors.get('sidebar-links'),
        get hash() { return context.window.location.hash; },
        change: (id) => field(id).listeners.change(),
        get copied() { return copied; }, get releaseHealth() { return slowResolve; }};
}

test('all operations have readable mock responses and no network request runs on load or preview', () => {
    const t = tester();
    assert.equal(t.requests.length, 0);
    assert.equal(t.sockets.length, 0);
    assert.ok(t.field('operation').children.length >= 18);
    for (const option of t.field('operation').children) {
        t.field('operation').value = option.value; t.change('operation');
        assert.match(t.field('result-label').textContent, /Mock response/);
        assert.doesNotThrow(() => JSON.parse(t.field('result').textContent));
        assert.ok(t.field('operation-help').textContent.length > 20);
    }
});

test('unavailable chat service keeps the actual diagnostic response and stops full check', async () => {
    const t = tester({healthDown: true});
    await t.click('health');
    assert.match(t.field('result-label').textContent, /HTTP 503/);
    const response = JSON.parse(t.field('result').textContent);
    assert.equal(response.status, 'unreachable');
    assert.deepEqual(response.next_steps, ['Start the chat service.']);
    await t.click('run');
    assert.equal(t.sockets.length, 0);
    assert.ok(t.requests.every((request) => request.url.endsWith('/developer/socket/health')));
});

test('application URL is rejected with the correct origin before opening a socket', async () => {
    const t = tester();
    t.field('url').value = 'https://example.test/nearby_teachers/public';
    await t.click('connect');
    assert.equal(t.sockets.length, 0);
    assert.match(t.field('result').textContent, /Use https:\/\/example.test and put the WebSocket proxy path/);
});

test('safe full check verifies all steps without sending or creating records, then disconnects', async () => {
    const t = tester();
    await t.click('run');
    assert.ok(t.field('checklist').children.every((row) => row.textContent.endsWith('Passed')));
    assert.equal(t.emits.some(({event}) => event === 'message.send'), false);
    assert.equal(t.requests.some(({url}) => url.endsWith('/messages/start')), false);
    assert.equal(t.sockets[0].connected, false);
    assert.equal(JSON.parse(t.field('result').textContent).messageSent, false);
    assert.match(t.requests[0].url, /nearby\/public\/developer\/socket\/health$/);
    assert.equal(t.requests[0].options.headers.Authorization, undefined);
    assert.equal(t.requests[1].options.headers.Authorization, 'Bearer private-user-bearer');
    assert.equal(t.field('run').disabled, false);
});

test('opted-in full check sends exactly once and matches an event arriving before ACK to persisted history', async () => {
    const t = tester(); t.field('flow-send').checked = true;
    await t.click('run');
    assert.equal(t.emits.filter(({event}) => event === 'message.send').length, 1);
    assert.ok(t.field('checklist').children.every((row) => row.textContent.endsWith('Passed')));
    assert.equal(JSON.parse(t.field('result').textContent).messageSent, true);
    assert.equal(t.sockets[0].connected, false);
    assert.match(t.field('metrics').textContent, /Sent: 1 · Received: 1/);
});

test('failed room access stops send and disconnects with an actionable failed step', async () => {
    const t = tester({rejectEvent: 'conversation.join'}); t.field('flow-send').checked = true;
    await t.click('run');
    assert.equal(t.emits.some(({event}) => event === 'message.send'), false);
    assert.equal(t.sockets[0].connected, false);
    assert.equal(JSON.parse(t.field('result').textContent).ok, false);
    assert.match(t.field('result').textContent, /Conversation not found/);
    assert.ok(t.field('checklist').children.some((row) => row.textContent.includes('Skipped')));
});

test('ACK timeout does not resend and failure stays visible after cleanup', async () => {
    const t = tester({ackTimeout: 'message.send'}); t.field('flow-send').checked = true;
    await t.click('run');
    assert.equal(t.emits.filter(({event}) => event === 'message.send').length, 1);
    assert.equal(t.sockets[0].connected, false);
    assert.match(t.field('result').textContent, /ACK timeout/);
});

test('missing persisted message fails the history check rather than claiming delivery is enough', async () => {
    const t = tester({missingHistory: true}); t.field('flow-send').checked = true;
    await t.click('run');
    assert.equal(JSON.parse(t.field('result').textContent).ok, false);
    assert.match(t.field('result').textContent, /not in the latest history page/);
});

test('stop aborts a pending REST check and restores controls without sending', async () => {
    const t = tester({slowHealth: true}); const run = t.click('run');
    assert.equal(t.field('stop').disabled, false);
    assert.equal(t.field('token').disabled, true);
    t.click('stop'); await run;
    assert.equal(t.emits.length, 0);
    assert.equal(t.field('stop').disabled, true);
    assert.equal(t.field('run').disabled, false);
    assert.equal(JSON.parse(t.field('result').textContent).ok, false);
});

test('auto ping, transport heartbeat and reconnect events are logged, then stop on disconnect', async () => {
    const t = tester(); await t.click('connect');
    await t.click('ping-start');
    assert.equal(t.intervals.size, 1);
    await [...t.intervals.values()][0]();
    assert.equal(t.emits[0].event, 'chat.ping');
    t.sockets[0].io.engine.emit('packet', {type: 'ping'});
    t.sockets[0].io.engine.emit('packetCreate', {type: 'pong'});
    t.sockets[0].io.emit('reconnect_attempt', 1);
    assert.match(t.field('log').textContent, /heartbeat ping/);
    assert.match(t.field('log').textContent, /heartbeat pong/);
    assert.match(t.field('log').textContent, /reconnect_attempt/);
    await t.click('disconnect');
    assert.equal(t.intervals.size, 0);
    assert.equal(t.field('ping').disabled, true);
});

test('conversation start, inbox selection and REST send use real API base and explicit actions', async () => {
    const t = tester();
    t.field('provider').value = 2;
    await t.click('start');
    assert.equal(t.field('conversation').value, String(conversationId));
    assert.deepEqual(JSON.parse(t.requests[0].options.body), {providerId: Number(t.field('provider').value)});
    await t.click('inbox');
    t.field('inbox-select').value = conversationId; t.change('inbox-select');
    await t.click('rest-send');
    assert.equal(t.requests.filter(({url, options}) => url.endsWith('/messages/' + conversationId) && options.method === 'POST').length, 1);
    assert.match(t.field('result-label').textContent, /Actual result/);
});

test('logs redact credentials, stay bounded, and app example never copies the real token', async () => {
    const t = tester({httpError: true}); await t.click('authorize');
    assert.doesNotMatch(t.field('log').textContent, /private-user-bearer|must-not-log/);
    assert.doesNotMatch(t.field('result').textContent, /must-not-log/);
    await t.click('copy-example');
    assert.match(t.copied, /userLoginToken/);
    assert.doesNotMatch(t.copied, /private-user-bearer/);
    await t.click('connect');
    for (let index = 0; index < 180; index++) t.sockets[0].dispatch('message.sent', {message: {...message, body: 'private-user-bearer', token: 'must-not-log'}});
    assert.equal(t.field('log').textContent.split('← message.sent').length - 1, 150);
    assert.doesNotMatch(t.field('log').textContent, /private-user-bearer|must-not-log/);
    t.windowListeners.pagehide();
    assert.equal(t.sockets[0].connected, false);
});

test('invalid input and authentication failure never emit messages', async () => {
    const t = tester({connectError: true});
    await t.click('connect');
    assert.match(t.field('result').textContent, /Token denied/);
    t.field('conversation').value = 'invalid'; await t.click('run');
    assert.equal(t.emits.length, 0);
    assert.match(t.field('result').textContent, /valid conversation ID/);
});

test('double clicks cannot start a second send while ACK is pending', async () => {
    const t = tester({deferAck: 'message.send'}); await t.click('connect');
    const first = t.click('send');
    await t.click('send');
    assert.equal(t.emits.length, 1);
    t.sockets[0].finishAck(null, {ok: true, message}); await first;
    assert.equal(t.field('send').disabled, false);
});

test('missing live delivery fails clearly but still checks persisted history and cleans up', async () => {
    const t = tester({noDelivery: true, fastClock: true}); t.field('flow-send').checked = true;
    await t.click('run');
    assert.equal(JSON.parse(t.field('result').textContent).ok, false);
    assert.match(t.field('result').textContent, /no matching live event/);
    assert.ok(t.field('checklist').children.some((row) => row.textContent === 'Load message history · Passed'));
    assert.equal(t.emits.filter(({event}) => event === 'message.send').length, 1);
    assert.equal(t.sockets[0].connected, false);
});

test('session expiry and exhausted retries stop ping and allow an explicit new connection', async () => {
    const t = tester(); await t.click('connect'); await t.click('ping-start');
    t.field('operation').value = 'expired'; t.change('operation');
    t.sockets[0].dispatch('session.expired');
    assert.equal(t.intervals.size, 0);
    assert.match(t.field('result').textContent, /valid login token/);
    t.sockets[0].connected = false;
    t.sockets[0].io.emit('reconnect_failed');
    assert.equal(t.field('connect').disabled, false);
    assert.match(t.field('status').textContent, /retries exhausted/);
    await t.click('connect');
    assert.equal(t.sockets[1].connected, true);
    await t.click('disconnect');
});

test('sidebar switches focused steps without network traffic or losing shared connection fields', async () => {
    const t = tester(); await t.click('connect');
    const connected = t.sockets[0];
    for (const view of ['ping', 'conversations', 'send', 'receive', 'history', 'session', 'end']) {
        t.navigate(view);
        assert.equal(t.hash, '#realtime/' + view);
        assert.equal(t.field('navigation').open, true);
        assert.equal(connected.connected, true);
        assert.equal(t.field('token').value, 'private-user-bearer');
        assert.equal(t.field('conversation').value, String(conversationId));
        for (const panel of t.panels) assert.equal(panel.hidden, !panel.dataset.chatViews.split(' ').includes(view));
        assert.equal(t.sidebarLinks.filter((link) => link.attributes['aria-current'] === 'page').length, 1);
    }
    assert.equal(t.requests.length, 0);
    assert.equal(t.emits.length, 0);
    await t.click('disconnect');
});

test('direct step links restore the right view and previous/next links walk through the flow', () => {
    const t = tester({hash: '#realtime/send'});
    assert.equal(t.field('view-title').textContent, 'Send a message');
    assert.match(t.field('result-label').textContent, /Mock response.*Send via socket/);
    assert.equal(t.field('next').dataset.chatView, 'receive');
    t.click('next');
    assert.equal(t.field('view-title').textContent, 'Receive messages');
    t.click('previous');
    assert.equal(t.field('view-title').textContent, 'Send a message');
    const unknown = tester({hash: '#realtime/not-a-real-step'});
    assert.equal(unknown.field('view-title').textContent, 'Getting started');
    assert.equal(unknown.requests.length, 0);
});

test('pending send ACK cannot overwrite another step and its actual result is retained on return', async () => {
    const t = tester({deferAck: 'message.send'}); await t.click('connect');
    t.navigate('send'); const sending = t.click('send');
    t.navigate('history');
    assert.match(t.field('result-label').textContent, /Mock response.*Load message history/);
    t.sockets[0].finishAck(null, {ok: true, message}); await sending;
    assert.match(t.field('result-label').textContent, /Mock response.*Load message history/);
    t.navigate('send');
    assert.match(t.field('result-label').textContent, /Actual result.*message.send/);
    assert.equal(JSON.parse(t.field('result').textContent).message.id, message.id);
    t.click('show-mock');
    assert.match(t.field('result-label').textContent, /Mock response/);
    await t.click('disconnect');
});

test('receive screen shows bounded unique readable messages and masks credentials', async () => {
    const t = tester(); await t.click('connect'); t.navigate('receive');
    const socket = t.sockets[0];
    socket.dispatch('message.sent', {message: {...message, body: 'Hello <script>unsafe</script> private-user-bearer'}});
    socket.dispatch('message.sent', {message: {...message, body: 'Hello <script>unsafe</script> private-user-bearer'}});
    assert.equal(t.field('message-feed').children.length, 1);
    assert.match(t.field('message-feed').children[0].textContent, /Hello <script>unsafe<\/script>/);
    assert.doesNotMatch(t.field('message-feed').children[0].textContent, /private-user-bearer/);
    assert.match(t.field('result-label').textContent, /Actual result.*Live message/);
    for (let index = 0; index < 40; index++) socket.dispatch('message.sent', {message: {...message, id: 'message-' + index}});
    assert.equal(t.field('message-feed').children.length, 30);
    assert.match(t.field('listening-status').textContent, /Listening/);
    await t.click('disconnect');
});

test('all 13 named chat events appear in the sidebar with mock responses and server events cannot be forged', () => {
    const t = tester();
    const links = t.sidebarLinks.filter((link) => link.dataset.chatView === 'events');
    assert.equal(links.length, 13);
    for (const [event, contract] of Object.entries(chatContracts)) {
        t.navigate('events', event);
        assert.equal(t.hash, '#realtime/events/' + event);
        assert.match(t.field('result-label').textContent, /Mock response/);
        assert.match(t.field('event-title').textContent, new RegExp(event));
        assert.equal(t.field('event-rest').disabled, contract.direction === 'server');
    }
    assert.equal(t.requests.length, 0);
    assert.equal(t.emits.length, 0);
});

test('named socket sends and REST receipt/reaction actions use entered IDs and payloads', async () => {
    const t = tester(); await t.click('connect');
    t.navigate('events', 'chat:send');
    await t.click('event-run');
    assert.equal(t.emits.at(-1).event, 'chat:send');
    assert.equal(t.emits.at(-1).payload.body, message.body);
    t.navigate('events', 'chat:read'); t.field('event-message').value = message.id;
    await t.click('event-rest');
    assert.equal(t.requests.at(-1).url, 'https://example.test/nearby/public/chat/events/read');
    assert.equal(JSON.parse(t.requests.at(-1).options.body).messageId, message.id);
    t.navigate('events', 'chat:reaction'); t.field('event-reaction').value = '';
    await t.click('event-rest');
    assert.equal(JSON.parse(t.requests.at(-1).options.body).reaction, null);
    t.navigate('events', 'chat:unread_count'); t.field('conversation').value = '';
    await t.click('event-rest');
    assert.deepEqual(JSON.parse(t.requests.at(-1).options.body), {});
    t.navigate('events', 'chat:receive');
    assert.equal(t.field('event-run').disabled, true);
    t.sockets[0].dispatch('chat:receive', {message});
    assert.match(t.field('result-label').textContent, /Actual result.*chat:receive/);
    assert.equal(t.field('message-feed').children.length, 1);
    await t.click('disconnect');
});
