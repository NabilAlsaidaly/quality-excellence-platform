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

## Phase05A reconciliation checkpoint — 2026-11-05

**RECEIVED / SRS RECONCILIATION PENDING:** authorized private decision evidence has been reviewed under Issue #26. The table above preserves the canonical register's historical OPEN statuses; it is not a claim that no later responses exist. The canonical source has not been edited by this task. Detailed rules, source names and amendments remain in the private lead handover.

D-021 / ADR-002 compatibility and D-002 authority require an explicit implementation gate. D-003/D-014 and remaining context-specific details stay unresolved where no complete decision exists. Receipt is neither acceptance of a new architecture nor an operational grant. Accepted ADR-001/002/003 remain unchanged; dependent implementation requires reconciled source authority and lead acceptance of any technical revision. See [Phase05A gates](PHASE-05A-ARCHITECTURE-GATES.md).

## Phase05B-0 reconciliation checkpoint — 2026-11-05

**ANALYSIS COMPLETE / IMPLEMENTATION GATED:** Issue [#30](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/30) checked actual source contents/revision and current implementation. Additional D-021 clarification was received; do not reopen answered functional choices. G1 now requires source reconciliation and engineering acceptance of the complete compatibility design. A detailed D-002 proposal was explicitly supplied as **PROPOSED, not final owner approval**; its grants remain unavailable pending ratification. D-013 has received user-visible requirements; its concurrency mechanism is proposed engineering work, not a repeated owner question. D-003/D-014 remain deferred to their dependent module.

The historical table and accepted ADRs above are preserved. No canonical source amendment, accepted replacement ADR, operational permission or implementation is claimed. Detailed policies/alternatives/amendments remain private. The current [Phase05B-0 gates](PHASE-05B-0-IDENTITY-AUTHORIZATION-GATES.md) and [proposed next contract](../engineering/PHASE-05B-1-ENTRY-IMPLEMENTATION-CONTRACT.md) refine the planning checkpoint without authorizing code. The prior dependency blocker was resolved separately by merged PR #29 and is verified clean again; historical Phase05A failure text is not current gate evidence.

## Phase05B-1 candidate implementation — 2026-11-05

Issue [#32](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/32) follows an explicit later authorization to build/test the bounded identity-entry candidate, superseding the earlier planning-only stop for that slice. [ADR-004](../adr/ADR-004-institution-identity-candidate.md) proposes the limited ADR-002 identity amendment; it is **NOT ACCEPTED**. Received D-021 semantics guide the candidate; canonical-source amendment is not claimed. D-002 detailed operational authority remains unratified and no management capability is introduced. G0 source reconciliation, G1 independent technical acceptance and G2 approval of any future grants remain separate merge/release gates. [Implementation/report](../engineering/reports/32.md); [technical boundary](institution-identity-entry.md). No production/staging opening or deployment.
