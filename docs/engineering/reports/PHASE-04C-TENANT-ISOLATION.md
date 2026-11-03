# PHASE-04C — Tenant isolation schema contract engineering report

Date: 2026-11-03. Issue [#24](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/24). Branch: `test/24-tenant-isolation-contract`. Scope: test/documentation hardening only. Status terms: **IMPLEMENTED / TESTED / DEFERRED / BLOCKED / NOT VERIFIED**.

## 1. Outcome and scope

**IMPLEMENTED:** one explicit schema classification contract and five focused MySQL integration tests. Unknown base tables fail pending reviewed classification; removed/renamed approved tables and missing/weakened critical scope columns, foreign keys, unique tuples or event CHECKs also fail. The gate reads real MySQL metadata and uses existing test/CI discovery.

**TESTED:** actual migrated-schema classification, controlled unknown-table/FK/CHECK mutations and synthetic changes to copies of real metadata, with exact restoration. Existing application/authorization/provisioning/hierarchy behavior and all pre-existing tests are unchanged. No permanent schema, migration, UI, configuration, permission, dependency or deployment change.

Final local regression after the last helper/test change: **229 tests /1817 assertions passed**, comprising SQLite94/744, MySQL131/1054 and Redis4/19. All required local checks passed; remote delivery evidence is recorded below after observation.

## 2. Starting state and references

Freshly fetched origin/main and starting SHA: **5b16aaaf529b8492a45efc1c99bf0400549c2f77**. GitHub confirmed Phase04B PR #23 merged and Issue #22 closed. Searched for an existing Phase04C Issue before creating #24; no duplicate found. Created this branch directly from origin/main. Preserved a pre-existing untracked storage artifact and did not alter the UI contributor branch.

Reviewed AGENTS.md, CONTRIBUTING.md, README, unchanged CI and phpunit configurations; accepted ADR-001/002/003 and ledger; current schema/authorization/hierarchy/organization architecture; Phase03A/03B-B/04A/04B reports; Herd/Redis setup;14 migrations; relevant models/actions/authorization/configuration; existing feature/MySQL/Redis/support tests and safety guards. Issues #4/#6/#12/#13 remain open and their scope/owner gates were reviewed; #22 is completed.

Owner SRS v3.0 was accessible through connected Google Drive (source modification2026-10-31). BR-001/002, DATA-01..10, AC-GATE-003 / T-DB-001 and applicable cross-tenant boundaries were checked. No private excerpts, credentials, personal home paths, tenant data or screenshots are published. Official MySQL8.4 metadata references and installed Laravel13 introspection implementation were reviewed; links are in the architecture document.

## 3. Requirements and pre-implementation audit

The full concise gap matrix was committed **before code** in [PHASE-04C-IMPLEMENTATION-PLAN.md](../PHASE-04C-IMPLEMENTATION-PLAN.md), commit **103c7a5fe69c262bcbc6f815d3510b0268fbd61b**.

| Requirement / acceptance | Evidence and actual gap |
| --- | --- |
| DATA-01, ADR-001, AC-GATE-003 / T-DB-001; A1/A2/A3 | Existing tests named known tables but did not enumerate unknown future tables. New complete explicit classification and fail-closed inventory comparison closes this gap. |
| DATA-02/03/08, ADR-002/003; A2/A4 | Existing FK/DML cases cover major tenant boundaries. New metadata contract complements them by checking all21 required relationships, their ordered target/same schema/restrictive actions, direct scope columns and19 critical unique tuples. |
| DATA-09/10; A3/A4 | Existing history/migration tests remain. Actual lost FK, changed/disabled CHECK and metadata mutations prove the new gate fails; finally restoration is checked against full normalized metadata. |
| BR-001/002; A5 | Root/global identity/catalog exceptions preserve tenant isolation without requiring initial actor membership or granting platform content authority. No application/schema behavior changes. |
| DATA-07, identifier immutability, auth/context/provisioning/metadata/routes | Already covered by unchanged service, integrity, concurrency and Filament tests; no duplicate workflows or fake endpoints added. |
| A6/A7/A8 | Complete final suites/checks, permanent report/architecture, scoped branch/Draft PR/final-HEAD CI and lead-controlled merge gate. |
| DATA-04/05/06 and unresolved owner policies | DEFERRED domain requirements; no Approach/DocumentVersion/review/retention/lifecycle implementation or policy inference. |

No reproduced application/schema security defect requiring a forbidden production change was found in this audit. Existing limitations below are not silently upgraded into guarantees.

## 4. Actual schema inventory and exceptions

After the independent baseline suite, ordinary migrate behind the existing MySqlTestCase guard populated only task-created disposable quality_platform_ci on real MySQL **8.4.13**. Actual introspection: **14 applied migrations, 20 InnoDB base tables, 112 columns, 21 FKs, 49 indexes, 2 enforced CHECKs**. All21 FKs were same-schema RESTRICT/RESTRICT. Read-only settings: binlog1, trust_function_creators0, foreign_key_checks1.

| Classification | Complete observed members |
| --- | --- |
| Global infrastructure/identity (9) | users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, migrations |
| Global capability catalog (1) | permissions |
| Tenant root (1) | organizations |
| Direct tenant scope (8) | organization_memberships, organizational_units, roles, model_has_roles, model_has_permissions, authorization_changes, organizational_unit_changes, organization_changes |
| Indirect tenant pivot (1) | role_has_permissions |

[The architecture inventory](../../architecture/tenant-isolation-schema-contract.md) explains every table and the matching approved mechanism. `TenantIsolationSchemaContract::TABLES` is the single executable classification source; no second fixture allowlist is generated or inferred from live metadata.

users is global identity, permissions contains definitions, organizations defines the boundary, and role_has_permissions reaches it through its restrictive role FK and unique role/permission pair. None needs an invented organization_id. Initial organization history uses a global actor FK without membership. Assignment pivots use their approved composite role/membership links rather than fabricated direct root FKs. authorization_changes.role_id is an intentional historical snapshot, not a live role FK. sessions.user_id remains the optional indexed framework association without an FK. Global exceptions do not authorize arbitrary private tenant payloads.

## 5. Implementation and coverage

New tests/Support/TenantIsolationSchemaContract.php performs five read-only information_schema queries: actual base tables, column type/nullability, ordered FKs joined to restrictive actions, actual unique/index columns including full-column coverage, and CHECK clause/enforcement. It emits deterministic table/constraint diagnostics without reading row contents. It compares declared critical relationships with actual constraints, not migration strings/comments or a snapshot generated from the same current database.

Every direct organization_id is required/non-null/unsigned BIGINT; intended FK columns retain required type/nullability. All21 required FK shapes and19 critical unique tuples are validated. The two event CHECKs must be present/enforced with their reviewed clauses and non-null event columns. Formatting normalization preserves string-literal contents. Historical FKs additionally reject destructive actions even when newly added. InnoDB is required for the reviewed current tables. Global/root/indirect exceptions pass without a blanket tenant-column rule.

The new integration file adds five cases:

1. Real migrated schema passes, including explicit users/organizations/permissions/indirect-pivot exceptions.
2. A real unclassified tenant-looking table containing organization_id fails; an infrastructure-looking unknown metadata name also fails. Exact owned-table cleanup restores the prior passing schema.
3. A real dropped role_has_permissions -> roles FK fails, then finally restoration returns all normalized metadata to the prior passing state.
4. Twenty-one focused metadata mutations plus a renamed-table case reject missing/nullable/signed scope or reference columns, non-InnoDB, wrong FK target/order/schema/actions, missing/nonunique/partial keys, missing/disabled/changed CHECKs, nullable event and added historical cascade. Live metadata remains unchanged.
5. Actual changed CHECK literal contents (including embedded whitespace/charset text) and correct-but-NOT-ENFORCED CHECK each fail. Exact finally restoration returns the prior passing schema.

No existing test/configuration/guard was modified, and no new skipped case, fake route or grant was added. The existing CI automatically discovers this MySQL test.

## 6. Existing regression evidence retained

- TenantSchemaTest/TenantIntegrityTest: unsigned identifiers, public ULID uniqueness/model immutability, membership uniqueness, real composite parent/candidate keys, orphan/cross-tenant/self-parent rejection and restrictive references.
- TriggerMigrationSafetyTest: actual1419, partial trigger installation, prior data preservation, locked cleanup, competing writer and failure propagation.
- PermissionIntegrityTest and shared TenantAuthorizationScenarios: scoped roles/assignments, membership/global-role rejection, deny-by-default actor/resource/context, stale/revoked/cache behavior, internal management and transactional authorization history.
- OrganizationalHierarchyScenarios/HierarchyConcurrencyTest/HierarchyHistoryIntegrityTest: service cycles/tree/mutations, current concurrency/revocation and restrictive unit history.
- OrganizationManagementScenarios/OrganizationConcurrencyTest/OrganizationHistoryIntegrityTest: membership-free internal provisioning without grants, minimal current-tenant metadata, wrong/foreign/stale actors, actual concurrent names/no-op/atomic audit and restrictive global-actor history.
- FilamentAccessTest/service route tests: production/staging403 and absent user-facing tenant-management endpoints.
- IsolationConfigurationTest and RedisInfrastructureTest: true isolated SQLite and actual UUID-namespaced Redis cache/queue behavior.

Specific test references and reasons not to duplicate them are in the plan/architecture. Schema classification is not application authorization.

## 7. Independent baseline and attempted runs

| Run | Actual result |
| --- | --- |
| Baseline SQLite | PASS94 tests /744 assertions |
| Baseline disposable MySQL | PASS126 /1005,218.572 seconds |
| Baseline real Redis | PASS4 /19 |
| Baseline total | PASS224 /1768 |
| Initial focused gate | PASS4 /36 |
| Intermediate full MySQL after added metadata mutations | PASS130 /1047,245.982 seconds |
| Final focused gate after CHECK literal-preservation refinement | PASS5 /49,8.460 seconds |

Before the MySQL baseline, a disposable CLI setup attempt using the short unquoted host argument failed with connection error2003 (host parsed as127). Retried using explicit quoted --host=127.0.0.1, verified8.4.13 and created only the existing restricted test-account fixture in the disposable container; then the full baseline passed. This was a setup failure before PHPUnit, not a failed or skipped test. No database setting was weakened.

Final review refined CHECK formatting to preserve literal contents and added its actual-MySQL proof; all required final checks were rerun after that last helper/test change. No failing product assertion was removed or relaxed.

## 8. Final validation after the last helper/test change

`P` below means php with the existing workspace temporary-directory override (`-d sys_temp_dir=.local-tools/tmp`). Integration variables are scoped to separate processes. Existing phpunit configs force testing/isolated drivers; the MySQL base checks opt-in, driver, configured and actual exact disposable schema, no DB_URL and no cached config before destructive migration hooks.

| Actual command/check | Result |
| --- | --- |
| P artisan test --no-ansi | PASS94 tests /744 assertions, actual SQLite :memory:/array/sync |
| MYSQL_TESTS_ENABLED=1 P vendor/bin/phpunit --configuration phpunit.mysql.xml --no-progress | PASS131 tests /1054 assertions, actual MySQL8.4.13,204.990 seconds; all126 original cases plus5 new cases |
| REDIS_TESTS_ENABLED=1 P vendor/bin/phpunit --configuration phpunit.redis.xml --no-progress | PASS4 /19, actual Redis8.2.11 and UUID cleanup |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS |
| composer audit | PASS, no known advisories at check time |
| P vendor/bin/pint --test | PASS |
| Existing Node24.22.2 npm run build | PASS, Vite7.3.7,61 modules |
| P artisan about | PASS, existing PHP8.4.27/Laravel13.39.0/Filament5.11/Livewire4.4.8/Spatie8.3.2, local/debug-off |
| P artisan migrate:status | PASS,14 already applied on existing application DB; read-only, no application migration |
| P artisan route:list --path=admin | PASS, existing dashboard/login/logout only |
| git diff --check / exact path review | PASS during review; repeated before commit |

Final total: **229 tests /1817 assertions**, no failures/skips. Dedicated --list-tests independently confirms all five new cases are discovered by the unchanged MySQL configuration. General SQLite94/744 and Redis4/19 counts remain unchanged; the new gate contributes5/49 to MySQL. No new test was added merely to inflate a numeric target.

## 9. Environment preservation and data safety

Only task-created quality-phase4c-ci-mysql, MySQL8.4.13 on loopback33068 with exact quality_platform_ci, receives destructive tests. Existing application MySQL13306/native3306/phpMyAdmin/Herd/private configurations/runtime grants/volumes remain untouched. Application migrate:status is read-only and shows all14 original migrations applied. No application row was read for inventory or used as a fixture; no local authenticated HTTP/data probe was needed.

The task container uses existing public disposable CI fixture credentials/account shapes, not application secrets. There is no global SET, new FOREIGN_KEY_CHECKS disabling, trigger-definer edit, production dump or broad fixture deletion. All new live mutations are exact guarded table/FK/CHECK fixtures with finally restoration. Existing migration/recovery tests and their safeguards remain unchanged.

Private .env and service-credential file hashes match before/after without printing their contents. APP_KEY and runtime credentials were not regenerated/read into reports. Existing application MySQL and Redis containers remain healthy with the same ports; native services/user processes/contributor branch were not modified. Redis uses existing explicit opt-in/UUID namespace and individual-key cleanup, never broad FLUSH. Existing Node24/modules were reused, with no installs/upgrades or unknown-process termination.

After the final full suite, read-only task-server verification confirmed MySQL8.4.13 / quality_platform_ci, binlog1/trust0/foreign_key_checks1 and zero owned synthetic table. Stopped only quality-phase4c-ci-mysql; its --rm lifecycle removed it. Existing development MySQL13306 and Redis6379 remain running/healthy. No application-volume deletion or native-service operation.

## 10. Security impact, limitations and deferred work

The new gate closes the unclassified-table regression gap and tests critical metadata failures. It neither grants authority nor changes tenant handling. Internal provisioning still needs only a persisted actor for attribution and creates no membership/defaults/context switch; user-facing administration still requires owner-approved Policies. Metadata remains minimal/current-tenant; production Filament remains fail-closed.

This is not production drift monitoring or a complete schema snapshot. New columns/private payloads on known global tables, views, session-temporary tables, extra noncritical indexes/constraints, raw SQL, DB grants/definers and actual historical-data upgrade paths require review. Supported polymorphic model_type/guard and public-ID immutability remain application boundaries. Multi-level cycles are service guarantees, not DB-wide FK guarantees; raw writes can bypass audit/ULID/cycle behavior. Historical storage is not tamper-proof. Existing DDL/journal crash and trigger deployment prerequisites remain unchanged.

No business role matrix, organization lifecycle/status, membership provisioning, data retention engine, document/version/approach workflow, queue/file/download API, privileged seed or UI is authorized. FR-ID-001 stays partial per04B. Owner decisions and Issues #4/#6/#12/#13 retain their status. Separate remediation scope is required for any future finding that needs forbidden production paths; none was implemented here.

## 11. Exact changed paths

1. docs/engineering/PHASE-04C-IMPLEMENTATION-PLAN.md — committed before implementation; gap matrix and execution refinement.
2. tests/Support/TenantIsolationSchemaContract.php — single classification source/metadata checker.
3. tests/Integration/MySql/TenantIsolationSchemaContractTest.php — five new guarded gate tests.
4. docs/architecture/tenant-isolation-schema-contract.md — full inventory, exceptions, coverage, limits and safe extension procedure.
5. docs/engineering/reports/PHASE-04C-TENANT-ISOLATION.md — this permanent report.
6. docs/engineering/reports/24.md — Issue report index.
7. docs/README.md — two navigation links.
8. docs/architecture/foundation.md — one gate status paragraph/link.

No app/database/bootstrap/routes/resources/lang/config/CI/dependency/phpunit/contributor paths changed. Unrelated untracked storage remains excluded. Ignored local logs/metadata inventory are not part of the PR.

## 12. Commits, Draft PR and CI

Starting main: **5b16aaaf529b8492a45efc1c99bf0400549c2f77**. Plan-before-code commit: **103c7a5fe69c262bcbc6f815d3510b0268fbd61b**. Implementation/final code SHA and Draft PR URL will be recorded after publication. Final report-containing HEAD and actual CI must be verified in the PR before handover; no fabricated self-referential commit hash.

Remote CI: **NOT VERIFIED** before publication. Existing Foundation CI must run unchanged with SQLite, MySQL, Redis, dependency/audit, Pint, Node and cached HTTP stages. The Issue #4 branch-specific path guard is inapplicable to this branch, not a skipped security suite.

## 13. Engineering recommendation

**IMPLEMENTED / TESTED locally:** the test/documentation contract and all required local regression checks pass within scope. Complete remote final-HEAD CI/publication verification before handover, retain a Draft PR, then stop for engineering-lead review. No merge, auto-merge, deployment or owner-policy approval is authorized by this phase.
