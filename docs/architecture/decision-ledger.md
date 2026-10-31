# Decision ledger — architecture vs product policy

**Checked 2026-10-31.** The engineering lead explicitly accepted ADR-001, ADR-002 and ADR-003. This ledger prevents stale "proposed" labels from being mistaken for unresolved engineering design, and prevents engineering approval from being misread as approval of product-owner business policies.

| Decision | Current state | Authority / consequence |
| --- | --- | --- |
| ADR-001 shared MySQL + organization_id | **ACCEPTED** | Engineering; tenant isolation is mandatory |
| ADR-002 global users + memberships | **ACCEPTED** | Engineering; data model supports multiple organizations; membership grants still governed by product policy |
| ADR-002 deny-by-default scoped access | **ACCEPTED** | Engineering security invariant, not a detailed role matrix |
| ADR-003 internal BIGINT + external ULID | **ACCEPTED** | Engineering |
| Filament 5 administrative shell | **INSTALLED** | Technical infrastructure; not production-access permission |
| D-001 exceptional platform support access | **OWNER POLICY OPEN** | No exceptional access by default |
| D-002 role/permission/delegation matrix | **OWNER POLICY OPEN** | No invented roles or broad privileges |
| D-008 data residency/retention/recovery | **OWNER/COMPLIANCE OPEN** | Do not assume geography or retention periods |
| D-021 multiple memberships | **TECHNICAL CAPABILITY ACCEPTED; BUSINESS RULES OPEN** | Do not grant memberships automatically |
| D-022 self-review/separation of duties | **OWNER POLICY OPEN** | No self-approval rule presumed |
| D-023 suspended organization behavior | **OWNER POLICY OPEN** | No destructive/unreviewed suspension behavior |
| D-024 invitation/reset/MFA channels | **OWNER POLICY OPEN** | No unapproved invitation or bypass implementation |
| D-027 domain relationship flexibility | **OWNER POLICY OPEN** | Define from detailed domain workflow before schema expansion |
| D-010/D-019 UI stack and contract | **PARTIAL** | Filament 5 selected for admin foundation; broader product UI and detailed screen contracts require separate scope |

## Implementation guardrails
- Engineering acceptance permits **design and constrained implementation planning**, not unrestricted domain rollout.
- Every product policy still marked OPEN requires an owner decision or a task that explicitly excludes the unknown behavior.
- Do not silently change the original SRS decision register. If the owner approves business items, amend the authoritative SRS and this ledger together.
- To avoid unnecessary blocking, narrowly scoped infrastructure tasks may proceed without implementing unresolved business logic.
