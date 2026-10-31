# Issue #7 — Architecture gate (historical drafting record)

**Historical snapshot:** Original draft prepared 2026-10-31 while decisions were still PROPOSED and GitHub was private/unprotected. The state described in that initial report has since changed. The engineering lead accepted ADR-001/ADR-002/ADR-003 on 2026-10-31 and repository `main` is now protected. The current factual record is [ISSUE-7-ARCHITECTURE-APPROVAL.md](ISSUE-7-ARCHITECTURE-APPROVAL.md); use that report and the [decision ledger](../../architecture/decision-ledger.md) rather than treating this draft's historical status as current.

## Original scope
- Draft tenant isolation, identity and authorization architecture, and identifier alternatives.
- Document collaboration/protection risks and product-owner policy questions.
- No business migrations, roles or other application implementation.

## Original non-decisions
Support access, full role matrix, retention, self-review, suspension, invitations/MFA and domain relationship policies still require product-owner decisions. Original documentation-only PR was merged as #8; subsequent engineering acceptance is recorded separately.

## Validation history
Earlier branch metadata indicated `main.protected=false` while the repo was private. The owner later made the repo public and enabled branch protection. As of the updated audit, branch metadata reports `protected=true`, but the GitHub integration cannot inspect detailed protection rules (403).