# PHASE-04A — Organizational hierarchy engineering report

Date: 2026-11-03. Issue [#20](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/20). Status vocabulary: **IMPLEMENTED / TESTED / DEFERRED / BLOCKED / PROPOSED**. This is internal technical infrastructure; no owner-approved management permission or public UI is introduced.

## 1. Executive summary

**IMPLEMENTED / TESTED:** organizational-unit create/rename/move/tree service using the existing adjacency list and verified TenantContext, iterative multi-level cycle prevention, organization-row transaction serialization/current reads, and separate durable unit-change history. All original constraints/triggers/RBAC boundaries remain. Local validation: **156 tests / 968 assertions passed**: SQLite66/394, MySQL86/557, Redis4/19. No final test failures/skips. Composer strict/platform/audit, Pint, existing Node24 build, admin routes and actual Herd/phpMyAdmin smoke passed.

The real two-process race proves that a waiting move cannot use an old REPEATABLE READ snapshot to create an opposing cycle after the first move commits. Valid competing moves can proceed. Raw SQL still bypasses multi-level cycle protection; that limitation is explicit. No merge/deployment or production authorization is approved.

## 2. Issue and branch

Issue #20: PHASE-04A — Organizational Units Hierarchy and Safe Reparenting. Branch **feat/20-organizational-unit-hierarchy**, created from fetched main; no earlier feature branch reused. [File-by-file plan](../PHASE-04A-IMPLEMENTATION-PLAN.md) written before implementation. Existing unrelated untracked generated storage file preserved and excluded.

## 3. Starting repository SHA

**2ef8caaec867f464684dda24219b6e194ba25387**. GitHub independently confirmed PR #19 merged with that SHA; fetched origin/main matched. Local main was older and was neither developed on nor reset. Current Issue #4 contributor contract and Issues #12/#13 were reviewed. Main protection is governed by the provided AGENTS/repository governance; exact Settings enforcement was not inspected or changed.

## 4. Approved requirements

SRS BR-001 isolation, FR-ID-002 / AC-ID-002 unit management, DATA-07 hierarchy cycles/same-tenant links, FR-UX-005 / AC-UX-005 meaningful change traceability; accepted ADR-001/002/003 for tenant scope, identity/authorization and identifiers/integrity. Technical operations remain internal while business management authority/panel/lifecycle policies are open. No requirement conflict required escalation.

## 5. Reviewed references

Reviewed AGENTS, CONTRIBUTING, README, architecture foundation/decision ledger, all three accepted ADRs, tenant schema/authorization architecture, Phase03A/03B-B permanent reports, Herd setup, current models/factories/migrations/tests/integration guards and unchanged CI. Relevant SRS v3.0 sections were read through connected Drive; no private text was copied into the public repository. Issue #4 UI ownership and Issues #12/#13 gates remain binding. No inaccessible technical book is claimed reviewed.

Version-specific references: [MySQL8.4 locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [consistent snapshot reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html), [Laravel13 transactions](https://laravel.com/docs/13.x/database), [query locking](https://laravel.com/docs/13.x/queries#pessimistic-locking), and installed framework transaction behavior. Existing PHP8.4.27/Laravel13.39.0/Filament5.11.0/Livewire4.4.8/Spatie8.3.2 preserved.

## 6. Scope implemented

One cohesive OrganizationalUnitManagement service exposes internal create, rename, move and tree operations, with InvalidHierarchy domain exception. Supports duplicate names, Arabic/Unicode names, roots/multiple children, valid subtree/root moves, deterministic tree data and true no-ops. Rejects malformed/missing/foreign IDs, wrong/missing/revoked actors, self/descendant/corrupt ancestry and invalid names. Audits successful changes atomically.

No routes, controllers, Filament/Livewire/Blade interface, new capability/guard/role/user, package, cache framework or hierarchy schema replacement. No delete/archive/restore/transfer/reassignment/ownership/inherited privilege/notification/domain workflow.

## 7. Existing schema findings

Existing organizational_units has BIGINT id/organization/parent, unique immutable ULID, name VARCHAR255, timestamps, parent/children/organization model relations, tenant FK/candidate/lookup keys, model direct-self-parent guard and two MySQL direct-self-parent triggers. It did not enforce arbitrary-length acyclicity. All preserved unchanged, including applied Phase03A migration and trigger recovery prerequisites.

authorization_changes is RBAC history and was not reused. The new organizational_unit_changes table is the only additive schema change. Existing candidate keys support all tenant history relationships; no extra tree/depth/order/status columns or speculative package/index framework.

## 8. Created/modified code paths

New application code: app/Actions/OrganizationalUnits/OrganizationalUnitManagement.php and InvalidHierarchy.php. New migration: database/migrations/2026_11_03_000007_create_organizational_unit_changes_table.php. No existing model, factory, authorization service/provider or public-ID mechanism was modified. Full file list is in section24.

## 9. Hierarchy algorithms

Create resolves a parent public ULID inside context, validates persisted ancestry, assigns trusted organization/parent explicitly and uses the existing model ULID mechanism. Rename updates only name; whitespace-only/invalid UTF-8 or more than255 characters rejects; duplicate names/Arabic remain supported without linguistic normalization/uniqueness rules. Move validates old/candidate ancestry, changes only the target parent, and leaves its subtree attached.

Iterative visited-set ancestor walks cost O(h_old+h_new) current queries/time and O(max(h_old,h_new)) temporary memory. Full tree uses one tenant-filtered units query ordered by id, iterative completed-path validation and indexed assembly: O(n) time/memory, no recursive subtree copying or N+1 units query. Output is ordered root ULIDs plus public-ULID keyed nodes with internal id, name, parent ULID and child references. Internal BIGINTs must be omitted by any future public serializer.

## 10. Cycle-prevention evidence

Shared tests reject direct self, two-node, three-node and40-level descendant moves; verify unchanged persisted graph and no success history. Actual raw SQL creates an isolated two-node cycle while FKs/triggers remain enabled. Full tree, child creation, rename, root transition and move into corrupt ancestry reject without repairing it.

A 121-node valid chain demonstrates iterative tree construction and one units query (at most8 total context/tree queries) on both SQLite and MySQL. No business depth/child cap is imposed. Writes inspect touched ancestry, not every unrelated legacy branch; full tree validates the entire tenant collection.

## 11. Transaction/locking design

Every operation starts a Laravel transaction and locks in consistent order: organization -> actor User -> membership -> target and parent/ancestor units. All are current FOR UPDATE reads. This serializes cooperating hierarchy writers/readers for that organization and prevents stale snapshot ancestry decisions after waiting. Current locked membership is revalidated even if TenantContext's ordinary snapshot verification still sees a revoked row.

No automatic deadlock retry or external side effect. Errors propagate after rollback; callers must roll back an encompassing transaction on deadlock and preserve lock order. Tree currently uses the same exclusive protocol for simple consistency, at a documented throughput cost. User-row locks may serialize the same actor across organizations. No claim of universal deadlock freedom or unlimited-size performance.

## 12. MySQL concurrency evidence

**TESTED / PASS:** five dedicated scenarios using actual MySQL8.4 REPEATABLE READ:

| Scenario | Observed evidence |
| --- | --- |
| Opposing sibling-root moves | First action changes A.parent=B inside an uncommitted outer transaction; second PHP process/connection sees old root snapshot and requests B.parent=A. performance_schema confirms waiting specifically on the organization row. After first commit, second rejects cycle; final durable A->B, B root, only first success event |
| Valid competing move | Same real wait/snapshot setup, then B.parent=C succeeds after first A->B commit; final subtree/tree and two success events verified |
| Actual timeout1205 | Separate connection blocks at common organization lock, times out with no partial unit/history write, then fresh attempt succeeds after release |
| Server1213 error path | Actual SQL SIGNAL fault injected after unit update/before audit; exception propagates, no retry, graph/history rolled back. **Not a naturally occurring InnoDB deadlock test** |
| Revocation with old snapshot | Second connection deletes membership after first transaction snapshot; ordinary context verification still sees it, but service current membership read denies and writes nothing |

The race uses distinct CONNECTION_ID values, bounded subprocess/wait cleanup and actual transactions. Worker repeats opt-in/APP_ENV/config-cache/actual disposable-database/MySQL8.4/isolation guards before work; credentials stay in child environment, not arguments/output. performance_schema access uses the existing privileged disposable test operator, with no grants/global changes. Natural InnoDB victim selection and SQLite row-lock equivalence are **NOT RUN / not claimed**; real lock wait/timeout and explicit server-error rollback are proven.

## 13. Tenant isolation evidence

Every unit/ancestor lookup is tenant-scoped; public ULIDs do not confer access. Foreign/missing/malformed identifiers share a generic not-found response. Existing composite parent FK/direct trigger tests still enforce raw database constraints. History unit/old-parent/new-parent FKs include organization_id, and raw cross-tenant inserts/updates fail. Actor attribution comes from trusted context, never request mass assignment.

Missing/guest/wrong actor, revoked membership, forged organization and cross-tenant target/parent scenarios deny before mutation. No institution's unit name or existence is exposed through error messages. Read model excludes foreign nodes.

## 14. Authorization and inaccessible UI boundaries

The service is trusted internal infrastructure only. Structural membership does not authorize management/read administration. No application-user call site invokes it; future caller must enforce approved capability/resource Policy before use. Existing TenantAuthorization/CapabilityRegistry/Teams are preserved; no speculative management capability is registered.

canAccessPanel remains local/testing-only admin, fail-closed in production/staging. Existing Filament negative tests and three admin routes pass. No Filament Resource exists because management authority and UI integration are not approved; the separate Issue #4 contributor branch/files remain untouched. No approval/review privilege follows hierarchy position.

## 15. Audit implementation/status

**IMPLEMENTED / TESTED:** separate organizational_unit_changes append-only service path: organization, trusted actor, unit, created/renamed/reparented event, before/after parents, relevant before/after names and timestamp. No arbitrary request metadata, email/password/profile/document snapshot. No-op/rejected operations write no success event. Unit mutation/history insertion commit or roll back together; audit insertion failure reverses create/rename/move.

Additive single CREATE has inline CHECK/restrictive FKs; actual keys/types, cross-tenant rejection, referenced actor/org/unit/former-parent deletion restriction, rollback/repeat, missing-reference atomic failure/retry and existing-table preservation all tested. No cascade. Historical parents may remain deletion-restricted after a move; deletion/archive policy is deferred.

No audit read/edit/purge interface or retention policy. Runtime DML/operator can still tamper with raw rows: application append-only behavior is not tamper-proof storage. Stronger audit access/retention/residency controls remain owner gates.

## 16. SQLite/general test results

**PASS:** php artisan test on actual SQLite :memory:, array cache/sessions, sync queue. **66 tests / 394 assertions**, comprising the original44/207 plus22 shared hierarchy scenarios/187 assertions. Existing driver isolation and Filament/RBAC tests remain. SQLite tests validate algorithms/constraints/transactions, not MySQL row-lock semantics.

Initial new-test run had8 failures because strict attribute-array snapshots compared factory insertion key order with reloaded DB column order. Fixed the fixture snapshots to read persisted rows first; strict unchanged-value assertions remain. No application behavior was weakened to pass. New-file Pint findings were fixed. Final review strengthened no-op checks by advancing the clock one minute and strictly comparing both parent/child persisted timestamps and attributes, preventing same-second false passes; this adds one assertion per database suite without removing any check.

## 17. MySQL integration results

**PASS:** complete final suite **86 tests / 557 assertions**, about123 seconds locally, MySQL8.4.13 in a dedicated disposable container on loopback33068, quality_platform_ci, explicit opt-in and existing safety guards. Includes all53 original cases,22 shared hierarchy scenarios,5 concurrency/revocation cases and6 history/migration cases. No final failures/skips.

Destructive fresh/rollback/rename/corrupt fixtures operate only in this disposable schema. Existing native3306 and persistent application13306 databases never receive destructive test fixtures. No global binlog/trust change.

## 18. Redis regression results

**PASS:** real Redis8.2.11 integration, **4 tests / 19 assertions**, isolated UUID namespace/targeted cleanup. Existing cache/queue/after_commit behavior preserved; no broad flush or new hierarchy/permission cache. Total across all suites: **156 tests / 970 assertions**.

## 19. Existing test regressions

All original SQLite44, MySQL53 and Redis4 tests remain and pass, including actual1419 trigger failure/recovery, second-trigger failure, pre-existing rows, competing writer and unsafe/DROP cleanup failures; tenant FKs/self-parent/deletion restrictions; RBAC context/global-role/cache/assignment/production panel negatives.

Minimal existing test adaptations only: migration count12->13; permission rollback spans4->5 and6->7 to include later history; trigger rollback path includes dependent history before units. Every original rollback-success and safety assertion retained. No test safety opt-in, configuration, assertion or CI check removed.

## 20. Pint/Composer/build results

| Required check | Actual result |
| --- | --- |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS |
| composer audit | PASS, no known advisories at check time |
| artisan about | PASS; existing stack/drivers retained |
| artisan migrate:status | PASS;13 applied locally after separate-operator additive migration |
| artisan test | PASS,66/394 |
| Complete MySQL suite | PASS,86/557 |
| Redis suite | PASS,4/19 |
| Pint --test | PASS |
| npm run build | PASS, existing Node24.22.2/Vite7.3.7,61 modules |
| route:list --path=admin | PASS, existing dashboard/login/logout only |
| git diff --check | PASS |

No dependencies/lockfiles upgraded or reinstalled. Existing portable Node24 and workspace PHP temp/cache overrides reused; no global configuration or user Vite process changed. HTTP /up and /admin/login on Herd and independent phpMyAdmin.test each returned200. No visual UI test is claimed or required for this backend scope.

## 21. Security review

No cross-tenant parenting/history reference, forged context/ULID grant, hierarchy privilege inheritance, public management endpoint, default business role/capability/user, super-admin bypass, production-panel enablement or contributor UI change. Unit-public identity and all original schema/triggers preserved. New history has restrictive tenant relationships and no delete cascade.

The separate local migration operator verified actual target13306/MySQL8.4.13, exactly one pending additive migration and12 existing journal entries. After application:13 entries, new history0 rows; original tenant/RBAC table DDL/counts and both trigger definitions/definers preserved; runtime SELECT/INSERT/UPDATE/DELETE only; private .env/service-credential hashes unchanged; binlog1/trust0 unchanged. No credentials/APP_KEY regeneration, volume replacement, native MySQL or phpMyAdmin configuration change. Private helper/logs remain ignored.

## 22. Known limitations

Raw SQL/model save/bulk/unrelated writers can bypass application multi-level checks, lock protocol and history. The DB enforces same-tenant/direct-self-parent guarantees, not arbitrary acyclicity. No silent corrupt-graph repair; private operator-controlled review/backup/approved correction and full integrity validation required, as documented in architecture.

Full tree materializes O(n) rows and holds exclusive locks, so real memory/latency/throughput budgets require workload testing. Cross-service/noncompliant/nested multi-organization lock orders can deadlock; no automatic retry or natural-victim-selection proof. Histories are not tamper-proof. Future public serializers must omit internal IDs.

Single-CREATE SQL failures are atomic, but process death between successful MySQL DDL and journal insertion requires operator inspection; never auto-adopt/drop unexpected tables. Rollback drops history/loses rows and is not a production recovery strategy. Original trigger privileges/provider/definer prerequisites remain. No production deployment performed.

## 23. Deferred owner-dependent features

Unit delete/archive/restore/transfers, user/Approach ownership reassignment, auto-inherited permissions, drag/drop/order/types/levels, notification/lifecycle flows, public management APIs/Resources/panels and audit read/retention/purge policy. D-001/002/008/021/022/023/024/027/010/019 remain unchanged. Issue #13 still blocks production authorization/rollout. No implementation blocker within the approved internal scope.

## 24. Files changed

- app/Actions/OrganizationalUnits/OrganizationalUnitManagement.php
- app/Actions/OrganizationalUnits/InvalidHierarchy.php
- database/migrations/2026_11_03_000007_create_organizational_unit_changes_table.php
- tests/Support/OrganizationalHierarchyScenarios.php
- tests/Support/hierarchy-worker.php
- tests/Feature/OrganizationalHierarchyTest.php
- tests/Integration/MySql/OrganizationalHierarchyTest.php
- tests/Integration/MySql/HierarchyConcurrencyTest.php
- tests/Integration/MySql/HierarchyHistoryIntegrityTest.php
- tests/Integration/MySql/PermissionIntegrityTest.php
- tests/Integration/MySql/TenantIntegrityTest.php
- tests/Integration/MySql/TriggerMigrationSafetyTest.php
- README.md
- docs/README.md
- docs/architecture/foundation.md
- docs/architecture/organizational-hierarchy-management.md
- docs/setup/herd-windows.md
- docs/engineering/PHASE-04A-IMPLEMENTATION-PLAN.md
- docs/engineering/reports/20.md
- docs/engineering/reports/PHASE-04A-ORGANIZATIONAL-HIERARCHY.md

No old migration/model/factory/RBAC/route/controller/Filament/Blade/Vite/localization/package/CI/decision-ledger change. One pre-existing untracked storage artifact is excluded. No private SRS excerpt, real tenant records, credentials, personal home path or secret included.

## 25. Git commits

Baseline2ef8caaec867f464684dda24219b6e194ba25387; branch feat/20-organizational-unit-hierarchy. Implementation: **4922acb7f58981bcbb18f56855fc212ddce99edf**, feat: add safe organizational hierarchy management. The follow-up strengthens the timestamp no-op test and persists CI/PR evidence; its SHA is available in PR history rather than a fabricated self-reference.

## 26. Draft Pull Request

[Draft PR #21](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/21) targets main. Remote changed-file readback confirms this mandatory report and architecture among the20 reviewed paths; contributor UI/old schema/RBAC/CI files are unchanged. PR remains open, draft and unmerged. No merge/deployment authorized.

## 27. CI result and URL

**PASS:** unchanged [Foundation CI41655747379](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41655747379), job125985442808, on implementation4922acb7f58981bcbb18f56855fc212ddce99edf. All applicable dependency/platform/audit, migration rollback/repeat, SQLite66/394, MySQL86/557, Redis4/19, Pint, clean Linux npm ci/build and cached-runtime HTTP steps succeeded. Logs confirm the initial156/968 totals. The Issue #4 branch-specific scope guard is correctly inapplicable, not a skipped security suite. A subsequent review strengthens no-op timestamp checks beyond one-second precision; final local counts are recorded above, and the follow-up's final-commit CI is checked independently in PR #21 before handover. CI configuration/permissions unchanged.

## 28. Final engineering verdict

**IMPLEMENTED / TESTED locally and in CI:** the authorized internal hierarchy foundation, transactional change history and real MySQL synchronization/current-read cycle protection. **DEFERRED:** all user-facing management/deletion/archive/business privileges and owner-dependent rollout. **BLOCKED for production:** existing owner decisions/Issue #13. Draft PR #21 contains the committed permanent report, architecture and actual test/CI evidence. Ready for engineering-lead review after final-head CI verification; do not self-merge or deploy.
