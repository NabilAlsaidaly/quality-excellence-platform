# Quality & Excellence Platform

Multi-organization institutional quality and excellence management platform (Arabic RTL primary UI), currently at the **engineering foundation** stage.

## Current stack
- Laravel **13.39.0**, PHP **8.4**, Composer 2
- Filament **5.11.0** admin-panel foundation (Livewire **4.4.8**), panel path `/admin`
- MySQL **8.x** target; isolated SQLite PHPUnit bootstrap tests
- Node.js **24** LTS, Vite, Tailwind, PHPUnit, Laravel Pint and GitHub Actions CI

**Not implemented:** organization/membership tables, tenant authorization, full RBAC, evidence/document workflows, maturity stages, production admin access and end-user UI. Installed Filament login routes do not imply an authorized production panel.

## Setup from the default branch
Clone the repository and work on `main` only to **update your local base**, then use a task branch for any modification:

```sh
git switch main
git pull --ff-only origin main
composer install
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, replace `cp` with `Copy-Item .env.example .env`. Configure a dedicated local MySQL database and credentials in the untracked `.env` as described in [local setup](docs/setup/local-development.md). Never overwrite an existing production/application key.

```sh
php artisan migrate
npm ci
npm run build
php artisan serve
```

Health: `/up`. Filament admin login route: `/admin/login`; it is **not yet production hardened**. Never publish demo credentials or tenant data.

## Verification
```sh
composer validate --strict
composer audit
php artisan test
php vendor/bin/pint --test
php artisan route:list --path=admin
npm run build
```

GitHub Actions also verifies migrations using disposable MySQL 8.4 and HTTP bootstrap. See [Phase 01](docs/engineering/reports/PHASE-01-LARAVEL-BOOTSTRAP.md) and [Phase 02](docs/engineering/reports/PHASE-02-FILAMENT-FOUNDATION.md) reports.

## Architecture and contribution
Technical ADRs for [tenant isolation](docs/adr/ADR-001-tenant-isolation.md), [identity/authorization structure](docs/adr/ADR-002-identity-authorization.md) and [identifiers](docs/adr/ADR-003-identifiers-and-integrity.md) were **accepted by the engineering lead** on 2026-10-31. Owner-specific business policies remain open; see the [decision ledger](docs/architecture/decision-ledger.md).

Use Issue → scoped branch from updated `main` → tests/report → PR → required GitHub checks/review → lead-controlled merge. Repository is **public** and `main` is protected according to branch metadata. Detailed branch-rule enforcement requires verification in GitHub Settings. See [collaboration security](docs/engineering/collaboration-security.md). Do not work on obsolete bootstrap branches.

Complete [documentation index](docs/README.md) and [engineering governance](docs/engineering/README.md).