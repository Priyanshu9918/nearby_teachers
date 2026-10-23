const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

// All external server commands are doubles. No SSH, systemd, database or live checkout is used.
function deploy(overrides = {}) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'nearby-deploy-test-'));
    try {
        for (const subdir of ['bin', 'vendor', 'realtime', 'storage/framework', 'storage/logs', 'bootstrap/cache']) {
            fs.mkdirSync(path.join(directory, subdir), {recursive: true});
        }
        for (const file of ['artisan', '.env', 'composer.lock', 'vendor/autoload.php', 'realtime/package-lock.json']) {
            fs.writeFileSync(path.join(directory, file), '');
        }
        const log = path.join(directory, 'commands.log');
        const mock = `#!/bin/bash
name=\${0##*/}
printf '%s %s\\n' "$name" "$*" >> "$TEST_LOG"
case "$name" in
    id) [[ \${1:-} != -u ]] || printf '0\\n' ;;
    node) printf 'true\\n' ;;
    runuser) shift 3; exec "$@" ;;
    systemctl) [[ \${1:-} != is-system-running ]] || printf 'running\\n' ;;
    git)
        shift 2
        case "$1" in
            rev-parse) if [[ "$2" == --show-toplevel ]]; then printf '%s\\n' "$TEST_APP"; else printf '%s\\n' "$TEST_MAIN"; fi ;;
            remote) printf 'https://github.com/Priyanshu9918/nearby_teachers.git\\n' ;;
            status) [[ \${TEST_DIRTY:-0} == 0 ]] || printf ' M routes/api.php\\n' ;;
        esac
        exit 0 ;;
    php)
        if [[ "$*" == *broadcasting.connections.socketio.url* ]]; then printf 'http://127.0.0.1:6001/health';
        elif [[ \${1:-} == artisan && \${2:-} == migrate && \${TEST_FAIL:-} == migrate ]]; then exit 1;
        elif [[ \${1:-} == -r && "$*" == *stream_get_contents* ]]; then :;
        fi ;;
    curl) if [[ \${TEST_FAIL:-} == health ]]; then exit 1; fi; printf '{"ok":true,"domains":[]}' ;;
esac
exit 0
`;
        const mockFile = path.join(directory, 'mock');
        fs.writeFileSync(mockFile, mock, {mode: 0o755});
        for (const command of ['id', 'node', 'runuser', 'systemctl', 'git', 'php', 'composer', 'npm', 'curl', 'flock']) {
            fs.symlinkSync(mockFile, path.join(directory, 'bin', command));
        }
        // Relocate only the host lock file into this disposable fixture directory.
        const script = fs.readFileSync('scripts/deploy.sh', 'utf8')
            .replace('/run/lock/nearby-teacher-deploy.lock', path.join(directory, 'deploy.lock'));
        const sha = 'a'.repeat(40);
        const result = spawnSync('bash', ['-c', script, '--', directory, sha, 'https://example.test/app/public'], {
            encoding: 'utf8', timeout: 5000, env: {...process.env,
                PATH: path.join(directory, 'bin') + ':' + process.env.PATH,
                TEST_LOG: log, TEST_APP: directory, TEST_MAIN: sha, ...overrides}
        });
        return {...result, log: fs.existsSync(log) ? fs.readFileSync(log, 'utf8') : ''};
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
}

test('successful deployment uses exact tested commit and checks public health before completion', () => {
    const r = deploy();
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, /Deployment complete/);
    assert.match(r.log, /checkout --detach --no-overwrite-ignore a{40}/);
    assert.match(r.log, /artisan migrate --force --no-interaction/);
    assert.match(r.log, /https:\/\/example.test\/app\/public\/subjects/);
    assert.ok(r.log.indexOf('artisan down') < r.log.indexOf('checkout --detach'));
    assert.ok(r.log.indexOf('artisan up') < r.log.indexOf('https://example.test/app/public/health'));
});

test('outdated commit is skipped without maintenance, checkout or migrations', () => {
    const r = deploy({TEST_MAIN: 'b'.repeat(40)});
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, /Skipping an outdated deployment/);
    assert.doesNotMatch(r.log, /artisan down|checkout --detach|artisan migrate/);
});

test('local tracked changes refuse deployment before maintenance', () => {
    const r = deploy({TEST_DIRTY: '1'});
    assert.notEqual(r.status, 0);
    assert.match(r.stderr, /Tracked files contain local changes/);
    assert.doesNotMatch(r.log, /artisan down|checkout --detach/);
});

for (const failure of ['migrate', 'health']) {
    test(`${failure} failure retains maintenance and stops managed workers`, () => {
        const r = deploy({TEST_FAIL: failure});
        assert.notEqual(r.status, 0);
        assert.match(r.stderr, /maintenance is retained/);
        assert.match(r.log, /systemctl stop nearby-teacher-broadcasts.service nearby-teacher-notifications.service nearby-teacher-chat.service/);
        assert.doesNotMatch(r.stdout, /Deployment complete/);
        assert.doesNotMatch(r.log, /migrate:rollback|migrate:fresh|git clean|reset --hard/);
    });
}
