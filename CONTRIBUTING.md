# Contributing — Quality & Excellence Platform

This private repository is governed by the product owner's requirements and approved technical decisions. The primary branch is `main`.

1. Work only from an approved GitHub Issue with scope, allowed paths, SRS/AC references and acceptance tests.
2. Create one short-lived branch per Issue from the latest `main`: `feat/<issue>-short-name`, `fix/<issue>-short-name`, `test/<issue>-short-name`, or `chore/<issue>-short-name`.
3. Implement only the approved scope. If a decision is OPEN/PROPOSED or you need shared/core files outside the contract, stop that part and raise a blocker.
4. Use Laravel application/domain layers for business rules, enforce server-side authorization and tenant isolation, and add automated tests.
5. Run formatter/static analysis/tests. Do not suppress failures.
6. Commit focused Conventional Commits and push your branch only.
7. Add `docs/engineering/reports/<issue>.md`, open Draft PR into `main`, attach tests and risks, request lead review.
8. Never self-merge or push directly to `main`; lead alone approves and merges.

**GitHub safeguard warning:** A private repository may not support branch protection/rulesets without an eligible plan. CODEOWNERS and this text are procedural, not enforcement. Do not invite a collaborator with Write permission until access risks and the protection plan are explicitly resolved.

Original product requirements and the full SRS are maintained in the approved project documentation. No Laravel implementation is authorized by this governance-only PR.
