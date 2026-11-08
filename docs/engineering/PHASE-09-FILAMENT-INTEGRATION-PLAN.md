# Issue 44 — Phase 09 Filament integration plan

Date: 2026-11-08. Status: preflight before implementation. Issue [44](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/44). Existing branch `feat/44-filament-operational-foundation`; clean HEAD and remote protected main both `bc301352242864b0f1d2dc560f846961972b181a`. Reuse this branch. No migration, package, CI, deployment or Issue37 changes.

## Authority and reconciliation

Read AGENTS, CONTRIBUTING, ADR001/002/003, proposed ADR004, ledger, identity/authorization/Approach contracts, owner-source reconciliation and supplied owner answers/domain workflow/maturity appendix, and canonical SRS v3. Public documentation records decision IDs and consequences only; originals and source receipts remain ignored locally.

Latest functional direction supersedes old SRS display-only MVP language: D015/D016 require all five maturity stages to operate in MVP. The engine remains outside Issue44. D004 requires an actually approved published revision for ordinary readers; none exists in the current schema. Never substitute a draft. D005's objectives/review frequency are optional at creation in current Actions. D018 ownership is an organizational unit, not permission for its members. D022 forbids self-approval; no review engine is authorized here. D002 categories/responsibilities are known; exact operation/resource/assignment/expiry/revocation/bootstrap grants remain unratified. Independent institution identities are implemented candidates; ADR004 remains PROPOSED and accepted ADR002 is not silently amended.

## Verified integration constraints

- Existing `User::canAccessPanel` permits only legacy + admin + local/testing. Other panel access must remain denied until reviewed.
- Only `web` (legacy provider) and `institution` guards exist. Platform login/provider/browser-transition support is absent. Reusing web for platform would break isolation.
- TenantAuthorization supports Organization/OrganizationalUnit only; registry is empty. Approach capabilities cannot be inferred from membership or invented configuration.
- Internal Approach Actions validate structural tenancy/current locking membership, but deliberately do not grant end-user permission. A presentation application adapter must authorize every operation inside that same transaction boundary, with a freshly scoped persisted resource, before invoking existing Actions.
- Metadata/owner updates already use an edit token and 409; draft appends have no expected-version input. Candidate draft adapter must compare a public latest-revision token under the existing organization lock before calling the append Action. No old Action/schema change needed.

## Initially approved concrete new paths

| Path | Purpose |
| --- | --- |
| `app/Filament/Platform/PlatformPanel.php` | Unregistered native Panel definition, separate platform guard, no tenant Resources/login activation |
| `app/Filament/Institution/InstitutionPanel.php` | Unregistered native Panel definition using existing institution guard, explicit Resource list outside admin discovery |
| `app/Filament/Institution/Approaches/ApproachAccess.php` | Presentation application boundary: trusted institution guard + session + persisted binding/context + scoped Policy; transaction recheck; delegates existing Actions |
| `app/Filament/Institution/Resources/ApproachResource.php` | Native Resource with custom-data pages, no direct Eloquent write/global search/delete |
| `app/Filament/Institution/Resources/ApproachResource/Pages/ApproachBank.php` | Native Table and create Action/Form |
| `app/Filament/Institution/Resources/ApproachResource/Pages/ApproachWorkspace.php` | Native Infolist, edit/owner/draft Actions, separate history tables, honest publication-unavailable state |
| `app/Policies/ApproachPolicy.php` | Auto-discoverable deny-by-default candidate; no real grant path |
| `lang/ar/approach_workspace.php` | New Arabic labels/denied/conflict/gated messages |
| `tests/Feature/Filament/ApproachFilamentIntegrationTest.php` | Synthetic policy/assignment fixtures; actual native Livewire render/actions, adversarial updates, default denial, no route/discovery activation |
| `tests/Feature/Filament/PanelSeparationTest.php` | Inert configuration, existing identity/legacy session/remember/CSRF regressions, explicit missing platform guard evidence |
| `tests/Integration/MySql/ApproachFilamentBoundaryTest.php` | Disposable MySQL transaction/security/history/stale/revocation adapter tests; isolated fixture cleanup |
| `docs/engineering/PHASE-09-FILAMENT-INTEGRATION-PLAN.md` | This plan, amendments and status |
| `docs/engineering/reports/44.md` | Permanent evidence, traceability, file inventory, actual final checks, risks and gates |

No new custom Blade showcase or public route. Filament native Schema content renders pages without extra views. Synthetic allow policies exist only in test files and are never selected by application environment/config. The shipped Policy always denies. Native UI actions reauthorize on execution; stale public state is never authority. No model or numeric foreign key is serialized to Livewire.

## Protected-path proposal — NOT APPROVED, DO NOT EDIT

These are a future operational integration amendment, not part of the initial candidate. Lead must approve each exact path and owner must ratify D002 before registration/grants:

| Proposed path | Required reason |
| --- | --- |
| `config/auth.php` | Dedicated platform session guard/provider; never alias legacy web |
| `app/Providers/AppServiceProvider.php` | Register reviewed platform provider and scoped approved Approach authorization integration |
| `app/Identity/PlatformUserProvider.php` (new, outside initial scope) | Persisted platform-only credential/session/remember resolution |
| `app/Actions/Identity/BrowserAccountSession.php` | Extend one-active-account transitions to third guard, clear all opposing sessions/cookies safely |
| `app/Models/User.php` | Precise panel entry predicates without any tenant-content inheritance |
| `bootstrap/providers.php` | Register reviewed panel providers only after all gates pass |
| `app/Authorization/CapabilityRegistry.php` | Reviewed Approach capability/resource support; no speculative entries |

Any further provider/middleware/routes/login file must be proposed separately after design review. Current AdminPanelProvider, shared theme, routes and session providers are unchanged. Do not partially enable a third guard without complete transition/provider tests. This plan does not authorize those changes. Platform session/login acceptance is BLOCKED, not PASS on a nonexistent guard.

## Sequence and validation

1. Commit preflight plan before code; record source receipts privately. Request scoped authority checkpoint while continuing safe new files.
2. Denying Policy and unregistered panel configuration; scoped adapter using existing domain Actions.
3. Native Resource/Custom Pages with Table, Forms, Infolist, Actions, separate draft/audit/publication gates. Forms accept only public ULIDs and allowed text; deny extra fields, actor/tenant injection and stale edits. Read grants never imply write/history grants.
4. Positive tests use test-only explicit per-actor/action/resource assignments; negative tests keep real default denial. Revoke while a component is alive; recheck every read/update and hide old form values after denial. Test cross-tenant resource/owner IDs, platform/legacy, CSRF and locked-property tampering. No approval UI/engine, no fabricated published body.
5. SQLite, guarded disposable MySQL8.4 and Redis; Pint, Composer strict validation/audit/platform, npm build; actual rendering/mobile/keyboard checks. No migrate:fresh against user `quality_platform` database.
6. Review allowed file diff/privacy, push existing branch, Draft PR, inspect final HEAD CI logs. Report IMPLEMENTED INTERNAL / PREPARED GATED / BLOCKED OWNER/OPS separately. No merge/deploy.

## Remaining authority checkpoint

D002 approval must identify first quality-officer bootstrap authority, each action/resource/actual-user assignment, supporting delegation, revocation/expiry and independent reviewer. Do not repeat answered category-level questions. Sensitive path approval is separate from business grant ratification. Until both exist, only isolated synthetic execution and unregistered candidate panels are permitted; preserve default denial in all ordinary environments.
