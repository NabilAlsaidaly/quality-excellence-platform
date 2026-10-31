# quality-excellence-platform

Quality & Excellence Platform is a future multi-tenant institutional quality and excellence management SaaS, intended for an Arabic RTL product interface. Phase 01 provides the Laravel engineering foundation only; no tenant, authentication, authorization or other business workflows exist.

Stack: Laravel 13, PHP 8.4, Composer 2, MySQL 8.x target, Node.js 24 LTS, npm, Laravel's default Vite/Tailwind setup, PHPUnit and Pint. Exact verified versions and test evidence are in the [engineering report](docs/engineering/reports/PHASE-01-LARAVEL-BOOTSTRAP.md).

## Getting started

Install PHP 8.4 (pdo_mysql and pdo_sqlite), Composer, Node.js 24 LTS/npm and a dedicated local MySQL 8.x database. Clone the repository, switch to `chore/phase-01-laravel-bootstrap`, then:

```sh
composer install
cp .env.example .env
php artisan key:generate
```

On PowerShell, use `Copy-Item .env.example .env`. Create your local MySQL database/account and configure `DB_*` in `.env` using the complete [local setup guide](docs/setup/local-development.md). Then:

```sh
php artisan migrate
npm ci
npm run build
php artisan serve
```

The framework welcome page is `/`; Laravel health is `/up`. Use `npm run dev` for frontend development. No product UI has been designed yet. Keep `.env` and keys private; debug is off by default and must stay off outside local development.

## Verification

```sh
composer validate --strict
composer check-platform-reqs
composer audit
php artisan test
php vendor/bin/pint --test
npm run build
```

Tests use isolated in-memory SQLite, not MySQL. CI also provisions disposable MySQL for migration verification. Local MySQL verification remains outstanding; see the report for actual results.

## Working conventions

Use a task branch from updated `main`, focused Conventional Commits and a Draft PR. Never commit directly to `main` or merge your own PR. The dedicated Phase 01 branch is `chore/phase-01-laravel-bootstrap` ([Issue #3](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/3)). [Governance PR #2](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/2) is a separate unmerged dependency; its governance files are not duplicated here. Workflow changes need lead review. Branch protection has not been verified.

See [docs index](docs/README.md), [architecture conventions](docs/architecture/foundation.md) and permanent reports in `docs/engineering/reports/`. Tenant isolation, SaaS identity/RBAC, business schema, admin UI and other owner decisions remain deferred. Standard users/cache/jobs migrations are provisional Laravel scaffolding. No deployment or production changes are included.
