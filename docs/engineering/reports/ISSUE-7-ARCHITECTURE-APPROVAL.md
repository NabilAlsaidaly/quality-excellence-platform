# Issue #7 — Architecture acceptance and repository reconciliation

Date: 2026-10-31
Status: **Engineering acceptance documented; GitHub PR/CI and security details verified separately.**

## Executive summary
The engineering lead explicitly approved the three recommended technical architectures in the project conversation:
1. Shared MySQL tenant storage with mandatory `organization_id` and multilayer tenant enforcement (ADR-001).
2. Global users and organization memberships (including technical support for multiple memberships), scoped deny-by-default Policies/Actions with no automatic platform-admin access to tenant content (ADR-002).
3. Internal `BIGINT UNSIGNED` relation identifiers plus immutable public ULIDs (ADR-003).

This does **not** decide the SRS owner policy items D-001/D-002/D-008/D-022/D-023/D-024/D-027. Business rules remain owner-controlled; consult `docs/architecture/decision-ledger.md`.

## Repository verification on 2026-10-31
- Repository visibility: **public**.
- Main branch: **protected=true** (branch metadata; detailed rules endpoint inaccessible to GitHub integration).
- Contributor `zeko-create`: **write** access verified.
- PR #2 governance, PR #5 Laravel bootstrap, PR #9 Filament 5, PR #8 proposed architectural docs: all merged into `main`.
- Latest pre-change `main` head: `9767ee50af55f80b28fb115afce93621f5cf356b`.
- Pre-change `Foundation CI` on this head: **completed/success**, run https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41223371087.
- No open PR at audit start. Historical task branches remain but are not current development bases.
- `/admin` and `/admin/login` exist; application-level production authorization not implemented.

## Reconciliation performed
- Converted three ADRs from PROPOSED to ACCEPTED technical decisions and clarified exact authority.
- Updated stale repository visibility, protection and collaboration guidance in root README, docs and contributor/agent instructions.
- Explicitly indexed installed Filament and its limitations.
- Captured a decision ledger distinguishing approved technical choices from unresolved product-owner decisions.
- Retained historical engineering reports as evidence and marked obsolete gate status as historical.
- Prepared bounded next implementation and UI issues without claiming those modules have been delivered.

## Security caveats
- GitHub API cannot inspect individual classic branch-protection settings due to 403. Do not claim the required reviewers/CI/bypass options are fully verified.
- No direct-push rejection test from the contributor account has been recorded.
- No domain-level tenancy/RBAC or protected production admin is implemented. This must be built and tested in a future scoped task.
- Public repo requires strict secret and private-document hygiene.

## Testing
This reconciliation changes documentation and the foundation CI configuration only, and does not implement business features. CI confirmation for its own PR must be inspected before merge. Prior main CI success is not evidence of a new PR's success.