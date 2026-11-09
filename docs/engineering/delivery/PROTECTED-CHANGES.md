# Future protected changes — proposal only

No protected file is changed by #51 or #53. Creating an Issue or this document does not authorize an amendment. NEXT-APPROACH-AUTHORITY-GATE.md distinguishes the minimum required assignment/registry/schema changes from conditional list/locking changes for the next bounded slice.

| Later surface | File-specific reason/risk | Required approval and validation |
|---|---|---|
| docs/adr/ADR-004-institution-identity-candidate.md | Align formal status with independently reviewed PR #33 while preserving ADR002 history | Lead accepts exact documentation diff and source reconciliation; no inferred D-002 grants |
| docs/architecture/decision-ledger.md | Append accurate source/technical status without erasing historical OPEN records | Authorized canonical editor/lead review; no source edit or self-acceptance here |
| app/Policies/ApproachPolicy.php | Replace false assignment predicate only after approved durable assignment contract; privilege/tenant risk | Owner D-002 rows, exact schema/lock design and scoped lead approval; current actor/membership/resource, stale/mid-action revocation, cross-tenant/Livewire tests |
| config/authorization.php | Register only approved capability/resource anchors; broad runtime grant risk | Per-capability approval; no #50 provisional names or platform bypass |
| New additive migration paths and shared schema-test inventory | Durable grants/reviews/publication require actual integrity/rollback plan | Propose exact filenames/constraints once behavior ratified; MySQL DDL-failure/retry/rollback and scoped lead review before writing |
| app/Providers/Filament/InstitutionPanelProvider.php | Mount real modules/navigation; registered runtime access risk | Exact route/middleware/policy diff after approved domain boundaries; #37 integration only after accepted merge; full tests/browser evidence |

This is not an omnibus amendment request. Exact future new migration/UI filenames depend on the owner-approved behavior and must be separately proposed. Neither the accepted invariant subtask nor Issue53 identity verification needs these changes. The existing registered local/testing workspace requires no additional panel/route/guard change merely to exercise that first bounded draft slice after its authority is approved.
