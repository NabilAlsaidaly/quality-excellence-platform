# Local development

## Prerequisites

- PHP 8.4 with Composer 2; required extensions include ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, session, tokenizer and XML. Enable pdo_mysql for development and pdo_sqlite for the isolated test suite.
- Node.js 24 LTS and npm (see `.nvmrc`). Lockfiles are committed; install from them.
- A dedicated MySQL 8.x development database and local account. Never use a production database.
- Git. Windows users can use Laravel Herd; run `npm.cmd` if PowerShell blocks npm.ps1.

## Fresh clone

```sh
git clone https://github.com/NabilAlsaidaly/quality-excellence-platform.git
cd quality-excellence-platform
git switch main
composer install
```

Copy `.env.example` to `.env` (`cp .env.example .env`, or `Copy-Item .env.example .env` in PowerShell). Generate a unique local key:

```sh
php artisan key:generate
```

The example defaults to MySQL and `APP_DEBUG=false`. Set `APP_ENV=local`, `APP_URL=http://127.0.0.1:8000`; enable debug only for local development if needed. Never commit `.env`, a generated key or credentials. Keep debug disabled outside local development. The example includes optional framework Redis/AWS/mail placeholders; no external service is required.

## MySQL

Create a disposable development database using a local MySQL administrator:

```sql
CREATE DATABASE quality_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'quality_platform'@'127.0.0.1' IDENTIFIED BY '<choose-a-local-password>';
GRANT ALL PRIVILEGES ON quality_platform.* TO 'quality_platform'@'127.0.0.1';
```

Put your chosen local values in ignored `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=quality_platform
DB_USERNAME=quality_platform
DB_PASSWORD=<your-local-password>
```

```sh
php artisan config:clear
php artisan migrate
php artisan migrate:status
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/` (framework welcome), `/up` (framework health), and `/admin/login` (Filament 5 login shell; not production-hardened). Database-backed sessions/cache/queues use the standard migrations. The health endpoint proves application boot, not database or external-service availability. For asset development, run `npm run dev` in another terminal. On Windows use these separate commands; the upstream `composer dev` includes Pail, which requires Unix process-control extensions.

`composer setup` is a convenience only after configuring `.env` and creating the local database; it installs locked dependencies, generates a local key, migrates and builds assets. Do not run it against an existing shared environment because it regenerates the key.

## Tests and checks

```sh
composer validate --strict
composer check-platform-reqs
composer audit
php artisan --version
php artisan about
php artisan config:clear
php artisan test
php vendor/bin/pint --test
npm ci
npm run build
npm audit
```

PHPUnit selects `testing`, in-memory SQLite, array cache/session/mail and synchronous queues in `phpunit.xml`. The database override is forced to avoid using a shell's MySQL connection accidentally. Tests never require the developer's MySQL database. `RefreshDatabase` exercises only framework migrations in SQLite. Phase 01 local MySQL migration verification was not reported; CI includes a dedicated MySQL 8.4 service and passes the framework migration checks. Domain-specific tenant constraints require additional MySQL integration tests when built. Never run destructive migration commands against shared databases.

## Troubleshooting

If dependencies cannot reach package servers, verify network/proxy access; do not disable TLS checks. If PHP's configured temp path is unavailable, supply a writable local path without modifying global configuration, for example `php -d sys_temp_dir=.local-tools/tmp /path/to/composer.phar install` after creating that ignored directory. No local tooling downloads belong in Git.

## Workflow and CI

Start each task from fetched, updated `main`, create its task branch, run checks, commit Conventional Commits and open a Draft PR. Only the lead approves/merges. Do not force-push or modify repository settings. PR #2 governance, PR #5 Laravel and PR #9 Filament are merged. The public repository's `main` now reports `protected=true`; the GitHub integration cannot verify individual branch-rule settings (403), so the owner must confirm required review and CI enforcement in GitHub Settings. Phase 01 explicitly authorizes minimal CI; changes to the workflow require lead review before merge. Actions are pinned to verified full commit SHAs; update pins through reviewed PRs after verifying upstream tags. CI has read-only contents permission, uses `pull_request` (never `pull_request_target`), and no repository secrets or deployment jobs.

Official references: [Laravel installation](https://laravel.com/docs/13.x/installation), [support policy](https://laravel.com/docs/13.x/releases), [testing](https://laravel.com/docs/13.x/testing), [deployment configuration guidance](https://laravel.com/docs/13.x/deployment), [Node release policy](https://nodejs.org/en/about/previous-releases). No deployment is performed.
