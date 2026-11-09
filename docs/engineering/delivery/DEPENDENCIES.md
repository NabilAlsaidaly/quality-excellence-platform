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

Current independent subtask: source-confirmed immutable stage vocabulary and category range consistency. No DB, authorization, scale placement, workflow state or reassessment rule. Preserve configured order; variable category count; reject nonfinite/reversed/out-of0–100/overlapping bounds. No midpoint, score, average, automatic rating or unlock. It advances P7 internally but completes neither P7 nor J08/J09. Coverage-gap UI/precision semantics and persistent version snapshots remain separate reviewed work.

Next highest-value operational slice: minimal owner-ratified D-002 bootstrap/Approach grants then review/publication, requiring protected/schema approval. P3 independently needs D-007. #37 must not be duplicated or mounted before reviewed merge. See PROTECTED-CHANGES for dependency surfaces, not authorization.
