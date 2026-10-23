#!/usr/bin/env bash
# Run once on the live server as root, after stopping the old manual processes.
set -Eeuo pipefail

fail() {
    printf 'Service setup failed: %s\n' "$*" >&2
    exit 1
}

if [[ ${1:-} == '--help' || ${1:-} == '-h' ]]; then
    printf 'Usage: sudo bash scripts/setup-deploy-services.sh [app_path] [app_user]\n'
    printf 'Defaults: /var/www/html/nearby_teachers www-data\n'
    exit 0
fi

[[ $EUID -eq 0 ]] || fail 'Run this installer as root on the deployment server.'
[[ $# -le 2 ]] || fail 'Expected only app_path and app_user arguments.'

app_path=${1:-/var/www/html/nearby_teachers}
app_user=${2:-www-data}
[[ $app_path == /* ]] || fail 'The application path must be absolute.'
[[ ! $app_path =~ [[:cntrl:]] ]] || fail 'The application path contains control characters.'
[[ $app_user =~ ^[a-z_][a-z0-9_-]*$ ]] || fail 'Use a normal existing Unix service username.'
[[ $app_user != root ]] || fail 'The application services must use an unprivileged account.'

for command_name in systemctl runuser getent id readlink install chown chmod find ss mktemp php node npm composer git curl; do
    command -v "$command_name" >/dev/null 2>&1 || fail "Install the required command: $command_name"
done
[[ -d /run/systemd/system ]] || fail 'This server must be running systemd.'
getent passwd "$app_user" >/dev/null || fail "The service account does not exist: $app_user"
app_group=$(id -gn "$app_user")
app_path=$(readlink -f -- "$app_path")
[[ -d $app_path ]] || fail 'The application directory does not exist.'
[[ -f $app_path/artisan && -f $app_path/realtime/index.js ]] || fail 'The application path must contain artisan and realtime/index.js.'
[[ -f $app_path/.env ]] || fail 'Keep the configured live .env in the application directory before setup.'
[[ -f $app_path/vendor/autoload.php ]] || fail 'Install Composer dependencies before setting up services.'
[[ -f $app_path/realtime/node_modules/dotenv/package.json ]] || fail 'Install realtime dependencies with npm ci --prefix realtime --omit=dev first.'

php_bin=$(readlink -f -- "$(command -v php)")
node_bin=$(readlink -f -- "$(command -v node)")
for binary_path in "$php_bin" "$node_bin"; do
    [[ ! $binary_path =~ [[:cntrl:]] ]] || fail 'An executable path contains control characters.'
    [[ $binary_path != /root/* ]] || fail 'Install PHP/Node system-wide; services cannot use a binary under /root or root nvm.'
    runuser -u "$app_user" -- test -x "$binary_path" || fail "The service account cannot execute $binary_path; install it system-wide."
done
runuser -u "$app_user" -- "$php_bin" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || fail 'PHP 8.2 or newer is required for the service account.'
runuser -u "$app_user" -- "$php_bin" -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' \
    || fail 'Enable the PHP CLI pdo_mysql extension.'
runuser -u "$app_user" -- "$node_bin" -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)' \
    || fail 'Node.js 22 or newer must be executable by the service account.'
runuser -u "$app_user" -- test -r "$app_path/.env" \
    || fail 'The service account must be able to read the existing .env; adjust its private owner/group permissions first.'
runuser -u "$app_user" -- test -r "$app_path/artisan" \
    || fail 'The service account must be able to read the application files.'

# Parse Laravel .env with dotenv; never source it as shell or EnvironmentFile.
# Print only a validated port, never credentials or the parsed environment.
chat_port=$(runuser -u "$app_user" -- "$node_bin" - "$app_path" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const appPath = process.argv[2];
try {
    const dotenv = require(path.join(appPath, 'realtime/node_modules/dotenv'));
    const config = dotenv.parse(fs.readFileSync(path.join(appPath, '.env')));
    const port = Number(config.SOCKET_IO_PORT || 6001);
    if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('port');
    if (String(config.SOCKET_IO_SHARED_SECRET || '').length < 32) throw new Error('secret');
    if ((config.QUEUE_CONNECTION || 'database') !== 'database') throw new Error('queue');
    process.stdout.write(String(port));
} catch {
    process.stderr.write('Check readable .env: valid SOCKET_IO_PORT, SOCKET_IO_SHARED_SECRET of at least 32 characters and QUEUE_CONNECTION=database are required.\n');
    process.exit(1);
}
NODE
) || fail 'The existing Socket.IO/queue configuration is not ready.'

# Refuse to compete with a manually started chat server. Re-running this script
# is allowed when the listener already belongs to our managed Node process.
chat_unit=nearby-teacher-chat.service
listeners=$(ss -H -ltnp "sport = :$chat_port")
if [[ -n $listeners ]]; then
    managed_pid=$(systemctl show "$chat_unit" --property=MainPID --value 2>/dev/null || true)
    [[ $managed_pid =~ ^[1-9][0-9]*$ ]] \
        || fail "Port $chat_port is in use. Stop the old manual chat command with Ctrl+C in its terminal, then rerun setup. Inspect with: ss -ltnp 'sport = :$chat_port'"
    while IFS= read -r listener; do
        [[ $listener == *"pid=$managed_pid,"* ]] \
            || fail "Another process owns port $chat_port. Stop the old manual chat process before starting the managed service."
    done <<< "$listeners"
    configured_directory=$(systemctl show "$chat_unit" --property=WorkingDirectory --value)
    [[ $configured_directory == "$app_path" ]] \
        || fail 'The managed chat unit already points to a different application directory.'
fi

# Runtime ownership changes stay within Laravel's writable directories. Keep
# existing group/other access and private credential modes; never chmod 777.
for runtime_directory in "$app_path/storage" "$app_path/bootstrap/cache"; do
    [[ -d $runtime_directory ]] || fail "Missing runtime directory: $runtime_directory"
    runtime_directory=$(readlink -f -- "$runtime_directory")
    chown -R --no-dereference "$app_user:$app_group" -- "$runtime_directory"
    find "$runtime_directory" -type d -exec chmod u+rwx -- {} +
    find "$runtime_directory" -type f -exec chmod u+rw -- {} +
    runuser -u "$app_user" -- test -w "$runtime_directory" \
        || fail "The service account cannot write to $runtime_directory"
done

# systemd unit arguments are not shell code. Escape unit specifiers separately
# from ExecStart's environment substitution, including paths containing spaces.
unit_quote() {
    local value=$1
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//%/%%}
    printf '"%s"' "$value"
}
exec_quote() {
    local value=$1
    value=${value//\$/\$\$}
    unit_quote "$value"
}

unit_directory=$(mktemp -d)
trap 'rm -rf -- "$unit_directory"' EXIT

write_unit() {
    local name=$1 description=$2 command_line=$3
    cat > "$unit_directory/$name.service" <<UNIT
[Unit]
Description=$description
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=$app_user
Group=$app_group
WorkingDirectory=$(unit_quote "$app_path")
Environment="PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
Environment="NODE_ENV=production"
ExecStart=$command_line
Restart=always
RestartSec=5
TimeoutStopSec=60
KillSignal=SIGTERM
UMask=0027
SyslogIdentifier=$name

[Install]
WantedBy=multi-user.target
UNIT
}

write_unit nearby-teacher-broadcasts 'Nearby Teacher broadcasts queue' \
    "$(exec_quote "$php_bin") $(exec_quote "$app_path/artisan") queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30"
write_unit nearby-teacher-notifications 'Nearby Teacher notifications queue' \
    "$(exec_quote "$php_bin") $(exec_quote "$app_path/artisan") queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30"
write_unit nearby-teacher-chat 'Nearby Teacher Socket.IO chat' \
    "$(exec_quote "$node_bin") $(exec_quote "$app_path/realtime/index.js")"

for unit_name in nearby-teacher-broadcasts nearby-teacher-notifications nearby-teacher-chat; do
    install -m 0644 -- "$unit_directory/$unit_name.service" "/etc/systemd/system/$unit_name.service"
done
systemctl daemon-reload
for unit_name in nearby-teacher-broadcasts nearby-teacher-notifications nearby-teacher-chat; do
    systemctl enable "$unit_name.service"
    systemctl restart "$unit_name.service"
done
for unit_name in nearby-teacher-broadcasts nearby-teacher-notifications nearby-teacher-chat; do
    systemctl is-active --quiet "$unit_name.service" \
        || fail "$unit_name did not start. Inspect: journalctl -u $unit_name.service -n 50 --no-pager"
done

printf 'Services installed and started as %s in %s.\n' "$app_user" "$app_path"
printf 'Stop any remaining old manual queue workers so only managed workers remain.\n'
printf 'Inspect logs: journalctl -u nearby-teacher-chat -u nearby-teacher-broadcasts -u nearby-teacher-notifications -f\n'
