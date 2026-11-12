# منصة الجودة والتميّز — مرجع التسليم الحالي

main `03c829fde71bbb11754ef657bf2edfe50253068e` يحتوي النسخة الوظيفية TEST_ONLY بعد دمج PR69 وPR70. [CI ما بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح:885 تنفيذ اختبار/9560 تحقق. تتوفر أعمال فعلية للمنهجيات والوثائق والمراجعة والمراحل الخمس وإعادة التقييم في provider اختباري معزول؛ ليست تشغيلًا إنتاجيًا أو قبولًا عامًا للمتطلبات.

[الكتالوج العربي](docs/catalog/00-INDEX-AR.md) · [دليل Herd وMySQL](docs/operations/LARAVEL-HERD-WINDOWS-RUNBOOK-AR.md) · [المستخدمون وTinker](docs/operations/LOCAL-USERS-TINKER-AND-PERMISSIONS-AR.md) · [تقرير صاحب المشروع](docs/engineering/delivery/PROJECT-OWNER-COMPLETE-HANDOVER-SOURCE-AR.md) · [تدقيق37](docs/engineering/reports/FINAL-MAIN-AND-ISSUE37-AUDIT-2026-11-12.md) · [حالة التنظيف](docs/engineering/reports/FINAL-HOUSEKEEPING-STATUS-2026-11-12.md).

العروض15 تقاعدت بإذن صريح؛لا تستخدم روابطها القديمة أو حساباتها. Word خاص خارج Git. الإعداد العادي وبيانات التطوير محفوظة؛فحصHTTP Herd/phpMyAdmin لم ينجح حاليًا. قرارات الصلاحيات التشغيلية وscanner وonboarding/reference licences وL4 باقية. PR توثيق71 يحتاج مراجعة مستقلة؛لا self-merge.

## وصف الأساس التاريخي وإرشادات سابقة

الأقسام التالية توثق مرحلة الأساس عند كتابتها. عبارات «غير منفذ» أو «foundation» فيها لا تصف main الحالي؛ارجع للكتالوج ودليل التشغيل أعلاه قبل تطبيق أوامر قديمة على بيئة قائمة.

# Quality & Excellence Platform

Multi-organization institutional quality and excellence management platform (Arabic RTL primary UI), currently at the **engineering foundation** stage.

## Current stack
- Laravel **13.39.0**, PHP **8.4**, Composer 2
- Filament **5.11.0** admin-panel foundation (Livewire **4.4.8**), panel path `/admin`
- MySQL **8.x** target; isolated SQLite PHPUnit bootstrap tests
- Node.js **24** LTS, Vite, Tailwind, PHPUnit, Laravel Pint and GitHub Actions CI

Phase 03A adds structural organizations, memberships and hierarchical units with same-tenant database constraints, plus Redis infrastructure. Phase 03C adds the [technical tenant authorization foundation](docs/architecture/tenant-authorization-foundation.md): Spatie Permission 8.3.2 Teams, verified scoped context, explicit resource authorization and internal role-management services. The capability registry ships empty; no business role/permission matrix or public management interface is approved. Evidence/document workflows, maturity stages, production admin access and end-user UI remain unimplemented. A membership alone grants no permission; the admin development shell is explicitly denied outside local/testing.

Phase 04A adds [internal organizational hierarchy management](docs/architecture/organizational-hierarchy-management.md): create/rename/move/tree operations, serialized multi-level cycle checks and separate transactional unit-change history. No management endpoint or implicit hierarchy permission is enabled. Raw SQL can still bypass application cycle checks; deployment/recovery boundaries and actual concurrency evidence are in the [engineering report](docs/engineering/reports/PHASE-04A-ORGANIZATIONAL-HIERARCHY.md).

Phase 04B adds [internal organization provisioning/rename and scoped metadata reads](docs/architecture/organization-management-foundation.md), with exact name validation, current rename locks and separate transactional organization history. Provisioning creates no membership or privilege and grants no tenant-content access. FR-ID-001 remains partial: operational state and user-facing management authority are deferred. See the [permanent report](docs/engineering/reports/PHASE-04B-ORGANIZATION-MANAGEMENT.md).

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

Start the local Redis service and review MySQL trigger migration prerequisites in [Redis and integration setup](docs/setup/redis-local.md). `.env.example` selects Redis cache/queues via PhpRedis while retaining database sessions. MySQL remains authoritative. The regular tests stay independent of both services.

```sh
php artisan migrate
npm ci
npm run build
php artisan serve
```

Health: `/up`. Filament admin login route: `/admin/login`; it is **not yet production hardened**. Never publish demo credentials or tenant data.

On Windows with Laravel Herd, use the [Herd setup guide](docs/setup/herd-windows.md) and browse `http://quality-excellence-platform.test` directly; ordinary browsing does not require `php artisan serve`. The guide preserves an existing `phpMyAdmin.test` site, documents optional loopback-only MySQL 8.4/Redis services, and keeps application data separate from disposable integration databases. [Phase 03B local evidence](docs/engineering/reports/PHASE-03B-A-HERD-LOCAL-READINESS.md) and the [proposed authorization preview](docs/engineering/PHASE-03B-RBAC-DECISION-PREVIEW.md) record verified behavior and decisions still awaiting approval.

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
