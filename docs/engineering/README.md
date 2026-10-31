# Engineering governance

## Project
Arabic RTL multi-organization quality/excellence SaaS. Institutional approaches, evidence and human review advance through five maturity stages: **التوجه → النظام → التنفيذ → القياس → التحسين والتطوير**. Eligible saves trigger review automatically; there is no extra submit-for-review button. No invented numeric global maturity score.

## Authoritative references
- [SRS v3 (owner requirement reference)](https://docs.google.com/document/d/1QAPmgGzxfhe_BhBSwsSyCruajVq4unNli-cairQ_mZs/edit).
- [Accepted ADRs](../architecture/foundation.md) — technical decisions only.
- [Current GitHub collaboration security](collaboration-security.md).
- [Decision ledger](../architecture/decision-ledger.md) — what is approved vs what requires owner policy.

Never convert an SRS OPEN business decision into permission by inference.

## Current state
Laravel 13 + PHP 8.4 + Filament 5 and working CI are in `main`. The public repository's `main` has `protected=true`; detailed classic rules cannot be read through the integration. `zeko-create` has Write access. Actual contributor push/review enforcement must be checked in GitHub Settings.

## Contribution workflow
Approved Issue → scoped branch from current `main` → focused code + actual test evidence → permanent report in `docs/engineering/reports/` → PR → review and CI → merge. No arbitrary packages, domain policies or direct main edits.

## Reporting
A task report must state SRS/AC, files, tests/results, DB/security changes, remaining owner decisions, branch, commits and PR. Do not claim a test ran merely because a CI configuration exists. Historical reports may contain past statuses; consult the [current audit](reports/ISSUE-7-ARCHITECTURE-APPROVAL.md) for up-to-date state.