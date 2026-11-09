# Delivery risks

| Risk | Severity/impact | Mitigation / decision | State |
|---|---|---|---|
| Unratified operational authority mistaken for grants | Critical / tenant privilege escalation | D-002 gate; empty registry, real policy default-deny; #50 synthetic only | OPEN |
| Selected local fixture mistaken for owner ratification/file permission | Critical / unauthorized activation | J03_LOCAL_APPROACH_001 TEST_ONLY; explicit scope review without approval. V2 proposal only; no sensitive changes/L4 claim | CONTROLLED |
| Stale canonical wording/ADR004 status vs newer sources | High / incorrect scope or identity assumption | Preserve source history; trace amendment separately, no self-acceptance | OPEN |
| Current owner source identity confused with an unavailable copy | High / settled decisions might be re-asked | User confirmed current available source and reported extracted-text identity; exact available bytes/path/hash recorded privately. Agent comparison provenance stated accurately | RESOLVED FOR CURRENT SOURCE |
| Demo disruption | High / user's active local testing | Preserve process/ports/container/DB and source hashes; no shared protected source edits | MITIGATED FOR CURRENT SCOPE |
| Coworker #37 collision | High / lost parallel work | No edits/duplication; independently reviewed merge required | MITIGATED FOR CURRENT SCOPE |
| Unscanned file or arbitrary MIME/size policy | Critical / private file compromise | No user intake until D-007 adapter/whitelist approved; fail closed | BLOCKED |
| Invented maturity score/scope/reassessment | High / invalid decisions/history | Manual categorical results only; independent invariants; D-003/D-014 remain open | BLOCKED DEPENDENT WORK |
| False full-MVP acceptance by deadline | High / misleading customer handover | L1–L4, all J01–J14, unchecked phases until review/merge/CI/UAT | OPEN |
| Local browser evidence mistaken for normal CI or approved UAT | High / misleading acceptance | Issue53 runner is explicitly local; CI covers safety guards and existing regressions.45 browser assertions prove bounded J01 L3 only; no grants or L4 claim | CONTROLLED |
| Hosting scope creep | Scope violation | Excluded completely; local development/test resources only | CONTROLLED |
