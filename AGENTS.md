# AI Engineering Instructions — Quality & Excellence Platform

## Authority
- Product-owner-approved requirements are the business source of truth.
- Consult relevant SRS sections, ADRs, and the specific GitHub Issue before coding.
- SOURCE means source-derived, not approval of unspecified details. OPEN and PROPOSED are **not** approved instructions.
- Raise conflicts and blockers instead of inventing rules, permissions, status transitions, maturity percentages, or scope.

## Architecture
- Laravel pragmatic Modular Monolith with light DDD.
- Keep business logic in application actions/services/domain rules—not Filament resources, controllers or views.
- Avoid God Classes, unnecessary abstractions, duplicate services, and speculative dependencies.

## Security
- Enforce tenant isolation and authorization in the backend and cross-tenant relationships, jobs, storage, downloads, and tests.
- Keep documents private and historical revisions immutable.
- Never commit secrets, credentials, production dumps, or personal data.

## Git and team workflow
- Never work directly on main/master; create a short-lived task branch from the updated default branch.
- Scope changes to approved paths and requirements of the assigned Issue.
- Never merge your own PR, force-push protected branches, modify repository rules, secrets, deployment or CI without explicit authorization.
- Open a Draft PR and request lead review. Branch protection must be configured in GitHub; this file is not a technical access control.
- If technical branch protection is unavailable for a private repo, flag the risk before adding Write collaborators.

## Quality gates
- Inspect existing code; choose the smallest correct design.
- Test relevant positive, negative, authorization, cross-tenant, concurrency, and regression paths.
- Run actual formatting, static analysis, and automated tests. Report exact command results; never claim tests passed without evidence.
- Arabic RTL for user-facing screens and messages.

## Mandatory handover
Write `docs/engineering/reports/<ISSUE-ID>.md` with requirements/acceptance IDs, changes, touched files, DB impacts, security, test commands/results, risks, branch, commits, and PR URL.
- If operations fail, report failures accurately.
- Reduce tokens by selective reading and concise outputs, **never** by dropping tests or safeguards.

## Stop conditions
Unapproved business decisions, cross-tenant risk, destructive DB changes, unsafe credential access, scope expansion, and undocumented failing tests must be escalated.
