# Phase05A gated implementation roadmap

Issue [#26](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/26). **PROPOSED sequence, NOT IMPLEMENTED or implementation-approved.** Detailed pilot acceptance and private decision evidence stay in the lead handover. Read the [architecture gates](../architecture/PHASE-05A-ARCHITECTURE-GATES.md) first.

## First increment versus pilot

The smallest end-to-end increment is a repeatable authorized tenant workflow with persisted state, two distinct test identities, a revision-bound review outcome and an auditable result. Account/entry prerequisites must be delivered before that workflow is exposed. Exact role grants, domain state transitions and schema are gated decisions, not defaults created by this roadmap.

One successful workflow proves only that increment. It does not replace the complete owner-required pilot acceptance checklist, authorize dropping later required functions, or turn an earlier estimate/proposal into a delivery commitment. No dates or completion percentages are promised.

| Order | Narrow later Issue / exit evidence | Dependency |
| --- | --- | --- |
| 0 | Reconciled source register, accepted identity option and minimum operational authority matrix; reviewed migration/rollback compatibility plan | G0/G1/G2; owner and lead |
| 1 | Authorized entry/onboarding foundation, explicit tenant resolution and negative login/reset/invitation/session tests; existing production deny remains until reviewed replacement | 0; approved source and identity contract |
| 2 | One small domain revision/read/review path with explicit ownership, immutable candidate, authorized decision and history; repeat after logout/login | 1 and G3; approved schema/action scope |
| 3 | Private versioned evidence and transactional notifications integrated into that path; cross-tenant download/job/revocation/concurrency tests | 2 and relevant G5 controls |
| 4 | Required configurable domain-cycle behavior and historical configuration/review consistency, driven by the private acceptance cases | G4 and earlier slices; no guessed scoring or cascade |
| 5 | Minimal licensed reference/version/element relation and one authorized tenant mapping, preserving old references; mapping alone is not an evaluation outcome | Authorized pilot material and G6 |
| 6 | Full pilot acceptance across the required slices, operator/security review and recovery evidence; independent UI integration review | All relevant gates plus Issues #4/#6/#13 |

These are dependency boundaries, not a mandate for a new framework or a fixed number of PRs. Each later Issue must cite approved acceptance, define permitted paths and meaningful positive/negative/cross-tenant tests, and remain small enough to review. An increment may be split further where a schema or security boundary warrants it.

## Future schema review order

First settle identity/login/reset namespace and historical principal compatibility. Then review tenant ownership/assignment and domain revision keys, followed by private document-version/evidence references and revision-bound review decisions. Configuration/rating history and reference-version mapping follow only after their own gates. Use additive compatible migrations, actual MySQL FK/index/CHECK tests and explicit actor/history preservation; do not rewrite old migrations or prescribe a final table count here.

No full assessment/audit engine, generalized rule/form/workflow engine, reference-transition automation, analytics, improvement-project platform, certification or billing implementation is authorized by this sequence. Future inclusion requires its own approved scope. The repository remains a technical foundation until the gated operational slices are implemented and tested.

## Maintenance and verification

Reuse current context/authorization/services without exposing them as implicit management privileges. Prefer framework capabilities and concrete responsibilities over extra repository interfaces, event buses or speculative modules. Review small changes, update decision evidence with the code, preserve existing regression tests, and verify the actual final PR HEAD.

Engineering references consulted online: Software Engineering at Google, chapters [1](https://abseil.io/resources/swe-book/html/ch01.html) (change over time), [9](https://abseil.io/resources/swe-book/html/ch09.html) (small independent reviews), [10](https://abseil.io/resources/swe-book/html/ch10.html) (documentation in the engineering workflow), [11](https://abseil.io/resources/swe-book/html/ch11.html) (tests supporting safe change), [21](https://abseil.io/resources/swe-book/html/ch21.html) (dependency cost) and [23](https://abseil.io/resources/swe-book/html/ch23.html) (actual integration evidence). These are engineering references, never authority for business requirements. Other named books were not supplied in the inspected source bundle and are not claimed as reviewed.
