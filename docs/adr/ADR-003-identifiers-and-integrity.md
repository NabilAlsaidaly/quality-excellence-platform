# ADR-003 — Identifier types and relational integrity

**Status: ACCEPTED — engineering decision, 2026-10-31.**
The engineering lead selected **Option A** after reviewing alternatives.

SRS: DATA-01..10, D-017/D-027, ADR-001.

## Decision
- Use `BIGINT UNSIGNED` (Laravel `id()` / `foreignId()`) for internal primary and foreign keys where appropriate.
- Use a separate, immutable, unique **ULID** for public-facing IDs exposed in external routes/APIs/integrations. Do not expose incremental internal primary keys in new public contracts.
- Enforce unique ULID constraints at the relevant table scope, consistently typed composite tenant constraints, and stable version identifiers.
- Tenant-owned foreign references must remain within the same `organization_id`; introduce composite candidate keys and FKs for high-risk cross-tenant references where MySQL allows them.
- Public ULIDs are **not** an authorization mechanism. Continue server-side scoped lookups, IDOR prevention, membership checks and policy enforcement.
- Use restrictive deletion for historical approvals, reviews, immutable versions and evidence; do not cascade-delete audit history.

## Alternatives considered, not selected
- ULID as the primary key across all relevant tables: simplifies distributed identifier allocation but widens relational indexes and FKs.
- UUID primary keys: viable distributed identifiers with different locality/storage tradeoffs.

## Implementation acceptance
- Confirm identifier type consistency and MySQL indexes/FKs with migrations and integration tests.
- Test forbidden cross-organization links, public-ID uniqueness, tenant-constrained lookups and historical-reference integrity.
- Avoid global mass assignment of `organization_id` from untrusted request input.
- D-027 business relationship flexibility remains product-owner controlled; the ID strategy does not decide domain relationship policy.

Only implementation-scoped PRs may adjust tables/models; this ADR does not create migrations.