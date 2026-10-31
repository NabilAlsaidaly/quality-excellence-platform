# PHASE-03A — Tenant schema, global identity and Redis infrastructure

Date: 2026-10-31 (Asia/Damascus). Contract: Issue #11 and owner-supplied phase3A.md. This is implementation evidence, not approval of unresolved product policy.

## 1. Executive Summary

IMPLEMENTED: additive organizations, organization_memberships and organizational_units schema, models/relationships/factories, public ULID generation/model immutability, composite same-tenant parent FK, restrictive references and MySQL direct-self-parent triggers. Redis development Compose, PhpRedis cache/queue configuration, after-commit behavior, independent integration suites and CI extensions are implemented. TESTED locally on SQLite, real MySQL 8.4.11 and Redis 8.2.11. Draft PR/remote CI publication evidence will be added once observed. No UI, role/permission, tenant authorization workflow or production change.

## 2. Scope and Objectives

Persist the approved minimum tenant/identity structure while keeping global users separate from membership. Add concrete same-tenant hierarchy integrity and reproducible Redis infrastructure. Preserve all previous tests, dependencies, sessions, UI contributor scope guard and presentation files. General tests remain isolated SQLite/array/sync. Deliver additive migrations, negative MySQL tests, genuine Redis tests, documentation, committed report and Draft PR without merging.

## 3. Authoritative Requirements and ADR References

Read AGENTS.md, CONTRIBUTING.md, README, architecture foundation/decision ledger, accepted ADR-001/002/003, local setup, existing CI and Issue #11 including its approved Redis/unit clarification before implementation. SRS v3.0 was accessible via connected Google Drive at the exact document referenced in the task; reviewed relevant BR-001..005, FR-ID-001..012, DATA-01..10, NFR-SEC-001..012, NFR-REL-001..006, NFR-PERF-001..004 and listed decision-register items. Private source content was not copied into the public repository. ADR technical acceptance supersedes stale SRS technical OPEN labels for the selected key/membership capabilities, without approving owner access/lifecycle policy.

| Requirement/acceptance | Status and evidence |
| --- | --- |
| BR-001, DATA-01/02, FR-ID-002 structural isolation | TESTED: organization ownership, composite FK and two-organization MySQL rejection |
| ADR-002, FR-ID-003, D-021 technical capability | TESTED: global user with multiple distinct memberships and unique pair constraint; no grant/approval policy |
| ADR-003, D-017 identifiers | TESTED: MySQL unsigned BIGINT metadata, unique public ULIDs, model immutability and public route key selection |
| DATA-07 hierarchy | PARTIAL BY APPROVED SCOPE: tenant parent FK/direct self rejection tested; complete cycle prevention DEFERRED |
| DATA-08/09/10 | TESTED: indexes/candidate key inspected; restrictive references; fresh migrations/rollback/remigration |
| NFR-SEC-004/005 | TESTED structurally: existing password hashing/hidden attributes retained; no committed secrets |
| NFR-REL-001 queue timing | TESTED: real Redis queue receives test-only payload after commit; rollback does not add payload |
| NFR-PERF-003 | IMPLEMENTED: organization/parent composite lookup index; no performance SLA invented |
| FR-ID-004..012 and remaining security/reliability/domain requirements | NOT IMPLEMENTED beyond existing framework compatibility and stated infrastructure; owner-approved Actions/Policies required |
| Redis clarification, regression, CI, report delivery | Local evidence below; publication status in sections 18/23/24 |

## 4. Existing Repository Audit Findings

Repository origin verified; clean assigned branch was created locally by tracking existing `origin/feat/11-tenant-schema-foundation`. It contains fetched `origin/main` commit `56e51ee` (UI scope CI guard); `git merge-base --is-ancestor origin/main HEAD` exited 0 before changes. No user changes were discarded. `feat/4-arabic-filament-shell` was never checked out or modified.

Existing framework tables: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs and migrations bookkeeping. Users has unsigned BIGINT id/unique email; sessions.user_id is indexed without an FK. No existing tenant tables. Three applied framework migration files and UserFactory are unchanged. User preserves fillable/hidden attributes, password cast and Authenticatable/Notifiable behavior; only membership/organization relationships were added. Existing Laravel 13.39.0, PHP 8.4.27, Filament 5.11.0, Livewire 4.4.8, PHPUnit 12.5.42, Pint 1.32.1, Composer 2.10.3 and Node 24.22.2/npm 11.19.1 are retained; no package or lockfile changes. Baseline general suite: 5 passed/12 assertions.

Existing Redis connections used PhpRedis, DB 0/default and DB 1/cache, database cache/queue defaults and after_commit=false. Sessions were database-backed and remain so. Docker Desktop was initially stopped; it was started for task-local test containers. Local host MySQL 9.7 binary was not used; a separate MySQL 8.4 container on loopback port 33068 and Redis on loopback 6387 were used. No developer database or production service was migrated.

## 5. Database Schema Overview

See `docs/architecture/tenant-schema-foundation.md` for the plan recorded before migrations. Organizations is the tenant root. Global User has many OrganizationMembership records and belongs to many Organizations through that table. Each unit belongs to an Organization and optionally to another unit in that same Organization. No membership state, organization state machine, roles or provisioning behavior.

## 6. Tables, Columns, and Data Types

| Table | Columns |
| --- | --- |
| organizations | id BIGINT UNSIGNED auto-increment PK; public_id CHAR(26) unique required ULID; name VARCHAR(255) required; nullable Laravel timestamps |
| organization_memberships | id BIGINT UNSIGNED auto-increment PK; user_id/organization_id BIGINT UNSIGNED required; timestamps |
| organizational_units | id BIGINT UNSIGNED auto-increment PK; public_id CHAR(26) unique required ULID; organization_id BIGINT UNSIGNED required; parent_id BIGINT UNSIGNED nullable; name VARCHAR(255) required; timestamps |

No existing columns altered, users recreated, data backfilled or real records seeded. Laravel's ULID concern is restricted to `public_id` so `id` remains incrementing/int. A small shared model concern rejects persisted public_id changes through model updates; guarded fillable fields prevent request-array assignment. Raw SQL/query-builder/quiet saves can bypass model events: no public bulk-write surface is introduced, and future Actions must preserve immutability. ULIDs are identifiers, not credentials.

## 7. Foreign Keys and Indexes

Membership user_id -> users.id and organization_id -> organizations.id use RESTRICT for update/delete. Unique (user_id,organization_id) prevents duplicate membership; organization_id index supports inverse lookup. Units.organization_id -> organizations.id is restrictive. Candidate key UNIQUE (organization_id,id) permits standard MySQL 8.4 referenced-key enforcement. Index (organization_id,parent_id) supports hierarchy lookups and FK checks. Composite FK (organization_id,parent_id) -> organizational_units(organization_id,id) rejects cross-tenant parents on insert/update. Public IDs have database uniqueness constraints. No destructive CASCADE.

## 8. Tenant Isolation Guarantees

TESTED database guarantees: required ownership, existing referenced user/organization/parent, unique membership pair and public IDs, same-organization parent references, parent organization-change rejection with children, restrictive deletion. NOT IMPLEMENTED: tenant context resolver, authenticated organization selection, scoped read services/policies, HTTP IDOR protection, file/download/job authorization. No global scope or implicit administrator bypass. Membership/ULID knowledge confers no access. Organization-owned fields are not mass assignable; trusted backend association/factories remain possible.

Documented global allowlist: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs and migrations are global/framework infrastructure. Organizations is the root; membership/unit records carry organization_id. Framework jobs/cache rows are not permission-bearing domain records.

## 9. Organization Membership Design

Normal Eloquent model with its own BIGINT id, explicit belongsTo user/organization, HasMany and BelongsToMany inverse relations; no custom pivot package. Guard all membership fields from mass assignment. The two FK values must be assigned explicitly using trusted association/context. Factories intentionally unguard synthetic fixtures. Multiple organizations per global user is supported, not automatically granted. Existing hashing and password/remember-token hiding are regression tested.

## 10. Organizational Hierarchy Integrity

NULL parent permits roots. Composite FK enforces tenant integrity, not graph acyclicity. MySQL CHECK cannot reference AUTO_INCREMENT id; narrow BEFORE INSERT/UPDATE triggers reject direct self-parenting even for raw SQL. Eloquent saving also rejects direct self-parenting in isolated SQLite tests. Complete multi-level cycle detection and safe concurrent parent changes remain DEFERRED to an approved Application Action. No tenant organization migration/reparent API was added.

MySQL binary logging initially rejected trigger creation (error 1419). Disposable local/CI server initialization sets log_bin_trust_function_creators=1 using the known service-only root fixture, then migrations/tests use the DB-scoped test user. No setting on existing/shared/production MySQL was changed. Future shared migration accounts/settings require lead review. Missing privileges fail migration rather than silently dropping the invariant. MySQL DDL is not transactionally rolled back as a whole; verify prerequisites before non-disposable migration and handle any partial failure under a reviewed recovery plan.

## 11. Redis Architecture and Configuration

Compose pins official Redis 8.2.11-alpine, loopback-only configurable published port, health check, local named AOF volume and no committed production password. Existing PhpRedis connections reused; no dependencies added. .env.example edits are Redis cache/queue/DB/prefix lines only; localization and database session lines unchanged. Docker image/runtime observed: Redis 8.2.11; PhpRedis extension 6.1.0. MySQL remains authoritative.

## 12. Cache and Queue Strategy

Default Redis namespace `quality:{APP_ENV}:`; cache store adds `cache:`. Future allowed tenant keys must include tenant ULID, category, resource/version, explicit finite TTL and invalidation after committed source changes. No institutional content, tokens or authorization decisions are cached. Redis unavailable while configured is an error; no silent failover added. after_commit=true is verified against installed RedisConnector/Queue transaction callback code and real queue behavior. No domain jobs/workers; the no-op job class exists only within integration tests and is never processed. Queue/cache keys in tests use UUIDs and exact individual cleanup, never FLUSHDB/FLUSHALL. General tests remain SQLite/array/sync with the original phpunit.xml unchanged.

## 13. Files Created and Modified

Created: three timestamped migrations; Organization, OrganizationMembership, OrganizationalUnit and HasPublicUlid concern; three factories; TenantSchemaTest; MySQL test base/integration suite/config; Redis integration suite/config; compose.redis.yml; schema design/local Redis setup; this report. Modified: User relationships only, config/database.php prefix, config/cache.php prefix, config/queue.php Redis after_commit, .env.example Redis settings, CI service/integration steps, README/docs index/setup/foundation status. The final changed-file manifest is appended after diff review. No files deleted. No composer/npm dependency/lockfiles or forbidden UI paths changed. Existing Issue #4 CI guard preserved verbatim.

## 14. SQLite Test Results

`P artisan test`: **17 passed, 60 assertions**, exit 0 after schema implementation; original five tests preserved. Coverage: organization creation/multiple organizations, ULID uniqueness/integer key and binding, global user with multiple memberships/relations, authentication hashing/hiding compatibility, duplicate membership, roots/children, immutability on both public models, protected mass assignment, direct self-parent rejection and SQLite composite FK negative case. SQLite is not presented as proof of MySQL semantics. Final regression output is recorded below when complete.

Here and below `P` means the actual local prefix `php -d sys_temp_dir=.local-tools/tmp`; `C` means `P C:/Users/nabil/.config/herd/bin/composer.phar`. The writable temp override addresses the already-known host PHP temp-path restriction without changing global tools. Windows commands use npm.cmd with the existing workspace Node 24 first on PATH.

## 15. MySQL Integration Test Results

Dedicated config `phpunit.mysql.xml`; `MYSQL_TESTS_ENABLED=1`, DB_HOST=127.0.0.1, DB_PORT=33068, DB_USERNAME=quality_platform_ci, DB_PASSWORD=disposable-ci-password. These values refer only to the task-created container/database. Before destructive hooks: reject cached config, require testing/mysql/explicit opt-in/exact configured AND actual database `quality_platform_ci`/no DB_URL override. No normal developer DB can be selected by the config.

`P vendor/bin/phpunit --configuration phpunit.mysql.xml --stop-on-error`: **15 passed, 40 assertions**, exit 0, actual MySQL **8.4.11**. Each test's DatabaseMigrations executes fresh migration and cleanup; additional explicit rollback/remigration test passed. Tests verify BIGINT types, candidate/composite keys, existing references, duplicate membership/ULID rejection, valid roots/children/multi-memberships, cross-tenant parent insert/update, referenced parent's organization update, raw self-parent insert/update and restrictive user/organization/parent deletion (including unit-only organization).

Initial attempt before disposable trigger prerequisite: exit 1, **15 errors/0 assertions**, all MySQL 1419. After initializing only the disposable server, 15/39 passed; added independent unit-only organization deletion coverage then 15/40 passed. No failing assertion removed or skipped. Without MYSQL_TESTS_ENABLED, filtered suite deliberately exits **2** with the opt-in error before migration, confirming the safety barrier.

## 16. Redis Integration Test Results

`REDIS_TESTS_ENABLED=1 REDIS_HOST=127.0.0.1 REDIS_PORT=6387 P vendor/bin/phpunit --configuration phpunit.redis.xml --stop-on-failure`: **4 passed, 19 assertions**, exit 0, real Redis **8.2.11** / PhpRedis **6.1.0**. Physical namespaced key checked by independent raw client, write/read/invalidation and TTL expiration verified, cache operations emitted zero SQL and preserved an isolated organization sentinel, queue only pushed after commit and not on rollback. Redis suite uses in-memory SQLite rather than developer/MySQL data, preserving independence; zero SQL during cache operations is the demonstrated database non-interference evidence.

First run: exit 1, four tests/13 assertions with two failures. Runtime prefix change happened after Laravel had already resolved a Redis client; fixed by forcing the testing prefix in dedicated XML before application bootstrap, retaining the physical key assertion. Second failure compared creation-vs-hydration attribute order; corrected the baseline to an actual reloaded persisted snapshot, preserving strict equality. No claim was weakened. Without REDIS_TESTS_ENABLED, the filtered suite deliberately exits **2**, not skip/success. Unavailable-service behavior and final formatted rerun are recorded in the final addendum.

## 17. Regression Test Results

| Actual command | Observed result |
| --- | --- |
| git status / git branch --show-current / git merge-base --is-ancestor origin/main HEAD | Clean start, assigned branch, approved base ancestor; exit 0 |
| C validate --strict | Passed exit 0; current lockfile valid |
| C install --no-interaction --prefer-dist --no-progress | Passed exit 0 with workspace COMPOSER_CACHE_DIR and permitted network/filesystem access; nothing installed/updated/removed; package discovery/Filament assets completed |
| C check-platform-reqs | Passed exit 0; all installed package platform requirements satisfied |
| P artisan --version | Passed exit 0; Laravel 13.39.0 |
| P artisan about | Passed exit 0; PHP 8.4.27, Filament 5.11.0, Livewire 4.4.8; debug off. Existing ignored local .env retained SQLite/database cache; Redis explicitly exercised via integration config |
| P artisan route:list --path=admin | Passed exit 0; existing admin dashboard/login/logout routes present |
| C audit | Passed exit 0, no vulnerability advisories |
| P vendor/bin/pint --test | Initial Redis test formatting issue; ran Pint on that file and recheck passed exit 0 |
| php -l over app/config/database/tests PHP files | Passed exit 0, 37 files |
| npm.cmd ci | Passed exit 0, 87 packages, no vulnerabilities; upstream esbuild script-policy warning, no bypass |
| npm.cmd run build | Passed exit 0; Vite 7.3.7, 61 modules |
| docker compose -p quality-platform-phase03a -f compose.redis.yml config --quiet | Passed exit 0 |
| git diff --check | Passed exit 0 for tracked changes; staged check repeated before commit |

No existing tests changed or disabled. Static analysis/coverage/HTTP tenant authorization tests NOT RUN (not implemented/configured in scope). Existing Filament routes and frontend assets remain regression targets, not approval of production access.

## 18. CI Results

Existing MySQL 8.4 service, PHP/Laravel validation, SHA-pinned actions, all framework tests, Pint, frontend/HTTP checks and Issue #4 scope-validation block retained. Added pinned Redis service/health, redis PHP extension, disposable trigger initialization and separate MySQL/Redis test steps. MySQL integration cleanup is followed by migration for existing HTTP/session checks. No repository secrets, write token permission or deployment steps. Integration absence/errors fail the job. Remote results pending publication; no CI success claimed from configuration alone.

## 19. Security Review

RESTRICT references and composite tenant FK are tested. Ownership is not user-array fillable; no request contracts or implicit grants. ULIDs do not authorize. Existing password security retained. New rows/factories are synthetic; no production data, private SRS text or secrets committed. CI credentials are known disposable container-only fixtures. Redis port is loopback and test namespace/key cleanup precise. Production admin/tenant context/authorization/IDOR/job/file policies remain NOT IMPLEMENTED. No repository protection, contributor branch, deployment, production settings or real credentials changed.

## 20. Performance and Scalability Considerations

BIGINT internal keys keep relational indexes narrow; ULIDs are separate public IDs. Only justified unique/FK/hierarchy indexes added. Standard relationships permit eager loading; no speculative cache/repository abstractions. No hot HTTP use case/query optimization, benchmark, P95 or SLA is claimed. Future approved use cases require query-count/N+1 and load testing; structural cache setup is not evidence of business performance.

## 21. Unresolved Product-owner Decisions

DEFERRED: D-001 support access, D-002 roles/delegation, D-008 residency/retention, D-021 membership grant/switch business rules, D-022 self-review, D-023 organization suspension, D-024 invitations/reset/MFA and D-027 business relationships. D-017 and D-021 technical data shape follow accepted ADRs; business authority is not inferred. No organization/membership status field invented.

## 22. Known Limitations and Risks

Complete hierarchy cycles and safe concurrent reparenting require a future approved Action. Model public-ID guards are bypassable through low-level writes; no such external contract is added. DB integrity is not tenant read authorization. New trigger creation has documented migration privilege/binlog prerequisites and no deployment approval is implied. Named Redis volume persistence is development-only; production backup/retention/HA are not selected. No performance/legal compliance claim. Test containers affect only known task resources; cleanup status is recorded at handover.

## 23. Git Branch, Commit IDs, and Pull Request URL

Repository: https://github.com/NabilAlsaidaly/quality-excellence-platform. Base main `56e51ee`; assigned/push-only branch `feat/11-tenant-schema-foundation`. Focused Conventional Commit IDs and verified Draft PR URL will be appended after publication. No merge authorized/performed. UI contributor branch and owned files untouched. Mandatory report persistence is checked using git ls-files and PR changed-file readback.

## 24. Final Engineering Verdict

Implementation and local database/Redis verification are ready for review; delivery/remote CI still pending at this report stage. Final verdict will be updated only after actual commit/push/Draft PR/report readback and observed CI. Do not interpret this foundation as production-ready tenant authorization.

### Verification addendum

- Composer's first restricted install warned about unwritable user cache/unreachable proxy and was interrupted during autoload generation (exit 1). A permitted rerun with COMPOSER_CACHE_DIR inside ignored .local-tools completed exit 0 from the existing lockfile; dependencies unchanged. No global tool or host PHP setting changed.
- Redis required-but-unavailable negative probe: REDIS_TESTS_ENABLED=1, port 63998, filtered real_connection test exited **2** with connection error, confirming absent Redis cannot silently pass/skip. Normal real-service rerun follows in final evidence.
- Normal opt-out safety probes: both MySQL and Redis configurations returned expected exit **2** and explicit opt-in diagnostics. These deliberate negative runs are distinct from successful integration suites.
- Existing UI scope validation block was compared against origin/main and preserved verbatim. No forbidden presentation/translation/Vite/UI-test paths, composer/npm manifests/lockfiles or general phpunit.xml changed.

### Changed-file manifest

- `.env.example`
- `.github/workflows/ci.yml`
- `app/Models/Concerns/HasPublicUlid.php`
- `app/Models/Organization.php`
- `app/Models/OrganizationalUnit.php`
- `app/Models/OrganizationMembership.php`
- `app/Models/User.php`
- `compose.redis.yml`
- `config/cache.php`
- `config/database.php`
- `config/queue.php`
- `database/factories/OrganizationalUnitFactory.php`
- `database/factories/OrganizationFactory.php`
- `database/factories/OrganizationMembershipFactory.php`
- `database/migrations/2026_10_31_000001_create_organizations_table.php`
- `database/migrations/2026_10_31_000002_create_organization_memberships_table.php`
- `database/migrations/2026_10_31_000003_create_organizational_units_table.php`
- `docs/architecture/foundation.md`
- `docs/architecture/tenant-schema-foundation.md`
- `docs/engineering/reports/PHASE-03A-TENANT-SCHEMA.md`
- `docs/README.md`
- `docs/setup/local-development.md`
- `docs/setup/redis-local.md`
- `phpunit.mysql.xml`
- `phpunit.redis.xml`
- `README.md`
- `tests/Feature/TenantSchemaTest.php`
- `tests/Integration/MySql/TenantIntegrityTest.php`
- `tests/Integration/Redis/RedisInfrastructureTest.php`
- `tests/Support/MySqlTestCase.php`
