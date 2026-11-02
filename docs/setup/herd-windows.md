# Windows Herd local readiness

Issue #16, checked 2026-11-02. This setup serves the existing repository through Herd; it neither creates another Laravel application nor authorizes production access. Preserve all existing sites, especially `phpMyAdmin.test`. See the [factual execution report](../engineering/reports/PHASE-03B-A-HERD-LOCAL-READINESS.md) and the [proposed authorization preview](../engineering/PHASE-03B-RBAC-DECISION-PREVIEW.md).

## Inspect before changing anything

From the repository root, inspect `git status`, `herd --version`, `herd links --json`, `herd which-php`, `herd php -v`, `php -m`, and `herd processes`. Do not publish outputs containing home paths, credentials or session values. Check native database/Redis services and Docker before starting a fallback. phpMyAdmin is only an interface: inspect its configured host/port without printing auth settings/passwords. Do not reinstall/relink it, change its server list, or edit hosts/nginx manually.

The verified environment had Windows 11, Herd 2.0.4/PHP 8.4.27 and a native MySQL97 service on 3306; phpMyAdmin uses 127.0.0.1:3306 with cookie authentication. Existing service credentials were not available to this task (the one blank-password connection probe was denied). No databases or settings on that server were modified. Because the task requires MySQL 8.x, a separate 8.4.13 service was provisioned on loopback 13306; it does not replace the native service or change phpMyAdmin. Reuse an accessible supported server instead on other machines when possible.

## Link the existing repository

Only after confirming that the alias is free or already points here:

```powershell
herd link quality-excellence-platform --isolate=8.4 --no-interaction
herd which-php
```

Set ignored `.env` APP_URL to `http://quality-excellence-platform.test`; retain the existing APP_KEY and APP_ENV=local. Generate a key only for a genuinely new environment with no key, never overwrite one. Start with HTTP; no certificate/HTTPS changes are required. Browse `/`, `/up`, `/admin` and `/admin/login`. Herd serves port 80 independently of any existing artisan dev/server process. The task left pre-existing dev/Vite processes untouched and did not start queue workers.

## Optional project-only MySQL fallback

Use Docker Desktop's Linux engine only if no accessible compatible local service exists. The optional `compose.mysql-local.yml` pins MySQL 8.4.13 with a persistent named volume, TCP health check and loopback-only published port. It requires a private root secret, with no committed default. Choose a free, non-reserved port. On this host 3307 was excluded by Windows; 13306 was verified available. Inspect `netsh interface ipv4 show excludedportrange protocol=tcp` and listeners; do not alter exclusion ranges.

Generate local random secrets in the already-ignored `.local-tools` directory without printing them. For example, in PowerShell:

```powershell
New-Item -ItemType Directory -Force .local-tools | Out-Null
$localSecretFile = Join-Path $PWD '.local-tools/herd-mysql.env'
if (Test-Path $localSecretFile) { throw 'Preserve the existing service secrets; inspect first.' }
$crypto = [Security.Cryptography.RandomNumberGenerator]::Create()
$rootBytes = New-Object byte[] 24
$runtimeBytes = New-Object byte[] 24
$crypto.GetBytes($rootBytes)
$crypto.GetBytes($runtimeBytes)
$crypto.Dispose()
$rootSecret = -join ($rootBytes | ForEach-Object { $_.ToString('x2') })
$runtimeSecret = -join ($runtimeBytes | ForEach-Object { $_.ToString('x2') })
[IO.File]::WriteAllText($localSecretFile, "QUALITY_MYSQL_PORT=13306`nQUALITY_MYSQL_ROOT_PASSWORD=$rootSecret`nQUALITY_MYSQL_RUNTIME_PASSWORD=$runtimeSecret`n")
docker compose --env-file .local-tools/herd-mysql.env -f compose.mysql-local.yml up -d --wait
docker compose --env-file .local-tools/herd-mysql.env -f compose.mysql-local.yml ps
```

Do not print `docker compose config` without `--quiet`: expanded configuration contains the secret. Never commit this private file or `.env`, expose the port beyond loopback, or reuse these development credentials in production. Retain the named volume and secrets across restarts; replacing the secret file does not reset an existing MySQL account. `stop` retains the service; `down` retains the volume. Never use `down -v` to troubleshoot an existing database.

Using the local administrator securely, verify actual server version/port, selected database and its table count before initializing. The container initializes `quality_platform` only on an unused volume. Do not assume an existing volume is empty. Check that both the chosen database and runtime account are unused; do not overwrite either. Create a dedicated runtime user with SELECT/INSERT/UPDATE/DELETE on this application schema only, using its private generated password. Do not copy real customer data or grant platform/tenant roles.

Use a separate migration operator for the existing six additive migrations, following [trigger prerequisites and recovery](redis-local.md#trigger-migration-deployment-prerequisites-and-recovery). Confirm the exact local target and pending migrations first. The disposable local container's existing root account can serve as operator; it is not a production account recommendation. Keep operator credentials out of application `.env` and scope them to the migration process. No global binlog/trust settings are changed. Never run migrate:fresh, db:wipe, truncation or destructive rollback against the application database. After migration, verify all six journal entries, all 12 tables, both units triggers and foreign keys using operator metadata access. Runtime-only accounts may not see trigger definitions.

Verified ignored application settings (password value deliberately omitted):

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://quality-excellence-platform.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=13306
DB_DATABASE=quality_platform
DB_USERNAME=quality_platform
DB_PASSWORD=<private runtime password>
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
```

## Redis and smoke checks

Reuse a healthy existing service; otherwise start the existing `docker compose -f compose.redis.yml up -d --wait` only when its loopback port is free. See [Redis local setup](redis-local.md) for other ports. The actual application uses `quality:local:` plus the cache prefix. Test only UUID-named synthetic probe keys with explicit TTL and individual invalidation; no flush or institutional content. Queue after_commit remains true and database sessions remain selected. `/up` is liveness, not proof of either service's health.

Validate unauthenticated `/admin` -> login, login form, CSRF rejection and database-backed session persistence through actual Herd HTTP. A temporary synthetic account with no roles/memberships may be used only in a confirmed new/local database. Verify invalid-password rejection, successful local shell login, logout and revoked session, then remove only that fixture and its owned session rows. Follow the Livewire update URL advertised by the page, not a hard-coded pre-v4 URL. Do not log CSRF/session/password values. No authentication gate or authorization bypass is added; local shell success does not satisfy Issue #13 or authorize production access.

## Existing verification commands

```powershell
composer validate --strict
composer check-platform-reqs
php artisan about
php artisan migrate:status
php artisan test
php vendor/bin/pint --test
npm ci
npm run build
git diff --check
```

Use Node 24 LTS for assets; no global Node upgrade is needed. On the inspected host Node 25 was global and an existing workspace Node 24 was used. If Vite is running, Windows can lock its native dependency binary and make npm ci fail. Do not kill unknown processes. Use a separate ignored installation directory with the same package.json/package-lock.json (`npm ci --prefix .local-tools/phase3b-build`), and preserve identical locked files when restoring dependencies. The actual root build must then succeed. The report distinguishes failed root installs from the successful isolated install/build. Stop an owned dev process only when authorized, before a future clean root npm ci.

General tests force actual SQLite :memory:/array cache/sync queue/array sessions, independently of application `.env`. Run the destructive MySQL suite only against a separate, confirmed disposable `quality_platform_ci` service with explicit MYSQL_TESTS_ENABLED=1 and operator privileges. The Redis suite requires REDIS_TESTS_ENABLED=1 and owns only testing UUID keys. Neither suite should migrate the application database. Keep integration variables in a separate shell/process and remove them afterward; see existing [integration commands](redis-local.md#independent-test-suites).

Official references: [Herd linking](https://herd.laravel.com/docs/windows/getting-started/applications#linking-an-existing-application), [official MySQL image](https://hub.docker.com/_/mysql), [Laravel 13 testing](https://laravel.com/docs/13.x/testing). Reference books named by the task were not provided in this repository and were not claimed as reviewed.
