# Organizational hierarchy management

**IMPLEMENTED technical backend foundation — internal use only.** Phase04A / Issue [#20](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/20), 2026-11-03. SRS references: BR-001, FR-ID-002 / AC-ID-002, DATA-07 and FR-UX-005 / AC-UX-005; accepted ADR-001/002/003. See the [decision ledger](decision-ledger.md), [tenant schema](tenant-schema-foundation.md) and [authorization foundation](tenant-authorization-foundation.md). No private SRS text is copied.

## Existing schema and scope

Retain organizational_units adjacency list: internal unsigned BIGINT id/organization_id/parent_id, immutable public ULID, name VARCHAR(255), timestamps, organization FK, composite same-organization parent FK/candidate key, parent lookup index and two direct-self-parent triggers. Existing model relationships, factories, model lifecycle guard and applied migrations are unchanged.

Multiple roots, duplicate display names, multiple children and valid depth are supported without invented business depth/child-count limits, types, positions, ordering columns or a single-root requirement. A unit's position grants no RBAC/review/approval privilege. No closure table/nested set/materialized path is introduced: the existing structure supports a one-row parent change and indexed parent relationships without schema replacement.

## Trusted application service

app/Actions/OrganizationalUnits/OrganizationalUnitManagement provides:

| Method | Internal contract |
| --- | --- |
| create(actor, name, parentPublicId=null) | Create a root or scoped child with existing model ULID generation; explicit trusted organization assignment |
| rename(actor, unitPublicId, name) | Update only the name, preserving public identity, parent, organization and descendants |
| move(actor, unitPublicId, parentPublicId) | Reparent inside the organization or move to root; move the subtree by changing only its root parent |
| tree(actor) | Return deterministic tenant-only indexed adjacency data, or fail on corruption |

These are trusted internal infrastructure with **no application-user call site, HTTP/REST/Artisan endpoint, Filament Resource or Livewire action**. Existing TenantContext is reused; no parallel context or global scope is introduced. Context actor identity and current user/organization/membership must exist. Membership is a structural prerequisite, not management/read authorization. A future user-facing caller must first enforce owner-approved capabilities and resource Policies, then invoke this service. CapabilityRegistry and the business permission matrix remain empty/unapproved. Production Filament gate stays unchanged and fail-closed.

Inputs identify units/parents by immutable public ULIDs; missing, malformed and foreign IDs yield the same scoped ModelNotFoundException without names or existence disclosure. Internal organization_id is taken exclusively from verified context, never unrestricted mass assignment. Name validation trims surrounding ASCII whitespace, rejects blank/invalid UTF-8 and more than 255 characters, and supports Arabic/Unicode. It does not impose uniqueness, case folding or linguistic normalization. Unchanged name/parent is a true no-op: no update/timestamp/audit write. Corrupt ancestry still fails before a no-op or root transition; there is no silent repair.

## Cycle algorithm

Here X -> P means parent_id(X)=P. Under the synchronization boundary:

1. Reload the target and intended parent using tenant-scoped current locking reads.
2. Validate the target's existing ancestor chain; even moving to root must not silently repair a corrupt graph.
3. Walk candidate P upward iteratively. Reject P=X, finding X anywhere above P, revisiting a visited ID, or encountering a missing/foreign ancestor.
4. Change only X.parent_id after all validation, then append its audit record in the same transaction.

For create, validate the candidate parent's chain before attaching the new node. Rename also validates touched ancestry. A visited-ID set stops corrupt loops without recursion or a fabricated maximum depth. Old/candidate walks cost O(h_old+h_new) current queries/time and O(max(h_old,h_new)) temporary memory; create/rename use their one relevant chain. Moving a parent does not rewrite/traverse its descendants. A valid forest stays acyclic after each serialized trusted mutation. A mutation does not scan unrelated branches for legacy corruption; the full tree read validates the entire selected organization.

## Transactions, lock order and current reads

Every method uses DB::transaction with one attempt and this common order: organization row FOR UPDATE -> acting user row FOR UPDATE -> organization membership row FOR UPDATE -> scoped target and parent/ancestor unit rows. Mutation/audit writes then commit together. The current membership read prevents a revoked row still visible in an older MVCC snapshot from granting structural eligibility. Existing TenantContext verification is an additional check, not a replacement for that current read.

The organization lock serializes cooperating hierarchy writers for that organization. Under MySQL8.4 default REPEATABLE READ, ordinary SELECT can retain an older snapshot. All graph decisions use FOR UPDATE current reads **after** acquiring the common lock; a waiting second transaction therefore sees the first writer's newly committed parent chain. It cannot authorize an opposing move from a stale snapshot. Holding an encompassing caller transaction retains these locks until its outer commit/rollback. Callers must preserve the same order, avoid pre-locking units before the organization and avoid mixing multi-organization transactions or unrelated locking protocols without review.

Tree reads deliberately use the same exclusive organization/current-row lock protocol for a simple consistent current read, including when called inside an older snapshot. This reduces concurrency and can block writers/readers within an organization; the global acting-user lock can also serialize that actor across organizations. It is a documented initial tradeoff, not a throughput claim. Future read optimization needs measured workload and consistency tests.

No Redis/advisory/distributed lock, global DB setting, automatic deadlock retry or external side effect is added. Inconsistent outer/other service lock orders can still deadlock; lock/deadlock errors propagate and callers must roll back the encompassing transaction before any reviewed retry. A real nested InnoDB deadlock may abort that outer transaction. The service does not turn errors into success or silently repeat a create.

Official version references: [MySQL8.4 locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html), [Laravel13 transactions](https://laravel.com/docs/13.x/database) and [pessimistic locking](https://laravel.com/docs/13.x/queries#pessimistic-locking).

## Guarantees and bypass boundary

| Layer | Guaranteed / limited |
| --- | --- |
| Existing DB FKs | Organization existence, same-organization parents, referenced-parent deletion/update restriction |
| Existing MySQL triggers | Direct self-parent INSERT/UPDATE rejection, including raw SQL |
| New application service | Scoped current membership/identifiers, name validation, multi-level cycle rejection, no-op behavior and transactional audit |
| Organization-row protocol | Serial ordering/current ancestry for all cooperating hierarchy operations |
| Raw SQL, Eloquent save/bulk updates, unrelated writers | Can bypass multi-level cycle checks, organization lock and audit; these are not approved application hierarchy write paths |
| Audit FKs | Same-organization unit and previous/new parents, existing actor/organization, restrictive historical references |

The database does **not** enforce acyclicity of arbitrary-length graphs. Tests intentionally create a real two-node cycle with raw SQL while FKs/triggers remain enabled; tree/mutations touching it reject rather than repair. Existing low-level model/factory writes remain available for fixtures and trusted operator work; no current application mutation entry point uses them outside this service. Any future approved hierarchy writer must use the service/protocol.

## Tree read model and cost

One tenant-filtered SELECT of id/public_id/parent_id/name, ordered by internal id, loads all units with current locking reads. Iterative indexed validation marks completed ancestor paths; each node is processed a bounded number of times. Missing parents and cycles fail, including disconnected cycles without roots.

The result is a flat array: roots is an ordered list of root public ULIDs; nodes is keyed by public ULID with internal id, public_id, name, parent_public_id and ordered child ULID references. This avoids recursive output construction/traversal and copying an entire nested subtree per ancestor. Assembly/validation cost O(n) time and O(n) memory after the query, with no N+1 units query. An empty organization returns empty roots/nodes. Technical insertion/id ordering is deterministic; it is not a user-configurable business order.

The internal id field is for trusted application work only. Future public serializers must omit internal BIGINTs, use immutable ULIDs and enforce Policies. No response endpoint is introduced. Full materialization and lock duration must fit deployment memory/time budgets; no claim of unlimited size or a benchmark is made. A 121-node chain test measures one units query and at most eight total context/graph queries on both engines; it is not proof of production scalability or SQLite row locking.

## Durable unit-change history

One additive migration creates organizational_unit_changes with internal BIGINT id/organization/actor/unit keys, created/renamed/reparented event, previous/new parent IDs, previous/new name when needed and timestamp. Organization, actor and all three organization/unit references use restrictive FKs; event values have a CHECK. Existing units candidate keys suffice, so no original schema alteration is required. MySQL creates the required FK indexes; no speculative tree/order index is added.

Service writes append only. Create stores the initial name/parent; rename records before/after name and unchanged parent; move records before/after parent without duplicating names. No-op/rejected operations produce no success event. There are no history edit/delete/read endpoints. authorization_changes remains RBAC-only. Names are the necessary unit-change metadata, not arbitrary request payloads; no emails, credentials, document bodies or user profile snapshots are stored.

Insertion failure rolls back the mutation; outer rollback removes both. Historical parent references can prevent deleting a former parent even after a child moves away. Deletion/archive/retention policies are deliberately deferred. The runtime DML/operator can still tamper with raw tables: this is durable transactional application history, not immutable/tamper-proof storage or a complete security audit system. Audit read permissions, retention/residency/purge policy and stronger storage controls remain owner gates.

## Migration, recovery and deployment

The new table uses one CREATE with inline constraints on verified MySQL/SQLite. An SQL FK-creation failure leaves no partial table, and a corrected ordinary retry succeeds; existing-table failure never adopts/drops data. MySQL process death/disconnect between successful DDL and journal insertion remains an operator-inspection case. Review pending SQL, exact target, backup/maintenance window and CREATE/REFERENCES privileges. Runtime credentials remain DML-only; use the existing separate migration operator. Original Phase03A trigger/binlog/cleanup/definer prerequisites remain; no global settings or trigger replacement.

Rollback drops history and loses its rows: it is not an automatic production recovery strategy. All rollback/fresh/drop/rename corruption fixtures run only in the opted-in disposable quality_platform_ci schema. Existing trigger fixtures first roll back the dependent history table using explicit paths, retaining their original success/safety assertions. Other tests account for the extra table without removing any checks.

For corrupt legacy/raw-SQL data: stop all writers, retain protected backup/evidence and identify affected organization/IDs through a private operator-controlled integrity review. Determine the intended parent from an explicitly approved correction; do not infer it from names. In a reviewed transaction use the same organization lock, inspect affected relationships/current data, execute only the approved repair and retain a separately attributed repair trail. Run full tree validation and constraint checks before resuming. No generic repair/delete command, automatic root conversion, invented audit event or unreviewed SQL repair is supplied.

## Evidence and future integration

Shared SQLite/MySQL scenarios exercise creation, Unicode/name boundaries, duplicate names/roots/children, immutable identity preservation, rename/subtree moves/root/no-ops, self/two/three/deep cycles, malformed/foreign IDs, absent/wrong/revoked actor, deterministic tree/query counts, raw cyclic data, audit attribution/failure and outer rollback. Dedicated MySQL cases verify history FKs/keys/restrictive deletion and additive migration rollback/SQL failure/retry.

Concurrency tests use separate real PHP processes/connections and transactions: the second establishes an old REPEATABLE READ snapshot, performance_schema proves its wait specifically on the organization row, the first commits, then the second rejects opposing A/B moves from freshly read ancestry. A valid competing move also proceeds and its final tree is verified. An actual 1205 timeout leaves no partial write and succeeds after release. An injected server 1213 error tests rollback/no-retry after the unit update; it is explicitly **not** a naturally occurring InnoDB deadlock test. Another real two-connection test removes membership after a snapshot and proves current membership reads still deny.

Future Filament/Blade adapters may consume the service/read arrays only after owner-approved role/capability and resource Policies, trusted request/Livewire context resolution and public-ID serialization. No UI or additional panel is created now. Unit deletion/archive/restore/transfers, ownership/user reassignment, inherited permissions, approval chains, notifications and business lifecycle remain out of scope. D-001/002/008/021/022/023/024/027/010/019 and Issue #13 retain their current gates. Full actual validation and delivery evidence are in the [permanent report](../engineering/reports/PHASE-04A-ORGANIZATIONAL-HIERARCHY.md).
