# AI Engineering Instructions — Quality & Excellence Platform

## Authority
- Owner-approved SRS is the source of **business** requirements.
- **Accepted technical ADRs**: [ADR-001](docs/adr/ADR-001-tenant-isolation.md), [ADR-002](docs/adr/ADR-002-identity-authorization.md) and [ADR-003](docs/adr/ADR-003-identifiers-and-integrity.md).
- Consult [decision ledger](docs/architecture/decision-ledger.md) and task Issue before coding. An engineering approval **never** fills in OPEN owner policies.
- Stop and report requirements conflicts. Do not invent roles, permissions, lifecycle rules, maturity percentages, audit outcomes or external integrations.

## Architecture
- Laravel pragmatic Modular Monolith + lightweight DDD. Keep business rules in domain/application Actions/Services, not Filament Resources, controllers or views.
- Filament 5 is installed for administrative presentation; no new packages without explicit authorization.
- Shared MySQL + tenant `organization_id`; global identities support organization memberships; scoped deny-by-default authorization; internal BIGINT + external ULID. Test tenant isolation at service, DB, jobs, files and downloads.

## Security
- No implicit platform-admin access to private tenant content. No user-provided organization ID is trusted for authorization.
- Protect historical approvals/evidence revisions; never expose private documents.
- Do not commit secrets, live tenant data, production dumps or private owner materials to this PUBLIC GitHub repository.

## Workflow
- Never develop directly on `main`. Start from updated `main`, use an Issue-scoped branch, push and open a PR.
- Never self-merge or bypass protection; do not modify CI/permissions/deployment outside the approved Issue.
- GitHub branch metadata confirms `main.protected=true`; exact enforcement needs owner review in GitHub Settings. Text-only `CODEOWNERS` does not itself enforce access.

## Validation and report
- Run actual formatting, automated positive/negative/cross-tenant tests and relevant MySQL constraints, not SQLite tests alone for tenant behavior.
- User-facing UI is Arabic RTL.
- Write `docs/engineering/reports/<ISSUE-ID>.md` with SRS/AC, summary, touched paths, DB/security impact, test results, risks, branch, commits and PR.
- If any test fails, document and fix it; never fabricate success.

## Stop conditions
Unknown owner policy, privilege escalation, cross-tenant risk, unauthorized data deletion/migration, unapproved package, secret access or unexpected scope expansion.