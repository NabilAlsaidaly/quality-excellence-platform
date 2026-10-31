# Contributing — Quality & Excellence Platform

This is a **public** repository, but repository Write permission does not authorize changes to the protected `main` branch. Product-owner-approved requirements are the business source of truth; accepted engineering ADRs govern technical implementation only.

1. Work only from a GitHub Issue defining SRS/AC references, permitted paths, prohibited paths and validation.
2. Create a short-lived branch from the **latest `main`**: `feat/<issue>-...`, `fix/<issue>-...`, `docs/<issue>-...` or `chore/<issue>-...`.
3. Follow [accepted ADRs](docs/architecture/foundation.md) and [decision ledger](docs/architecture/decision-ledger.md). Do not invent roles, maturity scores, access bypass, products, storage policy or new dependencies.
4. Enforce tenant isolation and authorization server-side. Keep business rules in Actions/Services/Domain, not Filament Resources, controllers or client code.
5. Run formatting/tests and record **actual** outcomes. Add a report in `docs/engineering/reports/<issue>.md`.
6. Open a PR targeting `main`; request review. Do not force-push, edit GitHub rules or self-merge.
7. Never commit secrets, private documents, real tenant records or production database exports to the **public** repository.

**GitHub rule visibility:** `main.protected=true` is verified; individual classic-branch protection controls are not readable by this integration. Owner must verify required CI/review and contributor-side denial of direct pushes via Settings, per [security checklist](docs/engineering/collaboration-security.md).

No contributor has inherent access to any tenant's institutional evidence.