# Project documentation

## Current references
- [Phase05B-1 institution identity/entry candidate](architecture/institution-identity-entry.md), [proposed ADR amendment](adr/ADR-004-institution-identity-candidate.md) and [implementation report](engineering/reports/32.md).
- [Phase05B-0 identity/authorization gates](architecture/PHASE-05B-0-IDENTITY-AUTHORIZATION-GATES.md), [proposed next entry contract](engineering/PHASE-05B-1-ENTRY-IMPLEMENTATION-CONTRACT.md) and [reconciliation report](engineering/reports/30.md).
- [Phase05A architecture decision gates](architecture/PHASE-05A-ARCHITECTURE-GATES.md).
- [Phase05A gated roadmap](engineering/PHASE-05A-MVP-IMPLEMENTATION-ROADMAP.md) and [reconciliation report](engineering/reports/26.md).
- [Executable tenant-isolation schema contract and reviewed exceptions](architecture/tenant-isolation-schema-contract.md).
- [Phase 04C schema regression engineering report](engineering/reports/PHASE-04C-TENANT-ISOLATION.md).
- [Organization provisioning versus member-scoped metadata](architecture/organization-management-foundation.md).
- [Phase 04B organization management engineering report](engineering/reports/PHASE-04B-ORGANIZATION-MANAGEMENT.md).
- [Internal organizational hierarchy management and concurrency guarantees](architecture/organizational-hierarchy-management.md).
- [Phase 04A hierarchy engineering report](engineering/reports/PHASE-04A-ORGANIZATIONAL-HIERARCHY.md).
- [Technical tenant authorization foundation — no business permission matrix](architecture/tenant-authorization-foundation.md).
- [Phase 03C / 03B-B authorization implementation report](engineering/reports/PHASE-03B-B-RBAC-AUTHORIZATION.md).
- [Architecture foundation and accepted ADRs](architecture/foundation.md).
- [Decision ledger — approved technical choices vs pending owner policy](architecture/decision-ledger.md).
- [Contributor and security posture](engineering/collaboration-security.md).
- [Local development](setup/local-development.md).
- [Windows Herd local setup](setup/herd-windows.md).
- [Phase 03B local readiness evidence](engineering/reports/PHASE-03B-A-HERD-LOCAL-READINESS.md).
- [Proposed authorization decision preview — not owner approved](engineering/PHASE-03B-RBAC-DECISION-PREVIEW.md).
- [Local Redis and independent MySQL/Redis tests](setup/redis-local.md).
- [Phase 03A schema design and authorization boundaries](architecture/tenant-schema-foundation.md).
- [Phase 03A engineering report](engineering/reports/PHASE-03A-TENANT-SCHEMA.md).
- [Phase 01 Laravel bootstrap evidence](engineering/reports/PHASE-01-LARAVEL-BOOTSTRAP.md).
- [Phase 02 Filament evidence](engineering/reports/PHASE-02-FILAMENT-FOUNDATION.md).
- [Architecture approval and repository audit](engineering/reports/ISSUE-7-ARCHITECTURE-APPROVAL.md).

Every implementation task must add a separate permanent report in `docs/engineering/reports/`. Preserve historical reports as evidence; the linked current audit supersedes obsolete status statements.

The SRS is the product requirement source of truth. Engineering ADRs define implementation choices, **not** unspecified business authorization or compliance rules.
