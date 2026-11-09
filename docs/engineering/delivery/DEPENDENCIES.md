# Dependency graph and independent work

```mermaid
flowchart LR
 P0[Source and decision trace] --> P1[Approved durable authority]
 P1 --> P3[Scanned private documents]
 P1 --> P4[Review and publication]
 P3 --> P5[Contextual evidence]
 P4 --> P5
 P4 --> P6[In-app notifications]
 P5 --> P7[Full five-stage maturity]
 P1 --> P8[Licensed versioned mappings]
 P5 --> P8
 P2[Reviewed coworker37 integration] --> P9[Unified native Filament]
 P7 --> P9
 P8 --> P9
 P6 --> P9
 P9 --> P10[Local customer UAT]
```

Accepted independent subtask #51: immutable stage vocabulary/category range consistency, merged PR52 with exact-main CI. It advances P7 internally but completes neither P7 nor J08/J09. Coverage-gap UI/precision semantics and persistent version snapshots remain separate reviewed work.

Current independent subtask #53: real J01 identity browser-to-MySQL verification,45 browser assertions and persisted readback, no roles/permissions or Policy substitution. L3 for the existing candidate is verified locally; independent review, exact-head CI and owner/source acceptance remain distinct. It completes no overall phase.

Next highest-value operational slice: bounded owner-ratified Approach entry/list/create/edit/history authority and durable assignments, then separately approved review/publication. See NEXT-APPROACH-AUTHORITY-GATE.md for the minimum issuer/resource/lifecycle rows and required versus conditional protected/schema approvals. P3 independently needs D-007. #37 must not be duplicated or mounted before reviewed merge. PROTECTED-CHANGES lists dependency surfaces, not authorization.
