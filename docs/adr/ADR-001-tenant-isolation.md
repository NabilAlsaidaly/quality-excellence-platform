# ADR-001 — Tenant isolation strategy

**Status: ACCEPTED — engineering decision, 2026-10-31.**
Approved by the project engineering lead in the project conversation. This is a technical architecture approval, **not** approval of unspecified business, legal, support or access policies.

SRS references: BR-001, BR-002, DATA-01..10, D-001, D-008, D-017.

## Decision
Use **one shared MySQL 8.x database**, an explicit `organizations` root, and `organization_id` on all tenant-owned records. Global tables must be explicitly documented in an allowlist. No user-supplied organization identifier is trusted without authorized active-membership resolution.

- Use tenant-constrained reads, writes, downloads, searches, reports and service/policy authorization; Eloquent global scopes alone are not security controls.
- Include active organization and actor context explicitly in jobs, then revalidate membership and permissions when processing.
- Use database-level composite unique constraints and composite foreign keys incorporating `organization_id` for critical tenant-owned relationships when feasible.
- Disallow cross-organization references, including approach → organizational unit, approach → document/evidence/version and link tables.
- Keep tenant uploads private; authorize every file download. Preserve historical evidence, reviews, approvals and revisions: no destructive cascade.
- Platform administration is **not** implicitly authorized to read private organization content.
- Require negative two-organization integration tests, including crafted IDs, forged context, tenant-bound joins, revoked membership and queued access.

## Considered alternatives
Separate databases (stronger physical isolation but greater provisioning/backup/migration overhead); separate MySQL schemas (not independent PostgreSQL-style namespaces). These are not the selected default.

## Mandatory implementation guardrails
First design organization-aware unique/index/FK relationships and current-context resolution, then write tests against **MySQL** as well as isolated framework tests. Any exception to shared storage or the tenant-owned table rule requires a new reviewed ADR. Do not invent production data retention, jurisdiction, restoration or exceptional support access policies; those are business/compliance decisions.

## Decisions intentionally outside this ADR
D-001 exceptional support access, D-008 data residence/retention, and operational recovery requirements remain open to the product owner. A shared schema does **not** authorize cross-tenant content access.