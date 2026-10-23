#!/usr/bin/env bash
# Run as a file or with bash -c SCRIPT -- APP_PATH SHA HEALTH_BASE_URL [APP_USER] [WEB_SERVICE].
set -Eeuo pipefail
umask 022

fail() { printf 'Deployment refused: %s\n' "$*" >&2; exit 1; }
log() { printf '%s\n' "$*"; }
[[ $# -ge 3 && $# -le 5 ]] || fail 'Expected APP_PATH SHA HEALTH_BASE_URL [APP_USER] [WEB_SERVICE].'
app_path=$1
target_sha=${2,,}
health_base=${3%/}
app_user=${4:-www-data}
web_service=${5:-auto}
[[ $app_path == /* && $app_path != / ]] || fail 'APP_PATH must be an absolute application directory.'
[[ $target_sha =~ ^[0-9a-f]{40}$ ]] || fail 'SHA must contain exactly 40 hexadecimal characters.'
[[ $health_base =~ ^https?://[^/?#[:space:]@]+(/[^?#[:space:]]*)?$ ]] || fail 'HEALTH_BASE_URL must be an HTTP(S) base URL without query, fragment or credentials.'
[[ $app_user =~ ^[a-z_][a-z0-9_-]*\$?$ ]] || fail 'Invalid application user.'
[[ $web_service == auto || $web_service =~ ^[a-zA-Z0-9_.@-]+(\.service)?$ ]] || fail 'Invalid web service name.'
[[ $(id -u) == 0 ]] || fail 'Run this script as root; service control and runuser are required.'
for command in php composer node npm curl git flock runuser systemctl realpath; do
    command -v "$command" >/dev/null || fail "Required command is unavailable: $command"
done
app_path=$(realpath -e -- "$app_path")
cd -- "$app_path"
[[ -f artisan && -f composer.lock && -f realtime/package-lock.json && -f vendor/autoload.php ]] || fail 'Expected an existing, installed Laravel checkout with realtime dependencies locked.'
[[ -f .env ]] || fail 'The existing production .env is required.'
id "$app_user" >/dev/null 2>&1 || fail 'Application user does not exist.'
runuser -u "$app_user" -- test -r .env || fail 'Application user cannot read .env.'
for directory in storage storage/framework storage/logs bootstrap/cache; do
    runuser -u "$app_user" -- test -w "$directory" || fail "Application user cannot write $directory; run setup-deploy-services.sh first."
done
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") && extension_loaded("pdo_mysql") ? 0 : 1);' || fail 'PHP 8.2+ with pdo_mysql is required.'
[[ $(node -p 'Number(process.versions.node.split(".")[0]) >= 22') == true ]] || fail 'Node.js 22+ is required.'
systemctl is-system-running >/dev/null 2>&1 || [[ $(systemctl is-system-running 2>/dev/null) == degraded ]] || fail 'A running systemd host is required.'
units=(nearby-teacher-broadcasts.service nearby-teacher-notifications.service nearby-teacher-chat.service)
for unit in "${units[@]}"; do
    systemctl is-active --quiet "$unit" || fail "$unit must be installed and active; run setup-deploy-services.sh first."
done
if [[ $web_service == auto ]]; then
    mapfile -t php_services < <(systemctl list-units --type=service --state=active --no-legend --plain 'php*-fpm.service' | awk '{print $1}')
    if [[ ${#php_services[@]} == 1 ]]; then
        web_service=${php_services[0]}
    elif [[ ${#php_services[@]} -gt 1 ]]; then
        fail 'Multiple PHP-FPM services are active; provide WEB_SERVICE explicitly.'
    elif systemctl is-active --quiet apache2.service; then
        web_service=apache2.service
    else
        fail 'No active PHP-FPM or Apache service was found; provide WEB_SERVICE explicitly.'
    fi
fi
[[ $web_service == *.service ]] || web_service+=.service
systemctl is-active --quiet "$web_service" || fail 'The configured PHP web service is not active.'

# Keep deployments serialized even when launched outside GitHub Actions.
exec 9>/run/lock/nearby-teacher-deploy.lock
flock -w 300 9 || fail 'Another deployment holds the server lock.'
git_app() { git -c safe.directory="$app_path" "$@"; }
artisan() { runuser -u "$app_user" -- php artisan "$@"; }
app_php() { runuser -u "$app_user" -- php "$@"; }
[[ $(git_app rev-parse --show-toplevel) == "$app_path" ]] || fail 'APP_PATH is not the Git repository root.'
origin=$(git_app remote get-url origin)
[[ $origin =~ ^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)Priyanshu9918/nearby_teachers(\.git)?$ ]] || fail 'origin must identify Priyanshu9918/nearby_teachers on GitHub.'
[[ -z $(git_app status --porcelain --untracked-files=no) ]] || fail 'Tracked files contain local changes; preserve and reconcile them before deployment.'
[[ ! -e storage/framework/down && ! -e storage/framework/maintenance.php ]] || fail 'The application is already in maintenance mode.'
app_php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit($app->isDownForMaintenance() ? 1 : 0);' || fail 'Application bootstrap failed or maintenance mode is already active.'
export GIT_TERMINAL_PROMPT=0
git_app fetch --no-tags origin '+refs/heads/main:refs/remotes/origin/main'
current_main=$(git_app rev-parse refs/remotes/origin/main)
if [[ $target_sha != "$current_main" ]]; then
    log 'Skipping an outdated deployment: origin/main has advanced beyond the tested commit.'
    exit 0
fi
git_app cat-file -e "$target_sha^{commit}"
[[ -z $(git_app ls-tree --name-only "$target_sha" -- .env) ]] || fail 'The target commit must not track the production .env.'
composer check-platform-reqs --no-dev --lock

changed=0
stage=preflight
on_exit() {
    local result=$?
    trap - EXIT
    if [[ $result -ne 0 && $changed == 1 ]]; then
        set +e
        # A failed in-place update is not safe to expose or roll back automatically.
        artisan down --render=errors::503 --retry=60 >/dev/null 2>&1 || artisan down --retry=60 >/dev/null 2>&1
        local maintenance_result=$?
        systemctl stop "${units[@]}" >/dev/null 2>&1
        printf 'Deployment failed during %s. Managed chat/queue services were stopped; maintenance is retained.\n' "$stage" >&2
        [[ $maintenance_result == 0 ]] || printf 'Could not re-enter maintenance; inspect the application immediately.\n' >&2
        printf 'Recover in %s: repair the failed step, rebuild caches, restart the three nearby-teacher services and PHP web service, then run php artisan up and recheck /health and /subjects. Do not roll back the database automatically.\n' "$app_path" >&2
    fi
    exit "$result"
}
trap on_exit EXIT
log "Deploying tested commit $target_sha to $app_path"
stage=maintenance
artisan down --render=errors::503 --retry=60 || artisan down --retry=60
changed=1
stage=stop-services
systemctl stop "${units[@]}"
stage=checkout
git_app checkout --detach --no-overwrite-ignore "$target_sha"
stage=dependencies
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
npm ci --omit=dev --ignore-scripts --no-audit --no-fund --prefix realtime
stage=application-caches
artisan config:clear
artisan route:clear
artisan view:clear
artisan package:discover --no-interaction
stage=migrations
artisan migrate --force --no-interaction
stage=application-caches
artisan config:cache
artisan route:cache
artisan view:cache
artisan queue:restart
stage=restart-services
systemctl restart "${units[@]}"
systemctl reload "$web_service"
for unit in "${units[@]}"; do
    systemctl is-active --quiet "$unit"
done
stage=chat-health
# Read only the non-secret bridge URL from Laravel configuration.
chat_health=$(app_php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo rtrim(config("broadcasting.connections.socketio.url"), "/"), "/health";')
[[ $chat_health =~ ^http://(127\.0\.0\.1|localhost|\[::1\]):[0-9]+/health$ ]] || fail 'The Socket.IO internal URL must be an explicit loopback host and port.'
check_json() {
    local url=$1 expected=$2 payload
    payload=$(curl --fail --silent --show-error --connect-timeout 5 --max-time 20 --retry 5 --retry-delay 2 --retry-connrefused --header 'Accept: application/json' "$url") || return 1
    printf '%s' "$payload" | php -r '$body = json_decode(stream_get_contents(STDIN), true); exit(is_array($body) && ($argv[1] === "ok" ? ($body["ok"] ?? null) === true : isset($body["domains"]) && is_array($body["domains"])) ? 0 : 1);' "$expected"
}
check_json "$chat_health" ok
stage=public-health
artisan up
check_json "$health_base/health" ok
check_json "$health_base/subjects" domains
changed=0
log "Deployment complete: $target_sha"
