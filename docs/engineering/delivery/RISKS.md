# Delivery risks

| Risk | Severity/impact | Mitigation / decision | State |
|---|---|---|---|
| Unratified operational authority mistaken for grants | Critical / tenant privilege escalation | D-002 gate; empty registry, real policy default-deny; #50 synthetic only | OPEN |
| Stale canonical wording/ADR004 status vs newer sources | High / incorrect scope or identity assumption | Preserve source history; trace amendment separately, no self-acceptance | OPEN |
| Latest private source version not independently located | High / decisions might be re-asked or misquoted | Distinguish located candidates from exact latest reference; use independent evidence only | UNDER_REVIEW |
| Demo disruption | High / user's active local testing | Preserve process/ports/container/DB and source hashes; no shared protected source edits | MITIGATED FOR CURRENT SCOPE |
| Coworker #37 collision | High / lost parallel work | No edits/duplication; independently reviewed merge required | MITIGATED FOR CURRENT SCOPE |
| Unscanned file or arbitrary MIME/size policy | Critical / private file compromise | No user intake until D-007 adapter/whitelist approved; fail closed | BLOCKED |
| Invented maturity score/scope/reassessment | High / invalid decisions/history | Manual categorical results only; independent invariants; D-003/D-014 remain open | BLOCKED DEPENDENT WORK |
| False full-MVP acceptance by deadline | High / misleading customer handover | L1–L4, all J01–J14, unchecked phases until review/merge/CI/UAT | OPEN |
| Hosting scope creep | Scope violation | Excluded completely; local development/test resources only | CONTROLLED |
