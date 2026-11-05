# Phase 05A reconciliation plan — Issue #26

Date: 2026-11-05. Starting origin/main: `2382adce5cfdb826d3caffb23ed61bec99fcf0a3`, freshly fetched and verified after PR #25 merged. Branch: docs/26-phase-05a-reconciliation. This plan precedes all other Phase05A tracked edits.

## Objective and evidence

Reconcile authorized private requirements with the actual engineering foundation; identify decision gates for planning the first operational increment. This is documentation only, not Phase05B implementation or production approval.

Canonical requirements, a supplied local reference copy, current owner responses and complete functional/maturity/earlier-planning materials are accessible. The required specifications' page counts were verified. Source names, passages, page-level comparisons, chronology, detailed policy matrices and proposed source amendments stay in the private lead handover, never in tracked files, Issues or PR bodies. The canonical decision register still requires reconciliation; access to a response is not evidence that the canonical source has been amended.

Inventory: AGENTS/CONTRIBUTING/README/docs index; accepted ADR-001/002/003 and ledger; Phase03/04 architecture/reports; all14 current migrations; models, authorization, internal Actions and panel provider; empty capability registry; MySQL schema contract and test guards; isolated suites and unchanged CI. Main's application/test tree equals the reviewed Phase04C tree. Issues #4/#6/#12/#13 remain open; contributor scope is separate.

## Analysis and gates

- Trace received decisions to private sources, distinguish firm requirements, proposals and unresolved details. Do not infer approval from file timestamps or older estimates.
- Review identity/cardinality/login/reset/history compatibility, especially D-021 and ADR-002. Compare alternatives privately; accepted architecture remains unchanged pending lead acceptance of a separately scoped revision.
- Separate a first implementation increment from pilot acceptance. Keep dependency order explicit without inventing dates, percentages, grants, workflow states or future schema counts.
- Review current relational safeguards versus future proposed domain/version/evidence boundaries. No DDL or runtime data access is needed.
- Record precise unanswered questions and responsible decision makers; dependent implementation remains blocked. Source reconciliation can finish with visible unresolved gates.

## Exact file-by-file changes

1. This pre-edit plan.
2. docs/architecture/PHASE-05A-ARCHITECTURE-GATES.md: sanitized status, existing/future boundaries, architecture acceptance gates.
3. docs/engineering/PHASE-05A-MVP-IMPLEMENTATION-ROADMAP.md: high-level gated increments and pilot distinction, no private policy details.
4. docs/engineering/reports/26.md: actual audit, checks, limitations, commits/PR/CI.
5. docs/architecture/decision-ledger.md: received/reconciliation-pending annotation only; preserve accepted ADRs and canonical OPEN entries.
6. AGENTS.md: concise conflict/secure-source and maintainability guardrails.
7. CONTRIBUTING.md: matching short review checklist.
8. docs/README.md: minimal links.

No other tracked paths. Preserve the pre-existing untracked storage artifact. No app/database/config/tests/UI/CI/lockfile/environment/deployment change; no package installation, migration, database cleanup, queue execution, production auth probe, owner-source edit, accepted ADR replacement or merge.

## Validation and delivery

Check complete diff, exact allowlist, relative links and confidentiality. Run Composer validate --strict, Pint --test, existing isolated SQLite tests and read-only route/version inspection where available. Local MySQL/Redis suites and live schema introspection are unnecessary for this docs-only audit and must not be described as fresh local evidence. Existing PR CI can independently verify its disposable services; require successful final-HEAD checks before recommending document review.

Commit/push only this scoped branch, open a sanitized Draft PR, verify remote paths/report and final CI. Deliver detailed reconciliation directly in the private final response. Unresolved identity/authority gates block dependent implementation; no claim of Phase05B or production readiness. Stop for engineering-lead review.
