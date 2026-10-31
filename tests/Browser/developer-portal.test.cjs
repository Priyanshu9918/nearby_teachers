const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');

function portal({clipboard = true} = {}) {
    const elements = new Map();
    class Element {
        value = ''; textContent = ''; hidden = false; dataset = {}; children = []; listeners = {}; files = [];
        classList = {toggle() {}};
        append(...children) { this.children.push(...children); }
        replaceChildren() { this.children = []; }
        setAttribute() {}
        querySelectorAll() { return []; }
        querySelector() { return null; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        focus() {} select() {}
    }
    const element = (id) => {
        if (!elements.has(id)) elements.set(id, new Element());
        return elements.get(id);
    };
    const apis = [
        {id: 'one', method: 'POST', path: '/example', category: 'Auth', action: 'example', authMode: 'bearer', bodyExample: {name: "O'Brien $(printf INJECTED)"}, mockResponse: {status: 200, contentType: 'application/json', body: '{"token":"fictional-token"}'}},
        {id: 'two', method: 'GET', path: '/health', category: 'Common', action: 'health', authMode: 'none', mockResponse: {status: 200, contentType: 'application/json', body: '{"ok":true}'}},
        {id: 'photo', method: 'POST', path: '/student/profile/photo', category: 'Common', action: 'upload', authMode: 'bearer', bodyMode: 'multipart', fileField: 'image', fileRequired: true, bodyExample: {}, mockResponse: {status: 200, contentType: 'application/json', body: '{"ok":true}'}},
        {id: 'report', method: 'POST', path: '/reports', category: 'Common', action: 'report', authMode: 'bearer', bodyMode: 'multipart', fileField: 'screenshot', fileRequired: false, bodyExample: {reasonCategory: 'spam'}, mockResponse: {status: 201, contentType: 'application/json', body: '{"ok":true}'}}
    ];
    let pending;
    let fetches = 0;
    let copied = '';
    let lastRequest;
    const context = {
        URL, URLSearchParams, Headers, FormData, AbortController, performance, setTimeout, clearTimeout,
        CSS: {escape: (s) => s},
        window: {location: {origin: 'https://example.test', hash: '', search: ''}, history: {replaceState() {}}},
        navigator: {clipboard: {async writeText(text) { if (!clipboard) throw Error('unavailable'); copied = text; }}},
        document: {getElementById: element, querySelector: () => null, querySelectorAll: () => [], createElement: () => new Element(), execCommand: () => false},
        fetch: (url, options) => { fetches++; lastRequest = {url, options}; return new Promise((resolve, reject) => { pending = {resolve, reject}; }); }
    };
    element('request-headers').value = '{"Accept":"application/json"}';
    let script = fs.readFileSync('resources/views/developer/portal.blade.php', 'utf8').split('<script>')[1].split('</script>')[0];
    script = script.replace("@js(rtrim($baseUrl, '/').'/')", JSON.stringify('https://example.test/app/public/'))
        .replace('@js($catalog)', JSON.stringify(apis)).replace('@js($categories)', '["Auth","Common"]');
    vm.runInNewContext(script, context);
    return {element, apis, get fetches() { return fetches; }, get copied() { return copied; }, get lastRequest() { return lastRequest; },
        send: () => element('api-form').listeners.submit({preventDefault() {}}),
        select: (id) => element('endpoint-list').listeners.click({target: {closest: () => ({dataset: {endpoint: id}})}}),
        resolve: (body, status = 200) => pending.resolve({text: async () => body, status, statusText: status === 200 ? 'OK' : 'Bad Request', headers: new Headers({'content-type': 'application/json'})}),
        reject: () => pending.reject(Error('offline'))};
}

test('selection shows fictional mocks without calling API or allowing mock token use', () => {
    const p = portal();
    assert.equal(p.fetches, 0);
    assert.equal(p.element('response-status').textContent, 'Mock response');
    assert.equal(p.element('response-body').textContent, '{"token":"fictional-token"}');
    assert.equal(p.element('token-actions').hidden, true);
    p.select('two');
    assert.equal(p.element('response-body').textContent, '{"ok":true}');
});

test('sending removes mock immediately and displays real response and token', async () => {
    const p = portal();
    p.element('bearer-token').value = 'real-test-token';
    const sending = p.send();
    assert.equal(p.fetches, 1);
    assert.equal(p.element('response-status').textContent, 'Sending…');
    assert.equal(p.element('response-body').textContent.includes('fictional-token'), false);
    p.resolve('{"token":"actual-response-token"}');
    await sending;
    assert.equal(p.element('response-status').textContent, '200 OK');
    assert.equal(p.element('token-actions').hidden, false);
});

test('HTTP and network errors replace mocks, and validation sends no request', async () => {
    const p = portal();
    await p.send();
    assert.equal(p.fetches, 0);
    assert.equal(p.element('response-status').textContent, 'Token required');
    p.select('two');
    let sending = p.send();
    p.resolve('{"error":"invalid"}', 400);
    await sending;
    assert.equal(p.element('response-status').textContent, '400 Bad Request');
    sending = p.send();
    p.reject();
    await sending;
    assert.equal(p.element('response-status').textContent, 'Network error');
});

test('an old request cannot overwrite a newly selected endpoint mock', async () => {
    const p = portal();
    p.select('two');
    const sending = p.send();
    p.select('one');
    p.resolve('{"old":true}');
    await sending;
    assert.equal(p.element('response-status').textContent, 'Mock response');
    assert.equal(p.element('response-body').textContent, '{"token":"fictional-token"}');
});

test('Copy cURL preserves base path, query, bearer and shell-sensitive JSON without sending', async () => {
    const p = portal();
    p.element('bearer-token').value = 'real-test-token';
    p.element('request-query').value = 'tag=one&tag=two';
    await p.element('copy-curl').listeners.click();
    assert.equal(p.fetches, 0);
    const result = spawnSync('bash', ['-c', 'curl() { printf "%s\\0" "$@"; };\n' + p.copied], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    const args = result.stdout.split('\0');
    assert.equal(args[args.indexOf('--url') + 1], 'https://example.test/app/public/example?tag=one&tag=two');
    assert.equal(args[args.indexOf('--data-raw') + 1], p.element('request-body').value);
    assert.ok(args.includes('authorization: Bearer real-test-token'));
    p.element('api-form').listeners.input();
    assert.equal(p.element('curl-preview').hidden, true);
    assert.equal(p.element('curl-output').value, '');
});

test('Copy cURL offers manual copy when clipboard is unavailable', async () => {
    const p = portal({clipboard: false});
    p.select('two');
    await p.element('copy-curl').listeners.click();
    assert.equal(p.element('curl-preview').hidden, false);
    assert.match(p.element('curl-feedback').textContent, /Copy the selected text/);
});

test('photo endpoints require an image and send multipart without a Content-Type override', async () => {
    const p = portal();
    p.select('photo');
    p.element('bearer-token').value = 'real-test-token';
    await p.send();
    assert.equal(p.fetches, 0);
    assert.equal(p.element('response-status').textContent, 'Image required');
    const photo = new Blob(['image'], {type: 'image/jpeg'});
    p.element('request-file').files = [photo];
    p.element('request-body').value = '{"caption":"hello"}';
    const sending = p.send();
    assert.equal(p.fetches, 1);
    assert.equal(p.lastRequest.options.headers.has('Content-Type'), false);
    assert.equal(p.lastRequest.options.body.get('caption'), 'hello');
    assert.equal(p.lastRequest.options.body.get('image').type, 'image/jpeg');
    p.resolve('{"ok":true}');
    await sending;
    await p.element('copy-curl').listeners.click();
    assert.match(p.copied, /--form 'image=@\/path\/to\/blob'/);
    assert.doesNotMatch(p.copied, /--data-raw|content-type:/i);
});

test('report endpoint sends form fields with an optional screenshot', async () => {
    const p = portal();
    p.select('report');
    p.element('bearer-token').value = 'real-test-token';
    const sending = p.send();
    assert.equal(p.lastRequest.options.body.get('reasonCategory'), 'spam');
    assert.equal(p.lastRequest.options.body.has('screenshot'), false);
    p.resolve('{"ok":true}', 201);
    await sending;
    const screenshot = new Blob(['image'], {type: 'image/png'});
    p.element('request-file').files = [screenshot];
    await p.element('copy-curl').listeners.click();
    assert.match(p.copied, /--form-string 'reasonCategory=spam'/);
    assert.match(p.copied, /--form 'screenshot=@\/path\/to\/blob'/);
});
