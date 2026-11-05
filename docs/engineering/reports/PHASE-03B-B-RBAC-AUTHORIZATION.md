# PHASE-03B-B — RBAC authorization engineering report

Date: 2026-11-03. Input phase: **03C**. Issue [#18](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/18). Status vocabulary: **IMPLEMENTED / TESTED / PROPOSED / DEFERRED / BLOCKED**. This is technical infrastructure, not an approved business permission matrix.

## 1. Executive summary

**IMPLEMENTED / TESTED:** Spatie Permission 8.3.2 Teams, membership-verified scoped callback context, explicit tenant/resource authorization, internal configurable role services, restrictive additive schema, transactional security change history, and production/staging Filament denial. Local validation: **101 tests, 497 assertions passed** across actual isolated SQLite, disposable MySQL 8.4 and real Redis. No final test failures/skips. Composer strict/platform/audit, Pint, existing frontend build, admin routes and actual Herd HTTP passed.

The capability registry ships empty and all six new development tables are empty after migration. No business role, user grant, default administrator, public management endpoint or tenant-switch UI is introduced. Production panel access stays denied pending owner policy and Issue #13.

## 2. Exact scope implemented

Scoped context and fresh database membership/resource checks; configurable organization role names; internal create/list/rename/sync-permissions/assign/revoke/delete-unassigned; rejection of global/cross-organization roles and unrecognized capabilities; immediate trusted-check revocation despite loaded relationship/cache state; explicit package mutation-command refusal; six additive migrations and focused tests. [Architecture](../../architecture/tenant-authorization-foundation.md) defines all entry points and limitations.

**DEFERRED:** Business permission matrix, delegated administration, enrollment/invitations, membership lifecycle/switching UI, suspension, workflows/self-approval, document/evidence/Approach domain, tenant CRUD, production panel rollout and retention/residency.

## 3. Requirements and ADR references

Reviewed AGENTS, CONTRIBUTING, README, architecture foundation/decision ledger, accepted ADR-001/002/003, tenant schema design, Phase03B proposed preview, both permanent prior reports, Herd/Redis setup, actual models/migrations/tests/CI and Issue #4 contributor contract. Issues #12 and #13 remain open. Reviewed relevant SRS v3.0 global identity/isolation and ownership requirements through connected Drive, without publishing private excerpts. References: BR-001..005, FR-ID-001..012, DATA-01..10 and applicable isolation/security acceptance. No unavailable reference book is claimed read.

Official references and installed source reviewed: [Spatie prerequisites](https://spatie.be/docs/laravel-permission/v8/prerequisites), [installation](https://spatie.be/docs/laravel-permission/v8/installation-laravel), [Teams](https://spatie.be/docs/laravel-permission/v8/basic-usage/teams-permissions), [roles versus permissions](https://spatie.be/docs/laravel-permission/v8/best-practices/roles-vs-permissions), [cache](https://spatie.be/docs/laravel-permission/v8/advanced-usage/cache), [Laravel 13 authorization](https://laravel.com/docs/13.x/authorization), [Filament 5 users](https://filamentphp.com/docs/5.x/users/overview). Some direct documentation fetches returned unsupported markdown; indexed official pages and the installed release source supplied the technical evidence.

## 4. Repository baseline

Fetched main **4da072c7fd41c4c10f0f6682391f2c0e2a0184d4**. GitHub confirmed PR #15 merged as be6cd80 and PR #17 merged as 4da072c; the phase claim was independently verified. Created Issue #18 and branch **feat/18-tenant-rbac-foundation** from origin/main. A pre-existing untracked generated storage file was preserved/excluded. No main commits, force pushes, self-merge, contributor branch changes or protection/settings modifications.

[File-by-file plan](../PHASE-03C-IMPLEMENTATION-PLAN.md) was written before implementation. Refinements: shared security scenarios run against both SQLite/MySQL; separate command-refusal class; minimal audit table; explicit package null context reset; actual actor-membership concurrency test. Existing trigger tests now target their own migration fixture instead of assuming it is last. Existing migration-count expectation becomes 12; every original integrity assertion remains.

## 5. Dependency compatibility review

Laravel 13.39.0, PHP 8.4.27, Filament 5.11.0, Livewire 4.4.8, Composer 2.10.3. Release constraints: PHP ^8.3 and Illuminate auth/container/contracts/database ^12.0|^13.0. Existing User has no roles/permissions relationship conflicts. Existing web guard remains. MySQL indices use bounded 125-character names, 30-character guard, 100-character morph type and unsigned BIGINT; tested on MySQL 8.4.13 and SQLite.

Package-tools was already present through the existing stack. Final lock diff adds exactly one package; no prior package version changes or extra dependency additions.

## 6. Spatie version and installation details

**IMPLEMENTED:** spatie/laravel-permission **8.3.2**, Composer constraint ^8.3.2, exact locked source **e4e906f8ca5fe10fbe4b6a81f6c4e544a01d8053**. Both manifests committed. Initial Windows batch caret handling temporarily selected 8.0.0; corrected the constraint and resolved 8.3.2 before integration/testing. Two task-started Composer operations overlapped during slow autoload generation; an early dependent package-discovery/test attempt failed while the class map was incomplete. Both operations finished, package discovery was rerun successfully, and final lock/autoload/platform/audit/tests verified. No unrelated PHP/Vite process was stopped.

Workspace temp/cache overrides were used because the host PHP default temporary directory is unavailable to restricted commands. No global PHP/Node configuration was changed. Global Node remains unchanged; existing portable Node 24.22.2 built assets. No npm reinstall or lock change was needed.

## 7. Teams configuration

Teams enabled before first permission table migration, team_foreign_key=organization_id, custom resolver reads the current scoped context, web guard only. Roles require a non-null immutable organization through the model boundary and NOT NULL/FK schema. No nullable/global roles, wildcard grants or super-admin exception. Arbitrary team setters cannot establish scope; null only clears it.

Package Gate permission interception is off. The explicit tenant-capability Gate delegates to the resource authorization service. Raw Spatie role/permission methods and Blade directives remain package APIs, not tenant authorization controls.

## 8. Database schema and migrations

Six new 2026_11_03 permission migrations: permissions, roles, role_has_permissions, model_has_roles, model_has_permissions, authorization_changes. Each uses one atomic CREATE with inline constraints and separate journaling, avoiding Blueprint multi-statement CREATE/ALTER failure windows. MySQL and SQLite are the supported verified drivers. No applied Phase03A migration was edited.

Restrictive FKs replace package cascades. roles has organization/name/guard uniqueness and organization/id candidate key; assignment scope has a composite FK to roles and a composite FK to existing membership user/organization. Direct permission pivots are package-compatible but ignored by application authorization and have no trusted management action. permission definitions are explicitly global infrastructure, not tenant content. User-only polymorphic limitation and guard enforcement are documented.

Actual missing-reference MySQL error 1824 leaves no partial table, then normal retry works. Existing-table error 1050 preserves data. New-table rollback/repeat and all original full rollback, unsigned-ID, composite tenant FK and trigger error 1419 recovery regressions pass only on disposable quality_platform_ci. No test opt-in/target guard was weakened.

The existing development schema was verified at MySQL 8.4.13 / loopback 13306 with six applied baseline migrations. The separate operator applied only the six new migrations. Final journal=12; all six new tables=0 rows. Original users/tenant table DDL hashes and row counts, both trigger definitions/definers, environment/private service-file hashes and runtime DML-only grant were unchanged. binlog=1/trust=0 before/after; no SET GLOBAL, credential regeneration, database recreation, fresh/wipe/truncate/destructive rollback on application/native MySQL.

**Remaining deployment risk:** process death/disconnect between successful DDL and journal insertion still needs operator inspection. Earlier successful table migrations remain journaled; normal rerun resumes after SQL prerequisites are corrected. Never auto-adopt/drop an unexpected existing table. Review backups/maintenance window, exact target, CREATE/REFERENCES and unchanged trigger prerequisites; rollback needs DROP and explicit approval. Provider prohibition of required Phase03A triggers remains a deployment blocker.

## 9. Global identity and membership integration

Existing User authentication, hidden attributes, password hashing, membership and organization relationships preserved. HasRoles added. Membership remains the authority separate from Teams; no status/invitation/suspension columns or rules. Authorization queries current user, organization and membership, including revocation inside an established context. Database assignment-to-membership FKs reject nonmember grants; explicit revocation is required before removing an assigned membership.

## 10. Tenant context implementation

Laravel-scoped TenantContext starts absent; run takes an authenticated/trusted explicit actor and internal integer key or public ULID string. It looks up the organization and verifies membership before callback execution, then clears relations/restores previous nested state in finally. Forged/nonmember/deleted membership identifiers deny. Singleton registrar resolves scoped context at use time. Null lifecycle reset, scope replacement, A→B→A, nested exceptions and consecutive different identities are tested.

No automatic session/header organization selection or HTTP switching endpoint. Future Livewire/HTTP/job adapters must revalidate every execution and isolate long-lived scopes; no domain queue authorization implementation is claimed.

## 11. Role-management capabilities

**IMPLEMENTED internally:** list, create, rename, sync recognized permissions, assign/revoke verified members, reject cross-organization mutation, duplicate/invalid handling, reject deleting assigned roles, delete explicitly unassigned role. Changes are transactional with actor-membership/role/target-membership locks and ID-only security events. A competing membership removal actually times out with MySQL 1205 while an internal mutation holds its actor lock.

**DEFERRED for application users:** all management UI/API/CLI privileges until owner approves management authority and delegation. Internal methods are not organization-member administration rights. Future authorized interface must first apply an approved Policy/capability then call these services. Four upstream mutation commands explicitly refuse execution; no new privileged command/route is exposed.

## 12. Laravel authorization behavior

Dedicated TenantAuthorization plus named Gate implements authenticated actor + fresh structural membership + correct context + registered capability + role inheritance + persisted resource organization. Only existing Organization/OrganizationalUnit are supported; wrong type/tenant/actor, absent context, unknown capability or missing explicit grant denies. No policy-by-role-name or global bypass. Loaded relations and caller-mutated organization_id do not authorize.

Registry is empty by default. Future Policies/Actions must add approved assignment/state/business constraints with feature-specific tests. This foundation alone does not authorize any unfinished domain action.

## 13. Filament compatibility and security

User implements FilamentUser; canAccessPanel allows only admin in local/testing and denies production/staging/other environments. Existing admin/login/logout route contract unchanged. Actual login/anonymous redirect, testing shell success, production/staging authenticated 403 and absence of role/organization/preview routes tested. Local shell access grants no roles/memberships.

AdminPanelProvider and contributor UI paths untouched. Actual Herd /up and /admin/login=200, independent phpMyAdmin.test=200. Issue #13 still must complete owner-approved panel/resource boundaries, enrollment/recovery/MFA, membership/session behavior, HTTPS/cookie/CSRF hardening, audit policy and deployment/rollback verification. Production remains **BLOCKED pending those gates**.

## 14. Redis/cache behavior

Permission definition cache is array, independent of existing Redis cache/queues. Membership is never cached as authority. Trusted authorization issues fresh package relationship exists queries and ignores cached definitions/loaded assignments; raw package methods are explicitly insufficient. Role permission and assignment revocation are immediate in subsequent checks for stale User instances. A real throwing cache-store fixture confirms revoked access stays denied and a cache-failed permission mutation rolls back without committing a grant.

No FLUSHDB/FLUSHALL, broad cache purge, custom cache framework or permissive fallback. Existing Redis namespaces and after_commit behavior remain. This conservative approach costs database checks; no performance claim or benchmark.

## 15. Cross-tenant security tests

The same **23 scenarios** execute against SQLite and real MySQL: empty installation, guest/missing context, forged IDs/ULIDs, membership-only denial, explicit scoped grant, wrong actor/resource/capability, A→B→A loaded relations, nested failures, forbidden arbitrary setter, null reset, scoped lifecycle/consecutive identities, same names per organization, CRUD, cross-organization mutation/assignment, revoked assignments/membership, assigned-role delete denial, duplicate/invalid names, unknown capability, disabled mutation commands, global-role rejection, direct-permission denial, cache exception and transactional audit.

These use real databases/package relationships rather than mocked authorization booleans. Fixture roles/capabilities are synthetic test data only. Three additional Filament tests cover actual route and environment gates. Nine additional MySQL-specific integrity/recovery/concurrency scenarios supplement the original 21 regressions.

## 16. General test results

**TESTED / PASS:** actual in-memory SQLite, array cache/session, sync queue; independent of application MySQL/Redis. **44 tests / 207 assertions**, including every original 18/68 plus new security/Filament coverage. The existing driver-isolation regression proves actual SQLite selection despite external environment variables.

Other required checks: Composer validate --strict PASS; platform requirements PASS; audit PASS, no known advisories at check time; artisan about/admin route list/migration status PASS; Pint --test PASS; Node24 npm run build PASS (Vite7.3.7, 61 modules); diff whitespace check PASS. No npm dependency installation performed.

Early failures investigated: incomplete concurrent Composer autoload (resolved), a duplicate-role test expecting QueryException instead of Spatie RoleAlreadyExists (corrected to actual package behavior, raw SQL uniqueness separately tested), a temporary Windows UTF-8 BOM in a new PHP wrapper (removed), and initial Pint formatting findings (fixed). No original assertion weakened.

## 17. MySQL integration results

**TESTED / PASS:** **53 tests / 271 assertions**, actual MySQL8.4.13, disposable quality_platform_ci on separate loopback33068, explicit MYSQL_TESTS_ENABLED=1 and existing safety guard. Includes 23 shared authorization cases +9 new schema/recovery/concurrency cases +21 existing tenant/trigger cases. Globals remain unchanged. Final duration about103 seconds locally; no failures/skips.

Actual 1419 trigger privilege failure still cleans/retries safely; second-trigger failure, pre-existing rows, competing writer, unsafe cleanup and DROP failure regressions remain. The test fixture targets its units migration directly because new migrations follow it; count12 accounts for six additive tables.

## 18. Redis integration results

**TESTED / PASS:** **4 tests / 19 assertions**, real Redis8.2.11, UUID test namespace and targeted cleanup only. Existing queue/cache/outage behavior preserved. No production/tenant data or broad flush. SQLite + MySQL + Redis total: **101 tests / 497 assertions**.

## 19. CI results

**TESTED / PASS:** unchanged [Foundation CI run 41641559476](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41641559476) on implementation commit **0e32c5209be5d5e06ab08d8b8437932e17ffb548**, job 125936075699. All dependency/platform/audit, migrations/rollback/repeat, SQLite, MySQL, Redis, Pint, clean Linux npm ci/build and cached-runtime HTTP steps completed successfully. The branch-specific Issue #4 scope guard was correctly inapplicable, not a skipped security suite. Remote logs confirm SQLite 44/207, MySQL 53/271 and Redis 4/19, matching **101/497** locally. This documentation-only follow-up persists the verified run, implementation SHA and Draft PR link; its own final-commit CI is verified again in the PR before handover. No CI file or permission was modified.

## 20. Files created and modified

| Paths | Change |
| --- | --- |
| app/Authorization/CapabilityRegistry.php, TenantContext.php, TenantTeamResolver.php, TenantAuthorization.php | Scoped context and explicit resource authorization |
| app/Authorization/RoleManagement.php, UnavailablePermissionCommand.php; app/Models/Role.php | Internal management/model scope/command refusal |
| app/Models/User.php; app/Providers/AppServiceProvider.php | HasRoles, fail-closed panel, scoped bindings and named Gate |
| config/permission.php; config/authorization.php | Teams, conservative cache and empty registry |
| composer.json; composer.lock | Single authorized dependency |
| database/migrations/2026_11_03_000001 through 000006 create_permission_* | Six additive table migrations |
| tests/Support/TenantAuthorizationScenarios.php; tests/Feature/TenantAuthorizationTest.php; tests/Integration/MySql/TenantAuthorizationTest.php | Shared real security scenarios on two DB engines |
| tests/Feature/FilamentAccessTest.php; tests/Integration/MySql/PermissionIntegrityTest.php | Panel and MySQL constraints/recovery/concurrency |
| tests/Integration/MySql/TenantIntegrityTest.php; TriggerMigrationSafetyTest.php | Expected total and exact fixture target adaptation only |
| README.md; docs/README.md; docs/architecture/foundation.md | Current status and links |
| docs/architecture/tenant-authorization-foundation.md; docs/setup/herd-windows.md | Full boundaries, deployment prerequisites/risks |
| docs/engineering/PHASE-03C-IMPLEMENTATION-PLAN.md; docs/engineering/reports/18.md; this report | Plan and permanent handover |

Private local operator helper/logs remain ignored. No resources/Blade/CSS/Vite/localization/AdminPanelProvider/CI/guard configuration/routes/seeders/existing migrations changed. No secrets, private SRS copy, local personal paths or real tenant data included.

## 21. Security findings and mitigations

| Review item | Result / boundary |
| --- | --- |
| Cross-tenant escalation / submitted identifiers | Fresh context/actor/membership and persisted resource scope; composite role/membership FKs; negative tests |
| Global roles | NOT NULL/FK plus model invariant; raw SQL rejection tested |
| Unauthorized administration/escalation | No application-user mutation surface; internal service only; upstream mutation CLI disabled; empty registry/no grants |
| Membership revocation | Database revalidation; assignment FKs restrict deletion until revocation; actor lock serializes mutation |
| Stale cache/loaded relations | Fresh trusted queries, relation cleanup, array definitions; actual failure/revocation tests |
| Context lifetime / background reuse | Scoped callback/finally/null reset and consecutive identities tested; future adapters/jobs still require review |
| Panel exposure | Explicit production/staging deny; unchanged login/local shell; no tenant CRUD |
| FK/migration safety | Restrictive inline keys, atomic SQL failure and retry; original trigger guarantees retained |
| Confidential logging / public secrets | ID/event/timestamp-only transactional change history, no sensitive payload; ignored credentials preserved |
| Audit integrity | No edit/purge interface/cascade; raw DML/operator tampering remains a documented limitation |
| UI contributor ownership | No contributor-owned file or branch edits; full changed-path audit before delivery |

## 22. Deferred product-owner decisions

D-001 exceptional support; D-002 role/delegation matrix; D-008 retention/residency; D-021 invitation/activation/switching; D-022 self-review; D-023 suspension; D-024 recovery/MFA; D-027 ownership/domain relationships; D-010/D-019 panel/UI boundaries. States preserved unchanged in the ledger. No technical implementation marks them approved.

## 23. Technical limitations

Internal role services require a future approved management-policy caller; membership is not that policy. No user-facing feature is enabled. Raw package checks and direct permissions do not meet application boundaries. Registry extensions require implemented feature Policies/Actions. Only User morph model and two resource types supported. Role IDs are internal BIGINT only; future external role-management contracts must introduce immutable public IDs under ADR-003 before exposing identifiers.

Authorization is a current DB checkpoint, not a transactional lock over future unrelated work. Long-lived concurrent/fiber/Octane/Livewire/job adapters need explicit scope and middleware integration tests before rollout. There are no domain jobs/files/downloads yet, so domain authorization for those is **DEFERRED**, not claimed tested. No high-volume performance benchmark; fresh query overhead accepted conservatively. No automatic deadlock retry or bulk revocation workflow is invented.

Audit history is not tamper-proof and has unresolved retention/read policies. Restrictive FKs intentionally block some user/organization deletion. Crash-between-DDL/journal operator risk remains. Original provider trigger privilege/definer/cleanup prerequisites remain. Development service credentials/volumes were reused, not regenerated. Existing unrelated processes preserved.

## 24. Git commit identifiers

Baseline: 4da072c7fd41c4c10f0f6682391f2c0e2a0184d4. Branch: feat/18-tenant-rbac-foundation. Implementation: **0e32c5209be5d5e06ab08d8b8437932e17ffb548** (feat: add scoped tenant authorization foundation). The subsequent documentation-only handover commit persists the CI/PR evidence; its identifier is visible in PR history rather than a fabricated self-reference.

## 25. Draft Pull Request

[Draft PR #19](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/19) targets main. Remote changed-file readback confirmed this report and architecture among the 34 reviewed files, with no contributor-owned UI/CI path. PR is open, draft and unmerged; no merge authorized.

## 26. Final engineering verdict

**IMPLEMENTED / TESTED locally and in CI:** the authorized technical RBAC foundation with no default business privilege. **DEFERRED:** product policies/public management/tenant workflows. **BLOCKED for production rollout:** owner decisions and Issue #13. Draft PR #19 includes this permanent report and architecture; the lead must review internal boundaries, restrictive schema, migration recovery and test evidence before merging or enabling a future management interface. No self-merge; stop for engineering-lead review.
