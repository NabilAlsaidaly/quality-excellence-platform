# PHASE-04B — Organization management engineering report

Date: 2026-11-03. Issue [#22](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/22). Status vocabulary: **IMPLEMENTED / TESTED / DEFERRED / BLOCKED / PROPOSED**. Technical provisioning is not an approved business administrator.

## 1. Executive summary

**IMPLEMENTED / TESTED:** separate internal organization create/rename and current-tenant metadata services, exact UTF-8 name validation, current locking rename/audit snapshots and dedicated transactional organization history. Provisioning creates one root and its audit only, never membership/default users/roles/units/content access. Existing tenant context, RBAC, hierarchy and production denial remain unchanged.

Final local regression after the last test-code fix: **224 tests / 1768 assertions passed** — SQLite94/744, MySQL126/1005, Redis4/19. Composer strict/platform/audit, Pint, existing Node24 build, routes/about/status/diff and Herd/phpMyAdmin checks passed. No final failures/skips.

**FR-ID-001 is PARTIAL**: independent technical identity/provisioning are implemented; operational state and user-facing administration are **DEFERRED** pending owner approval. No lifecycle/status feature is claimed complete.

## 2. Issue and scope

Issue #22 distinguishes technical provisioning from tenant membership and owner-approved management authority. Branch **feat/22-organization-management**, created from latest fetched main. [Implementation plan](../PHASE-04B-IMPLEMENTATION-PLAN.md) written before code. Scope: internal create/rename, scoped minimal metadata, dedicated history and tests/docs. Optional global organization listing is deferred because there is no justified exposed directory contract.

No application-user entry point, administrator policy/default, production panel enablement, lifecycle/deletion/invitation/billing/content operation, dependency or contributor UI change.

## 3. Initial GitHub SHA

Baseline **7db0557ca5e1481a7d44e7ce2e48151b316cbfe1**. GitHub confirmed PR #21 merged; fetched origin/main matched and its tree matched the previously reviewed Phase04A final tree. Independently verified post-merge [push CI41664254765](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41664254765) completed successfully on that SHA. The connector's PR-only workflow wrapper could not return a push run; public GitHub API readback supplied this evidence.

Fresh baseline SQLite66/394 passed. Historical baseline MySQL86/557 and Redis4/19 were subsequently included unchanged in the final full regression. Local main was older and was not reset/developed on. A pre-existing untracked generated storage file remains preserved/excluded.

## 4. Requirements mapping

| Requirement | Delivered / boundary |
| --- | --- |
| BR-001 / AC-ID-001 | Independent tenant roots, no implicit shared tenant records, scoped metadata/no foreign lookup |
| BR-002 | Internal technical establishment without private methodological-content authority |
| FR-ID-001 | PARTIAL: technical creation/identity implemented; state and user-facing authority deferred |
| DATA-01 | organizations remains tenant root without organization_id; organization_changes is tenant-attributed |
| DATA-02 | Scoped member reads and restrictive root/actor history FKs |
| FR-UX-005 | Meaningful creation/name-change traceability, atomic with writes |
| ADR-001/002/003 | Shared MySQL, separate global identity/membership, deny-by-default surface, BIGINT/public immutable ULID |

No owner decision is silently resolved or marked approved.

## 5. References reviewed

Reviewed AGENTS, CONTRIBUTING, README, foundation/decision ledger, accepted ADRs001/002/003, tenant schema/authorization/hierarchy architecture, Phase03A/03B-B/04A reports, Herd setup, actual Organization/User/Membership/HasPublicUlid, factories/migrations/history, integration guards/tests and unchanged CI. Already reviewed baseline contents were confirmed identical by tree diff; applicable SRS v3.0 sections were fetched again through connected Drive (modified2026-10-31), with no private excerpts committed.

Current Issues #4/#6/#12/#13 were reviewed. UI contributor branch stays separate. Issue #6 exact protection/contributor enforcement remains owner verification, not a code defect or authority to edit Settings. Official [Laravel13 transactions](https://laravel.com/docs/13.x/database), [MySQL8.4 locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) and prior current-read source review inform the implementation.

## 6. Architectural decisions

Two narrow services instead of one permissive administrator abstraction: OrganizationProvisioning for platform-side internal create/rename, OrganizationManagement for member-scoped metadata. No new context/token/operator-role framework, broad Gate callback or hidden authorization default.

A persisted actor provides attribution, not administration authority. Trusted internal code may call these services; no application-user call site exists. Future interfaces must enforce owner-approved management/resource Policies before invocation. Initial creation cannot require membership in the nonexistent root; TenantContext is therefore neither used as a provisioning shortcut nor weakened.

## 7. Existing schema findings

organizations has unsigned BIGINT id, unique public ULID, VARCHAR255 name and timestamps, plus existing memberships/users/units relationships and model public-ID lifecycle guard. It is a tenant root, so no organization_id is added. Existing13 migration files were inspected; only one new additive migration is introduced. Root schema/IDs/name uniqueness semantics and all prior applied migrations preserved.

No status/lifecycle/slug/code/category/subscription/headquarters/language/administrator/Quality Department/arbitrary metadata added. Duplicate institution display names remain valid.

## 8. Implementation structure

New app/Actions/Organizations/OrganizationProvisioning.php: create(actor,name), rename(actor,publicId,name), private actor/name validation and fixed audit insertion. New OrganizationManagement.php: metadata(actor,publicId) via existing TenantContext. No provider/config/guard/model/factory change. Complete architecture: [organization-management-foundation.md](../../architecture/organization-management-foundation.md).

No global directory/repository/interface scaffolding. Services hold no new mutable tenant/permission state and perform no cache/queue/notification work.

## 9. Organization creation behavior

One transaction: verify a real persisted global User using current shared locking read, validate exact name, save one Organization through HasPublicUlid, append created event and return persisted model without eager relationships. Fake/deleted/unsaved/guest actor rejects, including deletion hidden by an older snapshot.

Each invocation creates exactly one root plus history. No membership/user/role/permission/unit/default department/invitation/notification/subscription is created; existing organizations remain untouched. Duplicate names produce distinct immutable public identities. Creation inside another tenant context does not switch/extend it. No automatic retry/idempotency contract is invented; ambiguous commit/ack recovery requires inspection before another create.

## 10. Rename behavior

Resolve a valid public ULID with current FOR UPDATE, then current actor shared lock; compare actual persisted name after lock acquisition. Only name/updated_at changes; identity/created_at/memberships/units/roles/assignments and previous history stay intact. Missing/malformed/numeric internal IDs reject. Audit records actual previous/new names and actor.

Names are exact UTF-8 strings with nonblank content and maximum255 code points; arrays cannot supply arbitrary attributes. No trimming, transliteration, normalization, case folding, slug generation or uniqueness rule. Removing surrounding spaces or changing case is a real change; exact equality is a no-op. Clock-advanced tests prove no timestamp/event update for no-op, including a second writer that requests the first writer's committed name.

## 11. Tenant-scoped metadata reads

Require matching trusted context actor, current organization/actor/membership shared locking reads and existing context verification. Lookup is by **context organization key only**, never a submitted foreign public ID. After verification compare the requested ULID, accepting identifier case variants while returning its stored canonical value. Foreign/missing/malformed input produces one generic not-found response without name/ID/existence disclosure.

Response contains only public_id/name. No private profile/relationship/history/content is loaded; verification selects actor/membership identifiers and existence booleans only. Query log proves no units/roles/permissions/history/documents/email/password query and at most7 queries. No internal BIGINT response, global listing, membership cache or switching interface.

## 12. Provisioning versus membership separation

A newly created root has no members; requiring one would prevent initial establishment or encourage a bypass. Provisioning is a trusted internal platform operation, independent of membership. It does not authenticate/authorize a business platform administrator. The global actor remains without tenant access until a separately authorized membership/policy process exists.

Metadata uses the existing member context boundary; membership remains structural eligibility rather than a user-facing management permission. Tests prove no creator membership/context/content grant, no context switch and A-B-A isolation.

## 13. Authentication and authorization boundaries

Existing web guard, User authentication, Spatie8.3.2 Teams, empty capability registry, TenantContext/TenantAuthorization and role services unchanged. No speculative business role/permission, Gate bypass or privileged user seed. No organization HTTP/API/Filament/Livewire/Artisan management surface.

Production/staging Filament remains fail-closed; current local/testing shell is unchanged. The new shared test verifies production403 then restores testing before guarded MySQL teardown. Future platform/tenant UI must apply owner-approved Policies first; internal service availability is not user authorization. Private institutional-content support access remains denied by default.

## 14. Audit/change history

Dedicated organization_changes, not RBAC authorization_changes or organizational_unit_changes. Fields: id, organization_id, actor_id, created/renamed event, nullable previous_name, required new_name and timestamp. Restrictive organization/global actor FKs preserve references; no actor membership FK because provisioning precedes membership. Event CHECK rejects unsupported values.

Fixed private append-only service writes contain necessary exact names/IDs/time, no profile/email/password/token/document payload. Mutation/audit insertion commit or roll back together; audit failure and outer rollback preserve no orphan event/root. No-op/rejected changes write no success event. There is no audit endpoint/edit/purge/retention policy. Raw DML/operator history remains tamperable; application append-only is not tamper-proof storage.

## 15. Database migration details

New file: **2026_11_03_000008_create_organization_changes_table.php**. Single CREATE with inline BIGINT/FK/CHECK definitions for verified MySQL/SQLite; total14 after inspected13-migration baseline. No original table rebuild/alter or migration edit. Actual FK failure1824 leaves no partial table; correction permits ordinary retry. Existing-table1050 preserves rows without adoption/drop. Disposable rollback/repeat retains old tenant data/triggers/globals.

MySQL CREATE/journal insertion is not atomic across process death/disconnect. Inspect unexpected table/journal state; never auto-adopt/drop/mark applied. Rollback drops history and loses its rows, so it is not production recovery. Review target/backup/maintenance and CREATE/REFERENCES operator privileges. Original trigger provider/binlog/definer/cleanup prerequisites remain; no global changes.

Local separate operator applied only this migration to actual13306/MySQL8.4.13:14 journal entries, new history0 rows, original tenant/RBAC/hierarchy DDL/counts and both trigger definitions/definers preserved. Runtime remains SELECT/INSERT/UPDATE/DELETE only; .env/private service-file hashes and binlog1/trust0 unchanged. No new organization or default account was seeded into the application database.

## 16. Transaction and concurrency evidence

**TESTED / PASS:** six dedicated real MySQL cases, including two separate-process rename variants:

| Case | Actual result |
| --- | --- |
| Distinct concurrent renames | Second connection establishes Initial snapshot, performance_schema proves wait on organization row, first commits Initial->First, second audits First->Second; final committed name/events verified |
| Same committed name | Same actual wait/snapshot; second requests First and performs no save/event; advanced child clock proves timestamp preservation |
| Actual1205 timeout | No partial rename/history, clean competitor transaction, fresh operation succeeds after release |
| Unrelated roots/same actor | Second root rename commits while first remains locked; actor shared locks avoid unnecessary global serialization; first rollback preserves second |
| Revocation/old snapshot | Second connection deletes membership; ordinary context verification still sees it but current metadata read denies |
| Actor deletion/old snapshot | Ordinary snapshot still sees actor; current provisioning actor read denies and inserts nothing |

Worker repeats explicit opt-in/actual disposable DB/config-cache/MySQL8.4/REPEATABLE READ guards. Payload is stdin JSON; credentials stay in child environment, not argv/output. Wait/cleanup are bounded. No naturally occurring InnoDB deadlock was tested or claimed. Errors propagate with no automatic retries/external effects; mixed outer/cross-service lock ordering can still deadlock.

## 17. Tenant isolation tests

Shared28 actual SQLite/MySQL cases cover valid/blank/invalid UTF-8/255-length/overlong/array/Arabic/Latin/Unicode/SQL-like names, duplicates, exactly one root/no defaults, actor validity, ULID immutability, preserved related data/history, exact/no-op names, audit failure/outer rollback, minimal read/query scope, foreign-ID non-disclosure, guest/wrong/absent/nonmember/revoked/stale User and sequential contexts, no route/grant/production panel access.

Synthetic fixtures only. Foreign metadata ULID is never present in SQL bindings; submitted IDs cannot select another organization. No mocked authorization booleans substitute for persisted behavior.

## 18. SQLite test results

**PASS:** final php artisan test, actual SQLite :memory:/array cache/array sessions/sync queue, **94 tests / 744 assertions**. Original66/394 plus28 new scenarios/350 assertions. Existing driver-isolation assertion remains. No accidental application MySQL/Redis use; no final failures/skips.

## 19. MySQL integration results

**PASS:** final complete **126 tests / 1005 assertions**, about187 seconds locally, actual MySQL8.4.13/quality_platform_ci on separate loopback33068, explicit opt-in and existing MySqlTestCase safety checks. Original86 cases retained plus28 shared,6 concurrency and6 schema/history cases. Includes all prior hierarchy races/error1419/direct-self-parent/cross-tenant FKs/RBAC/history migration checks. No final failures/skips.

Initial concurrency attempt before new container TCP initialization failed6 cases/0 assertions with greeting/2006 connection errors. Waited for actual TCP readiness and reran unchanged:6/53 passed. Initial full MySQL run had126 tests/1005 assertions with one teardown error: a new production-denial test left app environment production, causing guarded rollback to request confirmation. Fixed only test cleanup to restore prior testing environment in finally; kept403 assertion, rollback confirmation and guards unchanged. Then reran all three complete suites successfully. No application integrity behavior was weakened.

Existing fixture adaptations only: total counts13->14, permission rollback spans5->6 and7->8 for later history; unit-history fixture targets its exact migration rather than assuming it is last (retaining original Artisan success assertion). Original migration/trigger/model/RBAC code unchanged.

## 20. Redis regression results

**PASS:** actual Redis8.2.11, **4 tests / 19 assertions**, UUID test namespace and individual cleanup. Existing cache/queue/after_commit behavior preserved, no new organization/membership cache or broad local flush. Final full regression total: **224 tests / 1768 assertions**.

## 21. Composer/Pint/build results

| Required check | Result |
| --- | --- |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS |
| composer audit | PASS, no known advisories at check time |
| artisan about | PASS, existing PHP8.4.27/Laravel13.39.0/Filament5.11.0/Livewire4.4.8/Spatie8.3.2 and drivers |
| artisan migrate:status | PASS,14 applied after local additive operation |
| artisan route:list --path=admin | PASS, existing dashboard/login/logout only |
| Full isolated SQLite / MySQL / real Redis | PASS,94/744 +126/1005 +4/19 |
| Pint --test | PASS |
| npm run build | PASS, existing Node24.22.2/Vite7.3.7,61 modules |
| git diff --check | PASS |

No package/lockfile change, dependency reinstall, Node/PHP global reconfiguration or unknown-process termination. Workspace temp/cache and existing Node24 reused. Actual Herd /up and /admin/login and independent phpMyAdmin.test each returned200. No UI/browser visual test is claimed for backend-only scope.

## 22. CI results

**PASS:** unchanged [Foundation CI41672796497](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41672796497), job126045318335, on implementation **3894f7512a45c46b4d78782a78d0cca76c22098f**. Remote logs confirm SQLite94/744, MySQL126/1005 and Redis4/19: **224/1768**, matching local results. All applicable dependency/platform/audit, migration rollback/repeat, Pint, clean Linux npm ci/build (61 modules) and cached-runtime HTTP steps succeeded. The Issue #4 branch-specific scope guard is correctly inapplicable, not a skipped security suite. This report-only follow-up persists the observed result, implementation SHA and PR link; its final-head CI is independently checked in PR #23 before handover. CI configuration/permissions unchanged.

## 23. Security review

No administrator default, tenant-membership workaround, context bypass, support-content grant, unapproved permission/role, global Gate callback, public management/listing endpoint, production panel enablement or contributor UI modification. Organization root remains without organization_id/status and keeps BIGINT/public ULID constraints. History scope/root and global actor FKs are real DB protections; actor attribution is not authorization.

Names are validated/bound data, no arbitrary field payload; foreign read errors disclose no metadata. Current actor/membership reads close stale-snapshot grants; no active-membership authority cache. Root rename transaction and history stay atomic, and unrelated roots remain independent. New history has restrictive actions/no cascade; raw history tampering and lifecycle policies remain explicit limits.

## 24. Known limitations

Services are internal, not a complete user-facing administrator permission system. Owner-approved management/resource Policies and request/Livewire integration/public serializers must precede exposure. No global directory, idempotent creation/retry contract, natural-deadlock proof, production workload benchmark or immutable raw audit storage. Raw SQL/model writes can bypass service audit/name validation and existing model ULID guards; no database-wide business workflow guarantee.

Current shared/exclusive locks may wait or deadlock with unrelated/noncompliant outer protocols; errors surface, callers must roll back outer transaction before reviewed retry. DDL/journal crash window and destructive history rollback need operator-controlled recovery. Restrictive organization/actor history may block future deletions intentionally; deletion/retention are not decided. No production deployment performed.

## 25. Deferred owner decisions

FR-ID-001 operational state is **DEFERRED**, not complete. D-001 support access, D-002 administrator/delegation matrix, D-008 retention/residency/recovery, D-021 membership/switching, D-023 suspension/state, D-024 enrollment/invitation/recovery/MFA, D-010/D-019 panel boundaries and other existing ledger gates remain unchanged. Organization deletion/archive/restore/transfer, default admins, billing/subdomains/themes/files/notifications/private-methodology administration and public directory also out of scope. Issue #6 owner protection verification and Issues #12/#13 remain open.

## 26. Files created/modified

- app/Actions/Organizations/OrganizationProvisioning.php
- app/Actions/Organizations/OrganizationManagement.php
- database/migrations/2026_11_03_000008_create_organization_changes_table.php
- tests/Support/OrganizationManagementScenarios.php
- tests/Support/organization-worker.php
- tests/Feature/OrganizationManagementTest.php
- tests/Integration/MySql/OrganizationManagementTest.php
- tests/Integration/MySql/OrganizationConcurrencyTest.php
- tests/Integration/MySql/OrganizationHistoryIntegrityTest.php
- tests/Integration/MySql/HierarchyHistoryIntegrityTest.php
- tests/Integration/MySql/PermissionIntegrityTest.php
- tests/Integration/MySql/TenantIntegrityTest.php
- README.md
- docs/README.md
- docs/architecture/foundation.md
- docs/architecture/organization-management-foundation.md
- docs/setup/herd-windows.md
- docs/engineering/PHASE-04B-IMPLEMENTATION-PLAN.md
- docs/engineering/reports/22.md
- docs/engineering/reports/PHASE-04B-ORGANIZATION-MANAGEMENT.md

20 scoped paths. Existing models/factories/authorization/config/guards/routes/Filament/Blade/Vite/localization/CI/package/old migrations/decision ledger untouched. Pre-existing untracked storage artifact excluded. Private local operator helper/logs remain ignored. No credentials, APP_KEY, token/cookie, real tenant record, private SRS excerpt or personal home path included.

## 27. Git commit identifiers

Baseline7db0557ca5e1481a7d44e7ce2e48151b316cbfe1, branch feat/22-organization-management. Implementation: **3894f7512a45c46b4d78782a78d0cca76c22098f**, feat: add internal organization provisioning and metadata management. The subsequent report-only handover commit persists observed CI/PR evidence; its identifier is visible in PR history rather than a fabricated self-reference.

## 28. Draft Pull Request URL

[Draft PR #23](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/23) targets main. Remote changed-file readback confirms this mandatory report and architecture among20 scoped paths. PR is open, draft and unmerged; no contributor UI/old migration/model/RBAC/CI path changed. No merge/deployment authorized.

## 29. Final engineering verdict

**IMPLEMENTED / TESTED locally and in CI:** the approved technical organization foundation and atomic history, separate from membership/content authority. **DEFERRED / PARTIAL:** FR-ID-001 operational state and user-facing administrator policy. **BLOCKED for production:** owner gates/Issue #13 and relevant deployment/security approvals. No implementation blocker within the internal scope. Draft PR #23 contains the committed permanent report and architecture with observed test/CI evidence; verify its final HEAD CI and stop for engineering-lead review. Do not merge or deploy.
