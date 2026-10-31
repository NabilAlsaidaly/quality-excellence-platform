# Architecture foundation

## Accepted foundation
Laravel 13 / PHP 8.4, Filament 5 administrative panel and a pragmatic **modular monolith with lightweight DDD**, one application and one deployment unit. Business rules belong in application Actions/Services and domain rules, **not** Filament Resources, controllers or views. Favor framework conventions and the smallest testable design before adding abstractions.

Conceptual flow: Presentation → Application → Domain → Infrastructure. Keep modules cohesive; do not introduce speculative repositories, CQRS, event buses or microservices.

## Accepted cross-cutting ADRs (2026-10-31)
- [ADR-001](../adr/ADR-001-tenant-isolation.md): shared MySQL 8.x schema, `organizations` root and tenant `organization_id` enforcement in database and application.
- [ADR-002](../adr/ADR-002-identity-authorization.md): global identity; multiple organization memberships supported; organization/assignment-scoped deny-by-default authorization; separate platform and tenant capability.
- [ADR-003](../adr/ADR-003-identifiers-and-integrity.md): internal `BIGINT UNSIGNED` PK/FKs and public immutable ULIDs.

These are **engineering approvals**, not silent approval of unresolved product-owner rules. Implement only against explicit acceptance criteria and with negative cross-tenant tests.

## Implementation status
The repository contains Laravel bootstrap, PHPUnit/Pint/CI, Filament 5 and its `/admin` panel. Phase 03A adds structural organizations, memberships and hierarchical units plus Redis cache/queue infrastructure; see [schema design](tenant-schema-foundation.md). **Tenant context/authorization, custom RBAC, document/evidence domain and workflows remain unimplemented.** Built-in users/cache/jobs migrations are preserved; no production administrator access policy is in place.

## Business and compliance policy still requiring product-owner decisions
D-001 support access, D-002 role matrix/delegation, D-008 location/retention, D-022 self-review/separation of duties, D-023 suspension, D-024 invitation/reset/MFA, and D-027 domain relationship flexibility. These cannot be invented as part of a technical implementation. The data model's capacity for multi-org membership does not itself grant membership.

## Quality controls
Use MySQL-specific migration/constraint tests as domain schema evolves; SQLite bootstrap tests alone cannot prove tenant integrity. Preserve immutable historical versions. Keep private evidence private. Record changes and evidence in `docs/engineering/reports/`.
