# Tenant isolation schema contract

Phase04C / [Issue #24](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/24) adds a test-only regression gate for AC-GATE-003 / T-DB-001, DATA-01/02/03/07/08/09/10 and accepted ADR-001/002/003. It changes no application behavior, permanent database schema, permission, route or deployment configuration. BR-001/002 remain the isolation and platform-authority boundaries. DATA-04/05/06 concern future domain structures and are not implemented by this gate.

## Evidence and single source

The baseline was freshly fetched main `5b16aaaf529b8492a45efc1c99bf0400549c2f77` after Phase04B PR #23 merged. Actual MySQL8.4.13 introspection after the14 migrations in guarded disposable quality_platform_ci found **20 InnoDB base tables, 112 columns, 21 foreign keys, 49 indexes and 2 enforced CHECKs**. All21 foreign keys were same-schema RESTRICT on delete and update. Existing two unit triggers and their behavior are covered by the unchanged integrity/trigger tests.

The only executable classification allowlist is [TenantIsolationSchemaContract::TABLES](../../tests/Support/TenantIsolationSchemaContract.php). The following table documents that observed baseline and its rationale; it is not a second runtime registry. A classification change requires an expressly reviewed schema change and corresponding documentation, never automatic inference from a table name.

## Complete observed inventory

| Actual table | Classification | Reviewed mechanism / exception |
| --- | --- | --- |
| users | Global identity | One global authentication identity; membership and permission are separate. No implicit tenant access. |
| password_reset_tokens | Global infrastructure | Framework identity recovery storage; no new recovery policy is approved. |
| sessions | Global infrastructure | Framework sessions; optional indexed user_id intentionally has no FK in the existing migration. Session payload is not tenant authorization. |
| cache | Global infrastructure | Framework cache compatibility, not an unscoped private tenant-content store. |
| cache_locks | Global infrastructure | Framework lock infrastructure; not a grant. |
| jobs | Global infrastructure | Framework queue compatibility; future domain jobs must carry/revalidate explicit tenant and actor context. |
| job_batches | Global infrastructure | Framework batch bookkeeping, not tenant business records. |
| failed_jobs | Global infrastructure | Framework failure storage; private payload/log handling needs feature review. |
| migrations | Global infrastructure | Migration bookkeeping; explicitly included in the inventory. |
| permissions | Global capability catalog | Unique name/guard definitions, not tenant grants or a business role matrix. |
| organizations | Tenant root | Defines the organization boundary; unsigned internal BIGINT and unique public ULID, no organization_id. |
| organization_memberships | Direct tenant scope | Required organization_id; restrictive root/user FKs; unique user/organization pair. |
| organizational_units | Direct tenant scope | Required root FK; nullable parent via composite organization/parent FK and organization/id candidate key; unique public ULID. |
| roles | Direct tenant scope | Required restrictive root FK; unique organization/name/guard and organization/id. No nullable global roles. |
| model_has_roles | Direct tenant scope | Required organization_id; composite organization/role FK and model_id/organization membership FK. |
| model_has_permissions | Direct tenant scope | Required organization_id; composite model_id/organization membership FK and global permission FK. Package compatibility; direct permissions do not grant trusted application access. |
| authorization_changes | Direct tenant history | Restrictive root and global actor/optional subject user FKs. role_id is an intentional historical snapshot without a role FK. |
| organizational_unit_changes | Direct tenant history | Restrictive root/global actor FKs and three composite organization/unit FKs for current unit and previous/new parents; enforced event CHECK. |
| organization_changes | Direct tenant history | Restrictive root/global actor FKs and enforced event CHECK. Actor membership is not required for initial provisioning. |
| role_has_permissions | Indirect tenant scope | Restrictive role_id -> roles.id, restrictive permission_id -> permissions.id, unique permission/role pair. Tenant boundary follows the scoped role; no organization_id required. |

The nine global infrastructure/identity exceptions, catalog, root, eight direct tables and indirect pivot exhaust the observed schema. These exceptions do **not** authorize arbitrary private institution content in global tables. IDs identify records; they never authorize a caller. A persisted provisioning actor is audit attribution, not a business administrator.

## What the executable gate checks

`TenantIsolationSchemaContractTest` inherits the existing MySqlTestCase safety guard and DatabaseMigrations. The existing phpunit.mysql.xml/CI discovery includes it automatically; neither configuration is changed.

The helper performs five read-only metadata queries scoped to DATABASE():

1. information_schema.TABLES, BASE TABLE only; enumerate actual names/engine.
2. COLUMNS, for actual type/nullability.
3. KEY_COLUMN_USAGE joined to REFERENTIAL_CONSTRAINTS, preserving ORDINAL_POSITION for both ends, actual target schema/table and update/delete rules.
4. STATISTICS, preserving SEQ_IN_INDEX, NON_UNIQUE and full-column versus prefix/expression index parts.
5. TABLE_CONSTRAINTS joined to CHECK_CONSTRAINTS, including ENFORCED and the two bounded event clauses.

System schemas, views and MySQL session-temporary tables are outside the base-table inventory. An ordinary fixture table is **not** silently ignored by prefix/name: the negative test creates one and proves it is rejected, then removes exactly its own table. Metadata queries never select user names, audit names/IDs, credentials or tenant rows.

Unknown names fail with the table name and a request for reviewed tenant-scope classification. Missing/renamed approved tables also fail. Every existing table must retain InnoDB. Each direct table requires a non-null BIGINT UNSIGNED organization_id. Direct/indirect classification requires explicit reviewed FK relationships; adding only a table name cannot suffice.

The gate verifies all21 required actual FK relationships by ordered columns, target and same database, with RESTRICT on both actions. Required FK columns keep their unsigned BIGINT/nullability: only unit parent, optional authorization subject and previous/new historical parents are nullable. It does not invent membership FKs for global actors or direct root FKs where scope already follows a membership/role. Any historical FK, including an added one, must retain restrictive actions.

Nineteen critical unique tuples cover identities, public ULIDs, membership pair, scoped role candidate/name keys and the three permission pivots. Comparison uses full ordered column tuples and real uniqueness, not just an index name or comment. Other framework/nonunique performance indexes are observed, not all frozen as a schema snapshot. Existing MySQL FKs require their supporting indexes; no benchmark or query-plan guarantee is claimed.

The unit/organization event CHECKs must exist, be enforced, retain their reviewed bounded expressions and non-null VARCHAR(20) event columns. MySQL formatting is narrowly normalized; this is not a general SQL equivalence parser. Existing actual DML tests remain authoritative for event rejection and valid application writes. FK/index names may change without defeating semantic comparison; the two CHECK names are intentionally part of this small contract and need reviewed updates if renamed.

## Gate effectiveness and restoration

Five new tests exercise:

- The entire real schema passes, explicitly including root/global catalog/identity/indirect exceptions without organization_id.
- An actual unclassified tenant-looking table fails even though it contains organization_id; an infrastructure-looking unknown name also fails on a metadata copy.
- Removing the real role_has_permissions role FK fails on introspected metadata. Its exact restrictive FK is restored in finally, and the entire normalized metadata equals its pre-fixture state and passes again.
- Copies of actual metadata reject removed/renamed known tables, removed/nullable/signed scope columns, missing/nullable critical references, changed engine, wrong FK target/order/schema, delete/update cascades, missing/nonunique/partial keys, missing/disabled/changed CHECKs, nullable event and an added historical cascade. The database remains unchanged by these mutations.
- Actual CHECK replacement preserves embedded whitespace/charset text in changed literals and fails the gate; a correct expression with NOT ENFORCED also fails. Finally restoration returns the entire observed metadata to its original passing state.

Fixture ownership starts only after CREATE/ALTER succeeds. DROP targets the single owned fixture name; there is no broad synthetic cleanup or adoption of a pre-existing table. Normal suite teardown is the existing guarded migration lifecycle. No new FOREIGN_KEY_CHECKS override, global MySQL setting, trigger-definer change or application database operation is introduced.

## Existing evidence reused instead of duplicated

The [pre-implementation gap matrix](../engineering/PHASE-04C-IMPLEMENTATION-PLAN.md) maps each addition to a missing check. These existing suites remain unchanged:

| Guarantee | Existing evidence |
| --- | --- |
| Internal/public IDs, uniqueness/model immutability, memberships, composite parent, raw self-parent and restrictive references | TenantSchemaTest; TenantIntegrityTest::test_composite_foreign_key_and_candidate_key_exist, test_cross_organization_parent_is_rejected_on_insert_and_update, test_direct_self_parent_is_rejected_by_mysql_for_raw_insert_and_update and identifier/membership/deletion cases |
| Trigger1419 recovery, pre-existing rows, lock-safe cleanup and failure propagation | All TriggerMigrationSafetyTest cases |
| Scoped role/membership integrity, no nullable global role, raw cross-tenant assignment rejection, real actor lock | PermissionIntegrityTest |
| Absent/forged/wrong actor/cross-tenant/revoked/stale/cache deny, membership grants nothing, scoped internal role operations | TenantAuthorizationScenarios on both SQLite and MySQL |
| No default membership/role/unit/context grant, global actor attribution, minimal private-safe metadata | OrganizationManagementScenarios, especially provisioning_needs_no_membership, member_metadata_is_minimal, foreign_and_forged_metadata, no_management_route cases |
| Current concurrent reads and revoked old snapshots | OrganizationConcurrencyTest and HierarchyConcurrencyTest |
| Application-only multi-level cycle rejection, scoped hierarchy reads/writes and transactional history | OrganizationalHierarchyScenarios, HierarchyHistoryIntegrityTest and OrganizationHistoryIntegrityTest |
| Production/staging panel denial and absent tenant-management surfaces | FilamentAccessTest and existing service scenario route tests |
| Independent general tests and real namespaced cache/queue behavior | IsolationConfigurationTest and RedisInfrastructureTest |

No fake endpoint, extra capability or new service was added for testing. No old assertion, test name, fixture or skip condition changed.

## Safe extension and limits

### Issue32 candidate extension (Phase05B-1)

The preceding Phase04C inventory is historical. Issue32 adds five migrations and one direct table, `institution_password_reset_tokens`, with account/organization composite RESTRICT FK to `users(id,organization_id)` and unique account token identity. users remains mixed global principal/actor storage, now with nullable organization_id under explicit legacy/platform/institution shape rules, public account ULID, generated namespace and namespace/email uniqueness; root FK and candidate keys are explicitly checked. It is no longer an example of a table having no organization_id at all. Legacy actors/history and every original FK/unique/CHECK expectation remain protected.

The helper's single TABLES map and explicit FK/unique/type checks extend accordingly. The original global/root/catalog/indirect exceptions remain tested; the users test now asserts its precise nullable binding rather than wrongly requiring the new column absent. Existing migration-count tests assert19 applied/18 after removing one migration; path/rollback-step adjustments still target and exercise the same original migrations. No original negative assertion is removed. New raw identity/membership/token tests and populated/concurrent MySQL tests prove the added invariants; this is an authorized schema extension, not an unknown-table bypass. See [candidate architecture](institution-identity-entry.md) and [Issue32 report](../engineering/reports/32.md). ADR-004 remains PROPOSED.

For a future approved schema change:

1. Define its SRS/ADR authority and ownership in a separate Issue. Global exceptions need explicit review under ADR-001; a neutral table name is insufficient.
2. Add an appropriately scoped, compatible migration and feature-specific authorization tests under that Issue. This Phase04C authorizes no such migration.
3. Update the single TABLES map and explicit required FK/key/column/check facts together, explaining direct/root/global/indirect scope and historical deletion behavior. Update this descriptive inventory, not a second executable allowlist. Never generate expected constraints from the same actual schema being checked.
4. Run genuine negative two-tenant/service/DB tests for the new feature, then all existing SQLite/MySQL/Redis/CI checks. A green metadata gate alone is not feature acceptance.
5. Document deployment compatibility/recovery and request lead review; do not auto-approve or merge to bypass a failing gate.

This is a migrated-test-schema regression gate, **not** production drift monitoring, a SQL firewall or a proof of every relationship/business invariant. It cannot determine ownership of new columns added to a known global table, inspect private payload semantics, prevent raw SQL changes, or certify all additional constraints/indexes. Polymorphic model_type/guard handling remains an existing application invariant; only User assignments are supported. A model ULID guard does not make raw SQL immutable. Multi-level hierarchy acyclicity and append-only audit behavior remain application guarantees; raw operators can bypass them. Views, session-temporary tables, DB grants/definers, upgrade paths with real historical data and new job/file/download paths need separate reviewed verification.

No production rollout, data repair, privileged user, lifecycle/status/suspension, role matrix, membership workflow or owner retention policy is delivered. Existing migration crash/trigger privilege prerequisites remain in the setup documents. Issues #4/#6/#12/#13 and the decision ledger remain unchanged. A genuine defect requiring application/schema changes must be reported with reproduction and separately proposed remediation scope, never silently fixed in this test-only PR.

Official metadata references: [MySQL8.4 KEY_COLUMN_USAGE](https://dev.mysql.com/doc/refman/8.4/en/information-schema-key-column-usage-table.html), [STATISTICS](https://dev.mysql.com/doc/refman/8.4/en/information-schema-statistics-table.html), [TABLE_CONSTRAINTS / CHECK enforcement](https://dev.mysql.com/doc/refman/8.4/en/information-schema-table-constraints-table.html). Local Laravel13 schema introspection implementation was also reviewed. See the [permanent engineering report](../engineering/reports/PHASE-04C-TENANT-ISOLATION.md) for actual commands, results and delivery.

## Issue38 — approved bounded extension (2026-11-07)

The engineering lead authorized this schema-register/test-fixture amendment in Issue38. Earlier inventories are historical, not counts of the expanded schema. Two additive migrations create directly tenant-scoped approaches and approach_content_revisions; no global exception is introduced.

| Table | Explicit integrity contract |
|---|---|
| approaches | Non-null unsigned BIGINT organization_id/owning_unit_id/created_by. Root RESTRICT FK, composite (organization_id,owning_unit_id) to existing unique units(organization_id,id), restrictive global creator FK. Unique id/public_id/(organization_id,id); names intentionally not unique. |
| approach_content_revisions | Non-null unsigned BIGINT organization_id/approach_id/actor_id. Root RESTRICT FK, composite (organization_id,approach_id) to approaches(organization_id,id), restrictive global author FK. Unique public_id and (approach_id,version_number), enforced positive unsigned version range. All historical FKs restrictive. |

The single TABLES map and explicit FOREIGN_KEYS/UNIQUE_KEYS/type/range checks enforce these facts. Existing negative schema mutation tests remain unchanged. Feature-specific MySQL tests additionally remove/alter each new relationship/key/type fact in a metadata fixture and require rejection; raw cross-tenant insert/update, null owner, duplicate version and actual deletion restrictions are tested separately. No expected FK is inferred from the same actual metadata under test.

The physical migration inventory is21 files (19 pre-existing+2), confirmed separately against the journal in the feature-specific tests. Existing fixture adjustments retain the original rollback/failure target, including removal of dependent approach tables before the units trigger fixture; the isolated identity rollback explicitly selects its original five migrations so first-step refusal still preserves its complete schema/journal. No scenario or security assertion is removed. Populated Approach rollback refuses before dropping either new table; exclusive maintenance writes and reviewed forward recovery remain deployment requirements.

These Actions are internal structural/domain operations only; no end-user permission, Policy, route, role or capability is enabled. Append-only service/model history is not raw-operator immutability. Draft text never implies approved/public content. See approach-bank-foundation.md and report38.md for actual tests and remaining integration gates.
