# ADR-001 — Tenant isolation strategy
Status: **PROPOSED — approval required**, 2026-10-31. Issue #7.
Relevant SRS: BR-001, BR-002, DATA-01..10, D-001, D-008, D-017.

## Decision proposal
Use a shared MySQL database and an explicit `organizations` root with `organization_id` on all tenant-owned records. Document every global table exception. Do not treat a user-provided organization ID as trusted.

Tenant resolution must be explicit and derived from verified membership and request context; check active membership and authorization before every operation. Model scopes are defence in depth, **not** authorization. Service-level policies and tenant-constrained lookups are mandatory on read/write/download. Background jobs must carry explicit tenant and actor context, re-validate before access, and avoid stale ambient context.

Use MySQL composite unique constraints and composite FKs including organization identifiers for critical cross-tenant references where feasible. Test that a tenant-A approach cannot point to a tenant-B document version or organizational unit. Avoid unconstrained polymorphic relationships in protected records. Never cascade-delete historical approvals, revisions or evidence snapshots. Keep uploads private and authorize downloads. No tenant content is accessible to Platform Administration by default.

## Alternatives
- Shared DB (proposal): low operational complexity; greater need for rigorous cross-tenant constraints and tenant-specific restore design.
- Separate schemas: operational isolation and migration complexity depend on database support; MySQL does not offer PostgreSQL-style independent schemas.
- Separate databases: stronger infrastructure isolation but higher provisioning, migration, monitoring and backup burden.

## Acceptance before implementation
Confirm applicable data location/retention requirements (D-008) and platform support policy (D-001), target MySQL and identifier strategy (D-017). Document organizations, memberships, current-organization resolver, global table allowlist, composite-key strategy, lifecycle rules, and two-tenant negative integration tests. Review indexes and rollback/restore plans. Do not create migrations based on this draft.
