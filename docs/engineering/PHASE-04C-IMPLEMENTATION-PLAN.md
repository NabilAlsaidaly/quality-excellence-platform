# Phase 04C implementation plan — Issue #24

Written before implementation; this document is committed separately first. Authority: the owner-supplied Phase04C contract, [Issue #24](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/24), accepted ADR-001/002/003 and the unchanged decision ledger. This phase changes tests and documentation only.

## Verified starting evidence

- Freshly fetched origin/main: `5b16aaaf529b8492a45efc1c99bf0400549c2f77`. GitHub confirms Phase04B PR #23 merged and Issue #22 closed. Branch: `test/24-tenant-isolation-contract`.
- Preserved the pre-existing untracked storage artifact. No tracked local changes. Contributor branch and application services remain untouched.
- Independently reran the unchanged baseline: SQLite **94 tests / 744 assertions**, actual MySQL8.4.13 **126 / 1005**, real Redis8.2.11 **4 / 19**; total **224 / 1768**, all passed. MySQL took218.572 seconds. This is observed evidence, not copied historical counts.
- After baseline teardown, invoked ordinary migrate behind the existing MySqlTestCase guard in the task-only disposable quality_platform_ci container. Introspected **14 migrations, 20 InnoDB base tables, 112 columns, 21 restrictive foreign keys, 49 indexes and 2 enforced event CHECKs**. No application database migration. binlog=1, trust_function_creators=0, foreign_key_checks=1.
- Read the actual migrations, models/actions/authorization, configurations, integration guards, current architecture/reports/setup, existing tests and CI discovery. SRS v3.0 was readable through connected Drive; relevant BR-001/002, DATA-01..10 and AC-GATE-003/T-DB-001 were verified without publishing private excerpts. Issues #4/#6/#12/#13 retain their gates.

## Gap matrix and exact additions

| Requirement / risk | Existing evidence | Gap and planned test |
| --- | --- | --- |
| DATA-01, AC-GATE-003 / T-DB-001 | Architecture allowlists describe framework exceptions; existing tests address named tables | No complete executable table inventory. New contract must compare actual base-table names with one reviewed classification map; unknown and removed/renamed tables fail clearly. |
| DATA-01/02, ADR-001/002 | TenantIntegrityTest and PermissionIntegrityTest prove selected FKs/DML failures | No complete structural check of all8 directly scoped tables or the indirect pivot. Check required non-null unsigned organization_id, actual ordered FK targets/schema and restrictive update/delete rules. No blanket organization FK for assignment pivots or membership FK for global audit actors. |
| DATA-08/09, ADR-003 | Existing candidate/public-ID/role uniqueness and history deletion tests | Complete required-key check closes untested direct-permission, pivot and historical-reference metadata gaps, including exact unique column tuples and all21 actual FK rules. Do not repeat already proven DML workflows. |
| DATA-09/10, history CHECKs | HierarchyHistoryIntegrityTest and OrganizationHistoryIntegrityTest reject unsupported event values | Check actual CHECK presence, ENFORCED and bounded event expressions, complementing existing DML tests. |
| Gate effectiveness | No prior classifier exists | Against real migrated metadata, mutate copies to remove/rename a table, remove/weaken a scope column, FK, unique key or CHECK. Verify specific diagnostics. Create one distinctly named unknown base table and remove one real indirect-pivot FK only in guarded disposable MySQL; finally restore/drop exact fixtures and prove the gate passes again. |
| Legitimate exceptions | Global identity/root/provisioning and permission schemas already implemented | Current-schema success explicitly covers users, organizations, permissions and role_has_permissions without organization_id; names never auto-classify unknown tables. |
| Identity and direct-self-parent/1419 recovery | TenantSchemaTest::test_public_identifier_is_immutable_after_creation; TenantIntegrityTest identifier/duplicate/composite/self-parent tests; all TriggerMigrationSafetyTest cases | Already covered: preserve unchanged, no duplicate tests or trigger-body snapshot. |
| Scoped deny-by-default / no grants | TenantAuthorizationScenarios absent/forged/member-only/wrong-actor/cross-tenant/stale/revoked/cache cases; FilamentAccessTest production/staging403 | Already covered: preserve unchanged; schema gate is not authorization or a new route. |
| Provisioning and metadata | OrganizationManagementScenarios::test_provisioning_needs_no_membership_and_grants_no_context_or_content_access; test_member_metadata_is_minimal_and_does_not_query_private_relationships; foreign/revoked/no-route cases | Already covered: no repeat tests, no global actor membership requirement. |
| Hierarchy / concurrency / transactional history | OrganizationalHierarchyScenarios; HierarchyConcurrencyTest; OrganizationConcurrencyTest; both history integrity suites | Already covered: multi-level cycles are an application guarantee, not an FK guarantee. Preserve all existing tests. |

## Classification decisions

One executable map in tests/Support/TenantIsolationSchemaContract.php is authoritative for the gate. Architecture will explain this same observed inventory, not supply a second executable allowlist:

- Global infrastructure/identity: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, migrations.
- Global capability catalog: permissions (definitions, not grants).
- Root: organizations (defines the boundary).
- Direct: organization_memberships, organizational_units, roles, model_has_roles, model_has_permissions, authorization_changes, organizational_unit_changes, organization_changes.
- Indirect: role_has_permissions through role_id -> roles.id, with restrictive permission FK and unique permission/role pair.

Actual assignment pivots link organization through membership and/or scoped roles; do not invent an additional direct FK. authorization_changes.role_id is intentionally a historical ID snapshot without a role FK. Historical actor/subject references target global users. sessions.user_id is an indexed optional framework association without an FK. These existing limitations must be explicit.

## Planned paths and design

1. tests/Support/TenantIsolationSchemaContract.php: small static classification/critical-constraint inventory, read-only MySQL metadata collection and deterministic violation messages. No generic schema DSL or production dependency. Compare key meaning, not arbitrary FK/index names; fixed CHECK expressions are narrowly normalized for MySQL formatting.
2. tests/Integration/MySql/TenantIsolationSchemaContractTest.php: current-schema acceptance, unknown actual base table, missing actual indirect FK and mutation-of-metadata negative proofs. Inherit MySqlTestCase and DatabaseMigrations. No optional SQLite helper file is needed: pure mutation assertions use the real MySQL baseline instead of a duplicated schema fixture.
3. docs/architecture/tenant-isolation-schema-contract.md: observed inventory/rationale, guarantees, existing-test references, extension/review procedure and limitations.
4. docs/engineering/reports/PHASE-04C-TENANT-ISOLATION.md and reports/24.md: actual findings/attempts/final verification/delivery evidence.
5. Minimal links in docs/README.md and docs/architecture/foundation.md.

No existing test needs modification. No changes in app/database/config/CI/UI/routes/bootstrap, dependency manifests/locks, phpunit configurations or owner decisions. No packages, product feature, permanent schema or deployment.

## Safety and verification

All destructive fixture operations run only after existing opt-in/testing/actual MySQL/exact quality_platform_ci/no DB_URL/no cached-config guards. Use the task-created loopback33068 container; native3306 and application13306 remain separate. Retain original runtime credentials, private files, Redis namespaced cleanup and unknown processes. No new foreign-key disabling, global settings, blanket cleanup or developer DB rollback. Fixture CREATE succeeds before entering its owned cleanup; FK removal has exact finally restoration. No tenant rows/credentials in diagnostics.

After the last test/helper edit, run full SQLite, full guarded MySQL and real namespaced Redis suites; Composer strict/platform/audit; Pint; existing Node24 build; about/admin routes/read-only migrate:status; diff/allowed-path review. Existing CI discovers the new MySQL test without edits. Commit permanent report, push only this branch, open Draft PR, verify actual final-HEAD CI and stop for lead review. Never merge or deploy.

## Boundaries and findings requiring separate scope

Execution refinement within the same allowed paths: final review added a fifth focused MySQL fixture to prove CHECK formatting preserves literal contents and that actual NOT ENFORCED metadata fails the gate. The exact original CHECK is restored in finally; full regression is rerun after this test/helper change. The original plan commit still precedes all implementation.

The gate detects reviewed structural regressions; it cannot infer ownership of new columns or authorize SQL, services, queues, files or downloads. Global exceptions are not permission to store unscoped private tenant content. No claim of DB-wide multi-level acyclicity, raw SQL ULID immutability, tamper-proof audit, production permissions or migration crash recovery. DATA-04/05/06 domain features and open owner policies stay deferred. Any newly reproduced application/schema defect requiring forbidden changes must become a separate proposed remediation, with compatibility impact, rather than an in-scope fix.
