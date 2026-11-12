# Phase 06 — Institution Organization UI Implementation Plan

**Issue:** #37 — Institution and Organizational Units UI\
**Branch:** `feat/37-institution-org-units`\
**Required base SHA:** `4f996e95d65d28a5aa6f06ff302ce2a45ced4eed`\
**Contract date:** 7 November 2026\
**Target delivery:** 12 November 2026\
**Status:** Corrected plan — pending validation and dedicated plan commit\
**Authority:** Issue #37 detailed engineering contract

---

## 1. Purpose

Implement real Arabic RTL interfaces for the current institution metadata and its organizational-unit hierarchy.

The interface must support:
- Displaying the authorized institution metadata.
- Displaying the actual organizational-unit tree.
- Creating an organizational unit.
- Renaming an organizational unit.
- Moving an organizational unit within the same institution.

All reads and writes must use the existing Laravel application services and the verified tenant authorization boundary.

This is a limited local/testing candidate. It is not authorization for production activation or permission grants.

## 2. Governing References

The implementation must comply with:

1. Issue #37 and its detailed engineering contract dated 7 November 2026.
2. `AGENTS.md`, `CONTRIBUTING.md`, and `README.md`.
3. Phase 04A, Phase 04B, Phase 04C, and Issue #4 engineering reports.
4. Accepted ADR-001, ADR-002, and ADR-003.
5. ADR-004 only as a proposed decision; it is not implementation authority.
6. The architecture documents for organization management, organizational hierarchy, and tenant authorization.
7. Existing service implementations and their tests.

The contract is the scope ceiling. If a live repository detail conflicts with an assumption in this plan, record the discrepancy and request the smallest necessary project-lead decision. Do not silently broaden scope.

## 3. Branch and Baseline

Required branch:

`feat/37-institution-org-units`

Required contract base SHA:

`4f996e95d65d28a5aa6f06ff302ce2a45ced4eed`

The branch was previously verified at the contract base. The current `origin/main` discrepancy was already identified during preparation.

No merge, rebase, reset, cherry-pick, or branch recreation is authorized to resolve that discrepancy without an explicit project-lead decision.

The latest observed working-tree state before this plan correction was:

- Correct task branch checked out.
- No tracked modifications.
- Three untracked paths:
  - `app/Presenters/`
  - `tests/Feature/InstitutionOrganizationOperationsTest.php`
  - `docs/engineering/PHASE-06-ORGANIZATION-UI-IMPLEMENTATION-PLAN.md`

The Presenter currently exists under a path that does not match the contract. It must be moved to the approved path during Phase 06.1.

### 3.1 Procedural deviation

The contract requires a plan commit before implementation. The Presenter and its initial Feature test were created before a dedicated plan commit was made.

This sequencing requirement was therefore not met retrospectively. The deviation must not be concealed or described as if the plan commit preceded the implementation.

Corrective action:

1. Correct and validate this plan.
2. Commit this plan as a dedicated commit before any further application-code changes.
3. Record the actual commit chronology in the final engineering report.

No further application-code modification is authorized until the corrected plan is committed.

## 4. Scope

### 4.1 Included

- Institution overview using authorized current metadata.
- Actual organizational-unit tree.
- Unit creation.
- Unit renaming without implicit parent changes.
- Unit movement within the same institution.
- Arabic RTL Blade views and translations.
- Empty, validation, denial, and unavailable states.
- Public tree presentation that excludes internal database identifiers.
- A restricted application boundary delegating to existing services.
- Feature and guarded MySQL integration tests.
- The implementation plan and permanent engineering report.

### 4.2 Explicitly excluded

- Institution creation or provisioning.
- Initial administrator creation.
- Invitations and membership administration.
- Role or permission administration.
- New production capabilities, roles, or grants.
- Authentication, guard, session, or identity changes.
- Application route registration.
- Database, migration, schema, or model changes.
- Unit description, status, code, ordering, depth limits, deletion, archive, or restore.
- Approach, documents, maturity, reviews, and unrelated business workflows.
- Production activation.

## 5. Existing Services and Read-Only Boundaries

The following existing components must not be modified by this issue:

- `app/Actions/Organizations/OrganizationManagement.php`
- `app/Actions/Organizations/OrganizationProvisioning.php`
- `app/Actions/OrganizationalUnits/OrganizationalUnitManagement.php`
- `app/Authorization/TenantContext.php`
- `app/Authorization/TenantAuthorization.php`
- `app/Authorization/CapabilityRegistry.php`
- `app/Authorization/TenantTeamResolver.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Middleware/InstitutionSession.php`
- `app/Models/Organization.php`
- `app/Models/OrganizationalUnit.php`
- `app/Models/OrganizationMembership.php`
- `app/Models/User.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `routes/web.php`
- `config/auth.php`
- `config/authorization.php`
- All migrations and schema definitions.

Usage rules:

- `OrganizationManagement::metadata` is the existing read-only metadata source.
- `OrganizationProvisioning` must never be invoked by this interface.
- `OrganizationalUnitManagement` is the existing business-operation service for tree, create, rename, and move.
- Do not duplicate service validation, locking, tenant-scoping, cycle detection, or audit behavior.
- Do not perform direct SQL writes for unit operations.
- The organization boundary must come from the verified `TenantContext` associated with the trusted actor.
- Do not trust browser-supplied `organization_id`, `user_id`, or numeric `parent_id`.
- Use the existing tenant authorization and Gate integration. Do not substitute raw Spatie role or permission checks.

## 6. Application Architecture

The application boundary is:

`app/Actions/OrganizationalUnits/AuthorizedUnitOperations.php`

It is responsible for `readMetadata`, `tree`, `create`, `rename`, and `move`.

### 6.1 Read flow

Browser\
-> Form Request / trusted request context\
-> thin Controller\
-> `AuthorizedUnitOperations`\
-> existing tenant context and Gate / authorization boundary\
-> `OrganizationManagement::metadata` for institution metadata, or `OrganizationalUnitManagement::tree` for the unit tree\
-> `OrganizationalUnitTreePresenter` for tree output\
-> Blade view

### 6.2 Write flow

Browser\
-> Form Request\
-> thin Controller\
-> `AuthorizedUnitOperations`\
-> trusted actor and verified tenant context\
-> existing Gate / tenant authorization boundary\
-> existing `OrganizationalUnitManagement` create, rename, or move operation\
-> existing transaction, locking, and audit behavior\
-> redirect / response using public identifiers only

Controllers must not implement business rules, ownership decisions, authorization decisions, or direct persistence.

Blade views must only render prepared data and validation feedback.

## 7. Authorized Application Boundary

New file:

`app/Actions/OrganizationalUnits/AuthorizedUnitOperations.php`

Required operations:

- `readMetadata`
- `tree`
- `create`
- `rename`
- `move`

Responsibilities:

- Obtain the actor from the trusted authentication/request context.
- Require a verified tenant context associated with that actor.
- Enforce the existing Gate / tenant authorization boundary.
- Deny by default if the required capability is missing or unrecognized.
- Delegate to existing services only.
- Never implement hierarchy algorithms or direct database writes.
- Never trust numeric or tenant identifiers from browser input.
- Avoid relying on stale, previously loaded membership relationships.

The contract's capability identifiers:

- `organization.units.view`
- `organization.units.manage`

may be used only in isolated test fixtures. They must not be added to permanent configuration or the production capability registry. No role or grant may be assigned.

If this boundary requires a new or changed Gate registration, policy, capability registry entry, guard, provider, session behavior, or shared lock-order behavior, stop that integration portion and send a Blocking Integration Request to the project lead. Continue only independent work that remains safe.

## 8. Presenter and Public Tree Contract

Required path:

`app/Support/Institution/OrganizationalUnitTreePresenter.php`

Required namespace:

`App\Support\Institution`

Responsibilities:

- Convert the existing service tree output into the public presentation structure.
- Preserve the roots list and parent-child relationships.
- Preserve ULIDs as public identifiers.
- Remove internal `id` and `parent_id` fields from public output.
- Avoid additional database queries.
- Add no unsupported business fields or semantics.

Expected structure:

- `roots`: list of unit ULIDs.
- `nodes`: map keyed by each unit ULID.
- Each node contains only:
  - `public_id`
  - `name`
  - `parent_public_id`
  - `children`

No numeric database identifier may appear in public DTOs, rendered HTML, public JSON, URLs, or user-facing logs.

The existing tree service returns an internal `id` for application use. Its existing output must not be modified. The Presenter is responsible for projecting only the approved public fields.

Presenter tests must verify:
- Exact public structure.
- Empty tree behavior.
- No `id` or `parent_id` key in serialized nested JSON.
- The fixture reflects the actual service output contract.

## 9. Views and Localization

New views:

- `resources/views/institution/organization/overview.blade.php`
- `resources/views/institution/organization/units/index.blade.php`
- `resources/views/institution/organization/units/create.blade.php`
- `resources/views/institution/organization/units/edit.blade.php`

New translation file:

- `lang/ar/institution_organization.php`

Requirements:

- Arabic RTL presentation.
- Actual institution metadata only.
- Actual organizational-unit hierarchy only.
- Explicit empty, error, denied, unavailable, and validation states as applicable.
- Only permitted unit fields and operations.
- Parent selection must use public ULIDs.
- Rename and move must remain distinct operations.
- Escape user-provided values using normal Blade escaping.
- Views must not make authorization or ownership decisions.
- Do not invent sample business data or fake successful states.
- Do not modify existing Filament preview screens or shared `quality-ui` components.

## 10. Form Requests

New files:

- `app/Http/Requests/Institution/StoreOrganizationalUnitRequest.php`
- `app/Http/Requests/Institution/UpdateOrganizationalUnitRequest.php`
- `app/Http/Requests/Institution/MoveOrganizationalUnitRequest.php`

Responsibilities:

**StoreOrganizationalUnitRequest**
- Validate `name`.
- Validate optional nullable `parent_public_id` as a public ULID.
- Validate permitted type and length constraints.
- Reject unsupported fields.

**UpdateOrganizationalUnitRequest**
- Validate `name` only.
- Renaming must not implicitly move the unit.
- Reject unsupported fields.

**MoveOrganizationalUnitRequest**
- Validate nullable `parent_public_id` as a public ULID.
- Do not decide tenant ownership or cycle validity in validation.
- Those decisions remain within the trusted application/service boundary.

Use the existing service's actual validation semantics. Do not invent new business rules.

## 11. Controllers

New files:

- `app/Http/Controllers/Institution/OrganizationOverviewController.php`
- `app/Http/Controllers/Institution/OrganizationalUnitController.php`

`OrganizationOverviewController` must expose `index` only.

`OrganizationalUnitController` must provide the HTTP adapters:

- `index`
- `create`
- `store`
- `edit`
- `update`
- `move`

Controllers must:

- Obtain actor identity from trusted request context.
- Pass validated data to the application boundary.
- Return prepared views or safe redirects.
- Use public identifiers only.
- Contain no SQL, direct model mutation, hierarchy algorithm, or independent authorization decision.

## 12. Route Integration

No production/application route may be registered by this issue.

Route ownership belongs to the project lead in a separate integration issue.

The proposed integration endpoints are:

| Route name | Method | Path |
|---|---|---|
| `institution.organization.overview` | GET | `/institution/{organization}/overview` |
| `institution.units.index` | GET | `/institution/{organization}/units` |
| `institution.units.create` | GET | `/institution/{organization}/units/create` |
| `institution.units.store` | POST | `/institution/{organization}/units` |
| `institution.units.edit` | GET | `/institution/{organization}/units/{unit}/edit` |
| `institution.units.update` | PATCH | `/institution/{organization}/units/{unit}/edit` |
| `institution.units.move` | POST | `/institution/{organization}/units/{unit}/move` |

The project lead must register these through the approved institution session/middleware and Gate integration and determine the allowed local/testing/production availability.

Test-only routes may be registered inside PHPUnit when needed, but must not become application routes or guest-access workarounds.

## 13. Approved File Inventory

Only these new paths are allowed:

1. `app/Actions/OrganizationalUnits/AuthorizedUnitOperations.php`
2. `app/Support/Institution/OrganizationalUnitTreePresenter.php`
3. `app/Http/Controllers/Institution/OrganizationOverviewController.php`
4. `app/Http/Controllers/Institution/OrganizationalUnitController.php`
5. `app/Http/Requests/Institution/StoreOrganizationalUnitRequest.php`
6. `app/Http/Requests/Institution/UpdateOrganizationalUnitRequest.php`
7. `app/Http/Requests/Institution/MoveOrganizationalUnitRequest.php`
8. `resources/views/institution/organization/overview.blade.php`
9. `resources/views/institution/organization/units/index.blade.php`
10. `resources/views/institution/organization/units/create.blade.php`
11. `resources/views/institution/organization/units/edit.blade.php`
12. `lang/ar/institution_organization.php`
13. `tests/Feature/InstitutionOrganizationOperationsTest.php`
14. `tests/Integration/MySql/InstitutionOrganizationOperationsTest.php`
15. `docs/engineering/PHASE-06-ORGANIZATION-UI-IMPLEMENTATION-PLAN.md`
16. `docs/engineering/reports/37.md`

No additional path may be created or modified without checking the contract and obtaining project-lead approval where required.

The currently existing Presenter under `app/Presenters/Institution/` is not an approved final path. Its implementation must be moved into the approved `app/Support/Institution/` location; do not retain two competing Presenter classes.

## 14. Mandatory Acceptance Criteria

The following acceptance criteria are required and must be represented in the Feature/MySQL test strategy as appropriate.

| ID | Acceptance criterion |
|---|---|
| T01 | Show only institution name and public identifier to an authenticated actor in the correct verified context. |
| T02 | Prevent access to institution B by changing the URI while operating in institution A. |
| T03 | Default deny when the capability registry is empty; positive authorization only through isolated PHPUnit fixtures. |
| T04 | Display a root and multiple children in the existing order, without numeric IDs. |
| T05 | Create a root and child through the existing service with the correct history event. |
| T06 | Rename without changing parent; a no-op must not create a history event. |
| T07 | Move to a valid parent in the same institution and record the transition. |
| T08 | Reject moving a unit to itself, one of its descendants, or creating a multi-level cycle. |
| T09 | Reject a parent from another institution without disclosing that parent's name. |
| T10 | Reject malformed ULIDs and unsupported/sensitive numeric fields. |
| T11 | Enforce the existing service's Arabic, length, whitespace-only, and UTF-8 validation behavior. |
| T12 | Deny a user whose membership was revoked between requests; do not rely on stale loaded Eloquent relationships. |
| T13 | Deny legacy-guard users and platform accounts from institution-context access. |
| T14 | Do not alter institution A data during an institution B operation, even if names match. |
| T15 | Transaction failure must leave no partial state or partial history event. |
| T16 | The original preview remains a preview; this PR adds no production route or panel access. |
| T17 | Test Gate-to-write revocation/concurrency; if it cannot be guaranteed within the approved files, record a blocker for the project lead. |
| T18 | Preserve and pass all existing main tests, MySQL/Redis suites, and build checks without weakening existing tests. |

## 15. Implementation Sequence and Phase Gates

The following sequence follows contract section 9. Each phase must be reviewed before starting the next.

### Preparation gate

- Verify origin, branch, required base SHA, and working-tree safety.
- Read the contract's required references.
- Confirm the public tree DTO from the existing service.
- Correct and commit this plan before further application-code changes.

The preparation review has been performed. The plan commit is still pending.

### Phase 06.1 — Presenter and contract tests

- Place Presenter at `app/Support/Institution/OrganizationalUnitTreePresenter.php`.
- Use namespace `App\Support\Institution`.
- Match the actual service tree structure.
- Project only the approved public keys.
- Test exact output, empty tree, and nested JSON absence of internal identifier keys.
- Review and close the phase before proceeding.

### Phase 06.2 — Arabic RTL views and translations

- Implement `overview`, `index`, `create`, and `edit`.
- Implement the approved Arabic translation file.
- Use prepared data only.
- Implement real empty, error, denied, and validation states.
- Do not register application routes or modify preview references.
- Review and close the phase before proceeding.

### Phase 06.3 — Form Requests

- Implement the three approved Form Requests.
- Validate only approved inputs.
- Test malformed ULIDs, unsupported fields, name constraints, and nullable parents.
- Keep tenant ownership and hierarchy authorization out of request validation.
- Review and close the phase before proceeding.

### Phase 06.4 — Controllers

- Implement the two approved controllers.
- Keep controllers as thin HTTP adapters.
- Delegate application work to `AuthorizedUnitOperations`.
- Do not register application routes.
- Review and close the phase before proceeding.

### Phase 06.5 — AuthorizedUnitOperations

- Implement `readMetadata`, `tree`, `create`, `rename`, and `move`.
- Enforce trusted actor, current verified context, and existing Gate/tenant authorization.
- Default deny when capability is absent.
- Delegate to the existing services only.
- Do not add capabilities, policies, roles, grants, routes, or provider changes.
- Stop and request integration if shared Gate/provider/registry/locking behavior must change.
- Review and close the phase before proceeding.

### Phase 06.6 — Feature and MySQL integration tests

- Complete isolated Feature tests for reads, writes, denial, isolation, and UI behavior.
- Add the approved MySQL integration test file.
- Cover T01–T18 across appropriate test suites.
- Preserve all existing test assertions and safety guards.
- MySQL tests may run only against the guarded `quality_platform_ci` database with existing explicit opt-in and exact database verification.
- Review and close the phase before proceeding.

### Phase 06.7 — Full validation and report

Run applicable checks, including:

- PHP syntax checks.
- Focused Feature tests.
- Full PHPUnit suite.
- Pint formatting checks.
- Guarded MySQL integration suite.
- Redis suite.
- `composer validate --strict`.
- `composer audit`.
- `npm ci` and `npm run build`.
- Relevant route/config/view cache and HTTP smoke checks in an approved environment.
- CI on the exact final commit.

Create:

`docs/engineering/reports/37.md`

The report must contain exact changed-file inventory, test commands and actual results, CI evidence, final SHA, integration blockers, authorization risks, and operating limitations.

Do not claim a test or CI pass unless it was actually observed on the relevant code/HEAD.

Prepare a single Draft Pull Request for project-lead review only after the required checks and authorization. Do not merge, self-approve, enable auto-merge, grant production access, or activate the feature in production.

## 16. Test and Environment Safety

The existing `MySqlTestCase` must remain unchanged.

MySQL tests must require the existing opt-in, testing environment, MySQL driver, no cached configuration, and exact database name `quality_platform_ci`, including verification of the actual selected database.

Never run destructive MySQL tests against development or production data.

Do not weaken or bypass guards, alter `.env` secrets, or change project manifests/configuration to make tests pass.

## 17. Stop Conditions and Project-Lead Requests

Stop only the affected unsafe integration portion if implementation requires:

- New/changed application routes.
- Middleware, guard, session, or provider changes.
- Capability registry or permanent permission configuration changes.
- New or modified policy semantics.
- Schema, migration, model, or existing-service changes.
- A changed branch base.
- A new file outside the approved inventory.
- Unsafe Gate-to-write revocation/concurrency behavior.
- A MySQL tenant-integrity or locking-order failure.

For each blocker, report:

1. The exact operation that is blocked.
2. The file or decision that prevents it, with evidence.
3. The smallest separate integration action required from the project lead.

Continue independent in-scope work only when it remains safe and does not depend on the unresolved integration.

## 18. Delivery and Reporting

Required permanent report:

`docs/engineering/reports/37.md`

The Arabic handover must state:

- What works in tests.
- What remains blocked by authorization or route integration.
- Exact files changed.
- Commands and observed results.
- Final commit SHA.
- CI links and actual status.
- Known limitations and outstanding project-lead requests.

The owner source documents must not be committed or published in the public repository, Issue, or Pull Request.

## 19. Current Status

At the time this corrected plan is written:

- Correct task branch is checked out.
- The required contract base SHA was previously verified.
- The Presenter exists under the wrong path and must be moved.
- The Feature test file exists with two passing focused tests; nested JSON coverage remains required.
- No `AuthorizedUnitOperations`, HTTP controllers, Form Requests, Blade views, or Arabic translation file have been implemented.
- MySQL integration tests have not been implemented or run for this issue.
- The permanent Issue #37 engineering report has not been created.
- The corrected plan has not yet been committed.
- No push or Draft PR has been performed yet; both remain gated by final validation and the required project-lead authorization. Merge, self-approval, auto-merge, and production activation are not authorized.

**Plan status:** Corrected to match the contract. Validate this file and create its dedicated commit before further application-code changes.

**Important chronology note:** The plan commit is being made after the initial Presenter and Feature test files were created. This is a documented procedural deviation, not a claim that the plan commit preceded implementation.
