# Push `main` to the live server

The workflow [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml)
runs Laravel tests on a disposable MySQL database and the Socket.IO tests. A
successful push to `main` then deploys that exact commit over SSH. Pull requests
run tests only. **Actions → Test and deploy → Run workflow**, selecting `main`,
also runs the pipeline. An older queued deployment is skipped if `main` has
already advanced. Production deployments are serialized.

CI also checks the developer portal interactions and exercises deployment
success, outdated-commit skips, dirty-checkout refusal and migration/health
failure recovery using command doubles. These tests never connect to production.
The tested script is passed as a quoted Bash command with closed stdin so
dependency commands cannot consume the remaining deployment script.

The confirmed target is `root@157.245.125.79`, SSH port `22`, application
directory `/var/www/html/nearby_teachers`, with public base URL
`http://157.245.125.79/nearby_teachers/public`. The workflow is ready in source;
it becomes operational after the following one-time server and GitHub setup.

## 1. Prepare the existing server checkout

Keep the existing live `.env`, database and `storage` files. The server directory
must be an installed Git checkout of `Priyanshu9918/nearby_teachers`, with an
`origin` remote and no modified tracked files. Preserve/reconcile any manual
server edits before enabling deployments. The script does not use `git clean`
or reset away changes. Do not copy `.env.example` over the live `.env` or generate
a new `APP_KEY`/JWT secret.

On the server, check:

```bash
cd /var/www/html/nearby_teachers
git remote -v
git status --short
git ls-remote origin refs/heads/main
php --version
node --version
composer --version
```

Required: systemd, Git, curl, Composer 2, PHP 8.2+ with Laravel's extensions
including CLI `pdo_mysql`, Node.js 22+ and npm. PHP/Node must be installed
system-wide and executable by `www-data`; a root-only nvm installation will
not run these services. Existing Composer and realtime dependencies must be
installed (`vendor/autoload.php` and `realtime/node_modules`), and the database
must already be configured. The workflow does not install operating-system
packages or provision the web server/database. Follow Laravel's
[server requirements and permissions](https://laravel.com/docs/11.x/deployment#server-requirements).

For a private repository, the server's root account also needs GitHub **read**
access to `origin`; the workflow's SSH key only provides access to the server.
Use a separate repository deploy key for server → GitHub access, configure its
verified GitHub host key, and use an SSH origin such as:

```bash
git remote set-url origin git@github.com:Priyanshu9918/nearby_teachers.git
git ls-remote origin refs/heads/main
```

The existing HTTPS origin is also supported if its noninteractive credentials
are already configured. Never put access tokens in a committed remote URL.
GitHub describes repository-scoped read access in
[Managing deploy keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys).

## 2. Convert manual chat and queues to managed services

Keep the existing environment values; verify these settings in the live `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://157.245.125.79/nearby_teachers/public
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=socketio
SOCKET_IO_HOST=127.0.0.1
SOCKET_IO_PORT=6001
SOCKET_IO_LARAVEL_URL=http://157.245.125.79/nearby_teachers/public
SOCKET_IO_INTERNAL_URL=http://127.0.0.1:6001
```

Retain the existing `SOCKET_IO_SHARED_SECRET` (at least 32 characters), Firebase,
SMTP and database credentials. The services need to read `.env`. If the current
file is root-only, grant the service group private read access deliberately:

```bash
chgrp www-data .env
chmod 640 .env
```

First stop the manually running `npm run chat:start` and both `queue:work`
commands with **Ctrl+C in their respective terminals**. Stop any old process
manager copies too. Do not leave manual workers running alongside the managed
ones. The installer refuses to take a chat port owned by another process.

After this setup script is present in the server checkout, run as root:

```bash
cd /var/www/html/nearby_teachers
bash scripts/setup-deploy-services.sh /var/www/html/nearby_teachers www-data
systemctl status nearby-teacher-chat nearby-teacher-broadcasts nearby-teacher-notifications --no-pager
curl --fail http://127.0.0.1:6001/health
```

It installs/enables/starts these systemd services, which restart after crashes
and reboots:

| Unit | Command run as `www-data` |
|---|---|
| `nearby-teacher-chat` | Node `realtime/index.js` |
| `nearby-teacher-broadcasts` | PHP `artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30` |
| `nearby-teacher-notifications` | PHP `artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=30` |

The installer grants the service user owner-write access within `storage` and
`bootstrap/cache`, retaining existing private file modes and contents. It does
not chmod files to `777` or overwrite `.env`. Laravel loads its own environment;
the Node entry point reads `.env` with dotenv. The units do not treat Laravel's
`.env` as a shell/systemd environment file. Logs go to journald and existing
Laravel logs:

```bash
journalctl -u nearby-teacher-chat -u nearby-teacher-broadcasts -u nearby-teacher-notifications -f
```

The deploy script requires all three services to be installed and active. Your
existing Apache/PHP-FPM configuration and Socket.IO reverse proxy remain
responsible for HTTP traffic; the installer does not create them.

## 3. Allow GitHub Actions to connect

Generate a dedicated server-access key on your trusted workstation. Keep its
private half out of Git, chat and server logs:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/nearby_teacher_actions -C github-actions-nearby-teacher -N ''
ssh-copy-id -i ~/.ssh/nearby_teacher_actions.pub -p 22 root@157.245.125.79
```

If using the server console instead, append only the `.pub` file's line to
`/root/.ssh/authorized_keys`, with directory mode `700` and file mode `600`.
The pipeline uses unattended key authentication, so this dedicated private key
must not require a passphrase. Its root access can change the live server;
restrict who can change `main`, workflow files and repository Actions secrets.

Collect the server's public host key on your workstation:

```bash
ssh-keyscan -t ed25519 -p 22 157.245.125.79 > nearby-teacher-known-hosts
ssh-keygen -lf nearby-teacher-known-hosts
```

**Verify that fingerprint independently** against the server console output:

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

Only after matching, use the complete `nearby-teacher-known-hosts` line as the
known-hosts secret. `ssh-keyscan` alone does not verify the server's identity.
The workflow enforces host-key checking and never auto-trusts an unknown host.

In the GitHub repository, open **Settings → Secrets and variables → Actions**.
Under **Secrets → New repository secret**, add:

| Required secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | Complete contents of `~/.ssh/nearby_teacher_actions`, including BEGIN/END lines |
| `SSH_KNOWN_HOSTS` | Independently verified server host-key line collected above |

Use **repository** secrets: this workflow has no GitHub environment binding.
These secrets grant runner → server access, not server → GitHub Git access.
See GitHub's [repository secrets instructions](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets#creating-secrets-for-a-repository).

Under **Variables**, these optional repository variables override the defaults:

| Variable | Default |
|---|---|
| `SSH_HOST` | `157.245.125.79` |
| `SSH_USER` | `root` |
| `SSH_PORT` | `22` |
| `DEPLOY_PATH` | `/var/www/html/nearby_teachers` |
| `APP_BASE_URL` | `http://157.245.125.79/nearby_teachers/public` |
| `DEPLOY_APP_USER` | `www-data` |
| `DEPLOY_WEB_SERVICE` | `auto` |

`DEPLOY_WEB_SERVICE=auto` selects the single active `php*-fpm.service`, or active
`apache2.service` if there is no active PHP-FPM. Multiple active PHP-FPM versions
require an explicit value, for example the actual `php8.2-fpm.service` used by
this site. The workflow's SSH user must have root privileges as currently
implemented. If changing the SSH port, update the verified known-hosts entry
to its `[host]:port` form too.

## Deploy, checks and recovery

Push/merge the workflow and scripts to `main`, then use the Actions run to
inspect tests and deployment. Each deployment fetches `origin/main`, checks out
the exact tested commit in place, installs locked production dependencies,
runs migrations, caches config/routes/views, restarts the three managed
services and reloads the selected PHP web service. It checks local chat health,
then public `/health` and database-backed `/subjects`.

The site enters maintenance during the update; this is **not zero-downtime
deployment**. Existing `.env` and storage files remain. No production test,
seed, key-generation or database-clearing command runs. Back up the database
and review forward migrations before pushing schema changes; automatic
database rollback is not attempted. Laravel documents production caching in
its [deployment guide](https://laravel.com/docs/11.x/deployment#optimization).

A failure after maintenance begins keeps the site in maintenance and stops
the managed chat/queue services. Read the failed Actions stage first, then
inspect the server:

```bash
cd /var/www/html/nearby_teachers
git rev-parse HEAD
systemctl status nearby-teacher-chat nearby-teacher-broadcasts nearby-teacher-notifications --no-pager
journalctl -u nearby-teacher-chat -u nearby-teacher-broadcasts -u nearby-teacher-notifications -n 80 --no-pager
```

Repair the reported dependency/configuration/migration issue before exposing
the site. A reviewed fix can be applied manually or in a follow-up deployment;
the script refuses to start while maintenance is already active or required
services are stopped, so recover those states deliberately first. After the
failed step is repaired, rebuild caches as the service user, restart services,
reload the selected PHP web service and leave maintenance:

```bash
runuser -u www-data -- php artisan config:cache
runuser -u www-data -- php artisan route:cache
runuser -u www-data -- php artisan view:cache
systemctl restart nearby-teacher-chat nearby-teacher-broadcasts nearby-teacher-notifications
# Reload the actual PHP web service selected for this site.
systemctl reload apache2.service
curl --fail http://127.0.0.1:6001/health
runuser -u www-data -- php artisan up
curl --fail http://157.245.125.79/nearby_teachers/public/health
curl --fail http://157.245.125.79/nearby_teachers/public/subjects
```

Replace the example Apache reload with the site's PHP-FPM unit when applicable.
Keep maintenance active if repairs or health checks fail. Reverting application
code alone does not undo migrations; do not run an automatic `migrate:rollback`
against live data.
