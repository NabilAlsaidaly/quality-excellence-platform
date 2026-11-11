# Tenant isolation schema contract

## Issue62 — bounded review and initial maturity additions

The [reviewed conditional TEST_ONLY authorization](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/62#issuecomment-6769110851) adds eleven migrations: two explicit revision candidate keys and nine directly tenant-scoped tables. Actual MySQL 8.4.13 inspection found **40 migration files, 40 journal entries and 40 InnoDB base tables**. The earlier measurements below describe their historical baselines.

| Table | Required isolation and integrity |
|---|---|
| maturity_workflow_stages | Organization + fixture + Approach identity, five fixed stage/ordinal pairs, one initial root per stage |
| review_maturity_assignments | Explicit grantee/issuer/revoker composite users(id,organization_id) links, finite content/stage scopes, stored non-NULL scope, exact positive 24-hour interval and paired revocation |
| maturity_workflow_configurations | Immutable numbered question/scale copies; exact organization/fixture/Approach/stage link, JSON shape and fingerprint |
| maturity_workflow_submissions | Immutable unrated answers/configuration/evidence snapshots, numbered per stage and bound to the exact configuration |
| maturity_workflow_evidence | Submission-specific expected-question binding and exact organization/Document/Version FK; no bytes or file serving |
| review_maturity_reviews | Exact immutable target, one pending slot, ordered predecessor chain, state/decision tuple, reviewer + scope + operation bound to the exact assignment |
| maturity_workflow_assessments | One initial result per stage, approved-review/submission/reviewer FK, preceding-stage assessment chain and manual category/snapshot consistency |
| review_maturity_audit | Finite grant/review facts with exact subject/assignment/scope/operation or review binding; deduplicated event keys |
| review_maturity_notifications | Exact same-tenant recipient and review, event/recipient deduplication, in-transaction delivery and actor-specific read state |

All FKs remain same-schema RESTRICT/RESTRICT. `approach_content_revisions(organization_id,approach_id,id)` and `document_versions(organization_id,document_id,id)` gain explicit UNIQUE candidate keys, independently journaled. No existing row, column or applied migration changes. InnoDB can silently replace an original implicit child-FK support index when the covering key is added. Each reverse key migration therefore restores that original two-column support index, if absent, **in the same atomic ALTER that removes the new candidate key**. This restores the prior integrity shape without changing FK enforcement or global settings.

The executable gate explicitly records every new column type/nullability, ordered FK, full UNIQUE, exact enforced CHECK and stored generation expression. Mutation tests remove or alter each required item and require rejection. MySQL's nullable metadata for stored generated values is preserved; the exact expression supplies the semantic guarantee. SQLite uses corresponding transactional DDL, JSON functions, stored expressions, explicit unsigned lower-bound checks and named candidate-key indexes.

Every one of the eleven reverse migrations checks all nine workflow tables before its first DROP/ALTER and refuses any populated workflow, including root/grant-only state. It also checks the owned shape/journal; unknown objects require reviewed forward recovery. Empty reversal follows explicit dependency order. Atomic CREATE/ALTER failures retain the ordinary migration retry path; a crash after DDL but before journaling still requires operator inspection. Exclusive migration maintenance and the existing MySQL trigger privilege prerequisites remain necessary. No adoption, reset, history purge, FK disabling or global database changes are supplied.

Conditional compatibility changes were justified by the first actual full MySQL run: 238 tests, 10 errors and 20 failures exposed unclassified tables, measured 40-versus-29 journal counts and original rollback fixtures blocked by the new empty FK children. The helper checks all new tables empty before reversing their eleven explicitly inventoried migration paths; original parent scenarios, partial-target counts, error-code checks, historical preservation and assertions remain. No runtime cleanup or retention policy is implied.

The new panel and all grants remain sealed to one trusted TEST_ONLY Request/PDO/database fixture. The actual institutional identity and real Approach, Document and workflow Policies apply. Bounded current-read/concurrency tests do not establish universal revocation synchronization: T17 remains deferred. Operational D002 grants, reassessment, real document release and deployment remain excluded.

## Issue62 — reviewed TEST_ONLY Document workspace addition

The [scoped engineering authorization](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/62#issuecomment-6764417922) permits the three additive Document workspace tables with explicit user candidate-key order, LONGTEXT capacity and strict expiry. Actual owned MySQL8.4.13 measurement after the new migrations found **29 migration files/journal rows and31 InnoDB base tables**. Existing classifications and negative assertions remain unchanged. These additions are not operational grants, D002/D007 ratification, a normal registered panel, or T17 acceptance.

| Added table | Classification and enforced contract |
|---|---|
| document_workspace_assignments | Direct tenant scope. Sealed TEST_ONLY/DOCUMENT_LOCAL_001 plus lower-hex fixture ID. Required restrictive root FK; grantee/issuer/optional revoker use child(id,organization_id) -> existing users(id,organization_id) explicit UNIQUE. Optional resource uses (organization_id,document_id) -> documents(organization_id,id). Institution create/list and document view/metadata/append/history scopes are disjoint. Exact24h requires a non-NULL calculated end, expires_at>starts_at and equality to starts_at+24h. Paired revocation fields. Full UNIQUE protects public/tenant identity, fixture+recipient+operation+generated scope, and the six-column audit identity. |
| document_workspace_assignment_changes | Direct tenant append-only application audit. Restrictive same-tenant actor/subject/document references; six-column FK binds organization, fixture, assignment, subject, generated resource scope and operation to the exact assignment. Only grant/revoke/expiration-observed/denied events; nullable assignment only for denial. Resource/operation CHECK and per-fixture event-key UNIQUE prevent mismatched attribution/duplicate lifecycle observations. No file bytes, keys or private event body. |
| document_metadata_revisions | Direct tenant immutable metadata snapshots. Restrictive root/document and actor(id,organization_id) FKs; public/tenant identity and organization+document+version UNIQUE. Positive UINT32 revision range is explicit for SQLite parity. Required title and LONGTEXT description preserve original UTF8 validation and65535-character capacity. Original Document and every DocumentVersion remain unchanged; metadata snapshots themselves are the metadata audit, without duplicate blob storage. |

All foreign keys remain RESTRICT/RESTRICT. Stored scope_key=COALESCE(document_id,0) closes MySQL NULL-UNIQUE ambiguity. MySQL reports the generated column nullable in metadata; the gate checks its exact stored generation expression as well as type and indexes, rather than assuming that physical nullable metadata means the expression may produce NULL. The existing column-introspection query also reads generation/extra metadata for these two new columns only; old tables' returned column shape is preserved.

The gate checks every new column type/nullability, ordered FK/candidate key, full-column UNIQUE, generated scope and exact enforced CHECK expression. New Document integrity tests mutate each FK, UNIQUE, CHECK enforcement/clause, nullability and scope expression and require rejection. No existing test or unknown-table assertion is removed or broadened.

Each new migration performs one atomic MySQL CREATE with inline constraints/indexes; SQLite creates its equivalent transactionally. Missing parent failures leave no partial table and retry through the normal journal. Unknown existing objects are refused, not adopted. All three down methods inspect all three tables before any DROP and refuse ANY populated workflow history. No cascade, history purge, FK disabling, new trigger or global configuration is introduced. The existing trigger migration privilege/binlog prerequisites still apply; the owned browser's database account is DML-only.

Compatibility maintenance is conditional on observed failures: actual strict classification failed for the three unclassified tables; actual journal count was29 rather than26; the original Document rollback failed3730 on the new empty FK children. The test-only helper first refuses any new populated assignment/audit/metadata row, then rolls back only the three explicitly named new migrations before an original parent rollback fixture. Original populated rollback assertions, explicit original migration targets, error codes and historical bytes remain intact. Empty fixture removal is not a runtime retention/repair operation. Process interruption between successful MySQL DDL and migration journaling still requires operator inspection; no automatic destructive reconciliation is supplied.

The private native test panel's admission is independent of Approach/J03 rights and is tested with actual institutional login, real DocumentPolicy and signed Livewire HTTP. Its support provider/adapter is explicitly registered only by the owned test application, not runtime providers or routes. Current document-local locks/refusals do not establish universal synchronization with existing shared authorization writers; T17 stays deferred and blocked.

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

## Issue40 — audited Approach mutation extension (2026-11-08)

The earlier inventories remain historical. There are now **22 physical migration files and 24 base tables**, including the journal. The added direct tenant table is approach_changes; no new global exception.

| Fact | Required contract |
|---|---|
| Identity | BIGINT unsigned internal PK and unique CHAR(26) public ULID. |
| Scope | Non-null unsigned organization_id, root RESTRICT FK and composite (organization_id,approach_id) → approaches(organization_id,id). |
| Attribution | Non-null actor_id → global users.id RESTRICT; current membership is revalidated by the Action, not a historical membership FK. |
| Owner history | Nullable unsigned previous_owner_id/new_owner_id, both composite organization/unit RESTRICT FKs; metadata requires both null, owner_changed both non-null and distinct. |
| Payload | before_state/after_state non-null JSON objects, event VARCHAR(20) enforced CHECK metadata_updated/owner_changed, created_at only. JSON truth and allowed snapshot fields remain an Action contract. |
| Query index | (organization_id,approach_id,id), bounded descending history reads; internal sort key is never returned. |

The executable TABLES/FKs/unique/type/nullable/check contracts include these facts. Feature MySQL tests remove/alter every new FK/unique/scope fact, exercise raw cross-tenant, malformed JSON/object shape/event/owner constraints and previous-owner restrictive retention. No assertions for the earlier schema are deleted.

Observed count change21→22 requires bounded fixture maintenance: TenantIntegrity full journal22; hierarchy/organization history full22 and selected old-migration removal21; identity's explicit original five-path rollback leaves17 and reapply22; Permission rollback14/16 retains the original targets; Trigger removes the dependent audit first. Approach's old two-table rollback becomes step3 and old revision-only failure step2, preserving original journal targets19/20. The plan names each file and reason; original negative scenarios and exact original targets remain intact.

The new migration has atomic single-statement MySQL DDL and transactional SQLite table/index creation, no trigger or server settings change. A populated rollback refuses before any DROP, including an identity-only Approach bank. Exclusive maintenance and forward recovery remain prerequisites. Ordinary model audit updates/deletion are blocked; raw SQL is not made immutable. No operational Policy/routes/capabilities are enabled. See [architecture](approach-bank-foundation.md) and [Issue40 results](../engineering/reports/40.md).


## Issue42 — Document/version extension (2026-11-08)

Actual disposable MySQL inventory:24 migration files/journal entries and26 base tables. Documents and document_versions are direct tenant tables; no global exception. Required keys/types/checks appear in the same executable TABLES/FK/UNIQUE/type/nullable/check contract.

documents: non-null org/creator, root/user RESTRICT FKs, unique id/public_id/(org,id), title255/descriptionLONGTEXT/created_at, index(org,created_at,id).
document_versions: non-null org/document/actor, composite(org,document)→documents(org,id), restrictive root/global actor FKs, unique public_id/storage_key/(document,version), positive unsigned32 range, fixed exact local disk, lowercase64hex SHA256 and nonnegative unsigned64 size checks. Optional detected media type is metadata, not a MIME permission.

New MySQL tests remove/change every critical FK/key/type/check fact and require contract failure; raw cross-tenant/duplicate/out-of-range/delete tests verify actual constraints. Model/service immutability is not raw-SQL immutability.

Fixture compatibility is measured, not an unknown-table/count bypass: full24; original five identity migrations removal leaves19; single history removal23; Permission offsets16/18 retain original targets. Explicit lead amendment additionally permits ApproachSchemaIsolationTest to select its original three/two/one migrations by path, leaving21/22/23 and preserving populated refusal before first DROP. Trigger fixture remains unchanged because document tables do not reference units. Existing assertions and scenarios stay enabled.

The two new migrations have atomic per-table DDL, existing-object refusal, guarded populated rollback including identity-only state, and empty rollback/reapply tests. No trigger/global DB changes or operational UI/authorization. See document-version-foundation.md and report42.md.

## Issue57 — isolated TEST_ONLY assignment schema

The new approach_assignments and approach_assignment_changes tables are direct tenant scope. Actual source inventory is26 migrations; the full synthetic schema is expected to contain28 classified base tables and is verified against live MySQL metadata/tests, not inferred from naming. Public ULIDs and scoped assignment uniqueness remain explicit. Composite account/organization and resource/organization references reject cross-tenant grants; the event reference additionally binds organization, fixture namespace, assignment and subject. All historical FKs are RESTRICT/RESTRICT; membership is rechecked rather than retained as a historical membership FK.

Metadata checks add required candidate keys, typed/nullability references, test classification/namespace/operation/time columns, the exact bounded event literal CHECK and presence/enforcement of the other assignment CHECKs. Semantic rejection of malformed operation/scope, TEST_ONLY classification, namespace, recipient, exact24h UTC interval and revocation tuple is exercised by raw MySQL DML in ApproachAssignmentIntegrityTest; this gate does not pretend to parse arbitrary SQL. Existing event/check/FK/unknown-table negative guarantees remain unchanged.

Two independently atomic CREATE migrations preserve retry after either failure; populated grant/event reverse steps refuse before the first DROP. Current-read assignment/structural checks live only in the named candidate boundary. This schema does not register operational privileges: normal authorization stays denied outside the trusted request-bound testing context. Raw SQL is not made immutable and existing deployment trigger prerequisites are unchanged.

Observed initial full-MySQL failures (193 tests/1434 assertions,8 errors/21 failures) demonstrated all eleven compatibility paths: missing classifications, actual26 versus old24 journals, old parent FK children and last-migration rollback targets. Empty new assignment children are rolled back by their explicit paths before an original parent fixture; populated history refuses. Original identity/Approach/trigger/permission scenarios and assertions remain, and document rollback selects its two original migration paths explicitly. There is no FK disable, global setting, deletion of historical fixture data to pass rollback, or CI amendment.

## Issue68 — approved v4 persistence foundation

Measured on the exclusively owned MySQL8.4.13 fixture:46 migration files/journal entries and45 base tables. The six additive migrations introduce five tables and one explicit initial-assessment parent candidate. The original40-migration manifest plus the six exact approved filenames is verified independently of journal count; base-table inventory must exactly match the executable classification map. Unknown tables still fail.

| Direct tenant table | Mandatory scoped references and integrity |
|---|---|
| document_intakes | Existing exact organization/document/version candidate; ordered actor and independent release actor references to users(id,organization_id); explicit fixture/TEST_ONLY/scenario; request/version uniqueness; scan/lease/generation/release CHECKs |
| document_intake_events | Exact organization/fixture/intake parent, nullable ordered actor reference, request uniqueness and enforced event/object/generation checks |
| maturity_reassessment_attempts | Exact original organization/fixture/Approach/stage/assessment candidate, pinned configuration and author; numbered approved predecessor; one-open generated slot; approved terminal submission/review pair and state CHECK |
| maturity_reassessment_decisions | Stored approved-state FK to the exact attempt, a second FK to its declared terminal submission/review pair, existing approved-review/submission/reviewer FK and ordered reviewer account reference; one decision per attempt/review; manual snapshot checks |
| maturity_reassessment_events | Exact scoped attempt and optional immutable submission, ordered actor reference, request uniqueness and enforced event/object checks |

Each has its own non-null BIGINT UNSIGNED organization_id and restrictive composite tenant relationships; the existing parent chain enforces organization existence. The contract records every actual column/type/nullability, required FK/UNIQUE/enforced CHECK and stored-generated expression. The initial-assessment candidate additionally requires organization_id,fixture_id,approach_id,stage_id,id. Removing or changing a required fact must fail the contract; raw-SQL operator immutability is not claimed.

MySQL reserializes the original fixture regex with an _ascii introducer after the additive parent-key ALTER. Only that proven charset-format difference outside quoted values is normalized, alongside the existing _utf8mb4 normalization. Quoted literal contents and all original drift/mutation assertions remain intact. Invalid fixture values remain subject to the actual enforced CHECK.

Engineering Lead separately approved only C15–C18 after the18-test run reproduced2 errors/9 failures. Empty R-tail migrations are reversed by explicit paths before original document/workflow rollback fixtures; no data is deleted to force compatibility, and every original safety assertion stays active. Populated new persistence refuses before any reverse DDL and has independent integrity tests. No old migration, Policy, permission, route, provider or CI change is part of this compatibility approval.

This is TEST_ONLY persistence. Exact initial-review exclusion also requires the locked Action invariant; SQL binding alone does not prove same-attempt creation history. Actual file/scanner/release/download and reassessment UI acceptance, operational grants and downstream effects remain separately gated. C01–C14/C19–C21 are excluded.
