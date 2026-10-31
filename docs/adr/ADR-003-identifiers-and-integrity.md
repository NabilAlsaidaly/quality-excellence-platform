# ADR-003 — Identifier types and relational integrity
Status: **PROPOSED — lead decision required**, 2026-10-31. Issue #7.
SRS: DATA-01..10, D-017/D-027, ADR-001.

## Options
A. BIGINT UNSIGNED auto-increment internal relational keys + ULID public identifiers (recommended for evaluation). Benefits: compact relational indexes/FKs; public IDs do not expose sequential internal IDs; cost: two identifiers, explicit API lookup and uniqueness.
B. ULID primary keys across relevant tables. Benefits: uniform identifiers and distributed ID generation; cost: wider relational indexes and complex composite constraints.
C. UUID primary keys. Similar distributed generation, differing locality/size properties.

## Suggested evaluation criteria
- MySQL 8.x storage/index and FK costs, portability, ergonomics with Laravel conventions.
- Composite `(organization_id, id)` candidate keys and matching multi-column foreign keys where relationships cross tenant-owned tables.
- Type consistency, controlled public identifiers, IDOR tests, and human-readable log correlation.
- Historical revision stability, migration reversibility, backup/restore and benchmarked query plans.
- Do not assume ULID is authorization: every ID lookup still needs membership and policy checks.

## Invariants
- A tenant entity references only records of the same organization.
- Join/link tables include organization boundary for critical relationships, with DB constraints where possible.
- `organization_id` may be absent only on explicitly approved global tables.
- No destructive cascade of historical approvals or versions.

Do not modify framework `users` schema or choose an ID type in code until the ADR is accepted.
