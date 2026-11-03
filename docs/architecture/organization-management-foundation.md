# Organization management and provisioning foundation

**IMPLEMENTED technical infrastructure — no approved business administrator.** Phase04B / Issue [#22](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/22), 2026-11-03. Requirements: BR-001/002, FR-ID-001 / AC-ID-001, DATA-01/02 and FR-UX-005; accepted ADR-001/002/003. See [decision ledger](decision-ledger.md), [tenant schema](tenant-schema-foundation.md), [authorization](tenant-authorization-foundation.md) and [hierarchy](organizational-hierarchy-management.md).

**The technical provisioning foundation does not approve or implement a business administrator.** FR-ID-001 is **PARTIAL**: technical identity/provisioning/isolation are implemented; operational state and user-facing administration remain deferred. No status/lifecycle field or behavior is introduced while D-023 is open.

## Existing schema and boundaries

organizations is the tenant root: unsigned BIGINT internal id, unique immutable public ULID, name VARCHAR255 and timestamps. It has no organization_id because it establishes that scope. Existing Organization relationships, HasPublicUlid model lifecycle guard, table/migrations/constraints and other tenant/RBAC/hierarchy structures remain unchanged. Duplicate display names are allowed. No slug/code/category/billing/default language/administrator/department/arbitrary metadata is added.

| Boundary | Service | Prerequisites / scope |
| --- | --- | --- |
| Platform-side internal provisioning | OrganizationProvisioning::create / rename | Explicit trusted application-code call, persisted actor for attribution; unavailable to application users until owner-approved platform management Policy |
| Current-tenant internal metadata | OrganizationManagement::metadata | Existing TenantContext actor/organization, fresh structural membership and supplied current public ULID; future user-facing caller still needs an approved resource Policy |
| Global organization directory | Not implemented | No justified current use case or approved exposed listing authority; deferred |
| Private institutional content | No grant | Provisioning, membership, ULID or platform staff identity never implies private-content access |

A new institution has no members. Creation cannot require membership in a tenant that does not exist yet. The provisioning service therefore does not enter or weaken TenantContext, manufacture a privileged context/token/role, or add actor membership. The supplied existing User is an accountable acting identity, not proof of platform administrator authority.

Both services are marked internal and have **no application-user call site, route/API, controller, Filament Resource, Livewire action or privileged Artisan command**. The trusted internal execution path is a direct reviewed application-code invocation. There is no user-supplied trusted=true flag or invented operator-authority rule. Future entry points must first enforce owner-approved management/resource Policies; merely exposing a service through DI does not authorize its caller.

## Create and rename

OrganizationProvisioning::create(actor, name) verifies a persisted global User with a current shared locking read, validates the name, inserts exactly one root through the existing ULID model mechanism, and appends one organization.created-equivalent history event in the same transaction. The actual concise event value is created. It returns the persisted model with no eagerly loaded relationships.

It does not create User, membership, role/permission assignment/definition, organizational unit, default Quality Department, invitation, notification, methodology or subscription. No existing context is switched or extended. A creator without membership still cannot establish tenant context/read private content afterward.

rename(actor, publicId, name) resolves one valid public ULID with a current FOR UPDATE read, then verifies the actor with a shared locking read. It compares the current persisted name, changes only name/updated_at when different, and appends renamed history with the actual previous/new values. Internal IDs/public_id/created_at, memberships/units/RBAC assignments and all existing history are preserved. A stale caller model is never used as the old-name authority; callers pass an identifier, not a mutable organization payload.

Exact equality is a no-op with no save, timestamp change or new event. Missing/malformed/internal-numeric identifiers reject with generic ModelNotFoundException; nonexistent/unsaved/fake actors reject with AuthorizationException. No external effect or automatic retry occurs. Each create invocation creates a new tenant: duplicate display names are valid and no idempotency key/transport retry contract is invented. After an ambiguous commit/ack failure, an operator must inspect before retrying to avoid duplicate provisioning.

## Name validation and identifiers

Names must be strings, valid UTF-8, contain a non-whitespace/non-Unicode-separator character and fit255 Unicode code points (the existing VARCHAR limit). Array attribute payloads are rejected by typed signatures, so unrelated fields cannot be mass assigned. Names use bound parameters; SQL-looking input is text, not executable SQL.

The stored representation is exact: no trimming, transliteration, case folding or Unicode normalization. Surrounding whitespace on a meaningful name is preserved; whitespace-only input is rejected. Removing surrounding spaces, changing case or changing Unicode representation is a real rename if the resulting valid string differs. No-op comparison is strict PHP string equality after validation, independent of MySQL display-name collation. Duplicate names, including equal names across tenants, remain allowed.

Only existing ULIDs identify resource references; BIGINTs remain internal. metadata returns the canonical stored ULID and name only, never internal id, actor identity, membership details or private content. ULID case variants are accepted consistently with the existing ULID/DB lookup convention, while the stored identifier is unchanged. ULIDs are identifiers, not secrets or authorization credentials. Existing model public-ID immutability and DB uniqueness are preserved/tested; direct raw SQL remains outside model lifecycle guards.

## Member-scoped metadata reads

OrganizationManagement::metadata(actor, publicId) requires a matching existing TenantContext actor. It opens a transaction and uses current shared locks in organization -> actor -> membership order. The root lookup is **only by the trusted context's internal key**; a submitted public ULID never drives a global organization lookup. After current actor/membership existence and existing context verification, it compares the supplied ULID with the current root. Foreign/missing/malformed identifiers share a generic response that includes no foreign ID/name or existence distinction.

Only public_id/name is returned. Verification reads minimal actor/membership identifiers, not their collections/profiles, and no relationships are eagerly loaded. Query logging verifies no units/roles/permissions/history/documents/email/password queries and at most7 context/metadata queries. There is no N+1 metadata list, organization cache, global listing, header/session switching mechanism or permission fallback.

Current locking membership reads deny deletion/revocation even when an older REPEATABLE READ snapshot or loaded User relationships still see the former row. Shared locks retain actor/membership validity through the operation, then release at the enclosing transaction's end. Existing TenantContext remains an additional structural check. A-B-A contexts are isolated; provisioning inside A cannot make the actor a member of a newly created B.

## Transactions and concurrency

Create uses one transaction and an actor shared lock before inserting the new root; there is no existing target root to lock. Rename uses organization FOR UPDATE -> actor shared lock. Metadata uses organization shared lock -> actor shared lock -> membership shared lock. These current reads coordinate with existing hierarchy operations on the same organization, and avoid an unnecessary exclusive actor lock for independent renames.

Two renames of one root serialize on its row. Under default MySQL8.4 REPEATABLE READ, ordinary reads may retain an old snapshot, so rename compares/audits only after obtaining its current locking read. Separate-process tests establish an old Initial snapshot, block the second writer at the actual organization row, commit First, and verify the second records First -> Second. If the second requests First, it becomes a no-op after waiting. The worker advances its test clock to expose an accidental no-op save despite timestamp precision. A separate real connection can rename an unrelated root with the same actor while the first root remains locked.

Actual lock timeout1205 leaves no partial name/history and a fresh attempt can proceed after release. A real old-snapshot actor-deletion test denies provisioning and a real membership-revocation test denies metadata despite ordinary snapshot verification still returning true. No natural InnoDB deadlock is claimed tested. Deadlock/timeout failures propagate; there is no automatic create retry or repeated side effect. Callers must preserve lock order and roll back an encompassing transaction on errors. Mixed unrelated locking protocols can still deadlock; no universal deadlock-freedom claim.

References: [MySQL8.4 locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [consistent reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html), [Laravel13 transactions](https://laravel.com/docs/13.x/database). No global DB configuration, distributed lock or notification is needed.

## Dedicated change history and integrity

One additive single-CREATE migration creates organization_changes: internal BIGINT id/organization_id/actor_id, created/renamed event CHECK, nullable previous_name, required new_name and timestamp. The organization and actor FKs use restrictive update/delete actions. Actor is global and need not be a tenant member: a membership FK would make initial provisioning impossible and erase the distinction between attribution and authority.

Each event belongs to exactly one existing root and acting identity. For creation, previous_name is null; rename preserves exact before/after names. The service's fixed events and private insert method cannot accept arbitrary user event/actor/organization attributes. Names are necessary change metadata, not arbitrary request payloads. No email/profile/password/token/document content is stored. RBAC authorization_changes and organizational_unit_changes retain their separate meanings.

Mutation and audit insertion share one transaction; audit failure or outer rollback removes both, leaving no orphan provisioning history. Application code only appends, with no history read/edit/purge endpoint. This is durable transactional change history, **not tamper-proof raw storage**: runtime DML or a database operator can still alter raw rows. Retention/residency/read/purge policy is deferred. Restrictive history prevents deleting referenced organization/actor records; deletion/archive policy remains out of scope.

MySQL tests verify unsigned types, ULID uniqueness, actual FK targets/restrictive rules, fake actor/root failures, unsupported-event CHECK and required new-name enforcement. A global actor FK does not grant cross-tenant content access; application entry/read Policies remain necessary. Raw SQL can bypass service validation/audit and existing model ULID lifecycle guards; no database-wide business workflow guarantee is claimed.

## Migration and deployment/recovery

Only database/migrations/2026_11_03_000008_create_organization_changes_table.php is new. The13 existing migrations were inspected and preserved; total becomes14. The organizations root is neither rebuilt nor altered. Each CREATE includes all constraints inline to avoid a successful table CREATE followed by a failed ALTER. Actual missing-reference SQL failure leaves no partial table and ordinary retry succeeds after correction; an existing-table failure preserves all data and does not silently adopt/drop it.

MySQL DDL/journal insertion is still not one transaction: process death/disconnect after successful CREATE but before migration journaling requires operator inspection. Do not automatically mark applied/adopt/drop a table. Review exact target, pending SQL, maintenance/backups and CREATE/REFERENCES privileges; original Phase03A trigger/binlog/definer/cleanup prerequisites remain. Use the existing separate migration operator and retain runtime DML-only credentials. No global MySQL setting or production deployment is introduced.

Rollback drops history and destroys its rows; it is tested only in the opted-in disposable quality_platform_ci database and is not a production data recovery plan. Older fixture rollbacks now target exact historical paths or adjusted spans, retaining all original assertions and safety guards. Existing development volumes/secrets/.env/APP_KEY/Herd/native3306/phpMyAdmin/user processes must remain intact.

## Acceptance, integration and open decisions

Shared actual SQLite/MySQL tests cover exact/Arabic/Latin/Unicode/SQL-like names, invalid encoding/length/blank/array input, duplicate roots/no implicit defaults, actor validity, ULID immutability, rename related-data preservation/no-ops/atomic history, minimal scoped reads, foreign-ID non-disclosure, stale User/revocation/A-B-A isolation, no routes/grants and unchanged production denial. Dedicated MySQL tests prove concurrency/current snapshots, FK/type/event/restrictive history and migration failure/retry/preservation/repeat. Existing hierarchy cycle races, direct-self triggers/error1419 recovery, RBAC and Redis regressions are retained.

Future Filament/Blade/API integration must enforce owner-approved platform management Policies before create/rename, and tenant resource Policies/context resolution before metadata reads; explicitly serialize only allowed public fields. Do not infer a platform-wide directory, private-content support access or administrator role from these services. Preserve Arabic RTL UI ownership and coordinate with Issue #4 only in a later approved task.

**DEFERRED:** FR-ID-001 operational state, suspension/activation/deletion/archive/restore/transfers, invitations/membership lifecycle/switching, recovery/MFA, default administrators/role delegation, billing/subdomains/themes/files/notifications/private methodology administration and global directory. D-001/002/008/021/023/024/010/019 (and other open owner decisions) remain unchanged; Issues #6/#12/#13 retain their review gates. No status field or user-facing management capability is approved by this foundation. See the [permanent report](../engineering/reports/PHASE-04B-ORGANIZATION-MANAGEMENT.md) for measured results and delivery evidence.
