# Final MVP integration candidate — 12 November 2026

**Verdict: PARTIAL / TEST_ONLY; not a complete operational MVP or production release.**

- Branch: `integration/mvp-final-2026-11-12`; [Draft PR70](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/70), never self-merged.
- Protected base: `90894051f249fe9b8aa78af728580bf96ddd9299`; validated starting source `b3db07e8afe4cc6f637946865f0d0e51fd9d07ca`.
- Contributor: published `5c86b4fdc8327975dab84faf9a4f39f82ad694fe`; non-destructive merge `01f2e53419a9a11030656ae023fc914f017fad52`, preserving original attribution and branch.
- Core technical source: `f2c13378198fddd79fc8f044dc77dab3423ff9e8`. Native additions are a subsequent checkpoint; the containing commit and exact final-head CI receipt are recorded in PR70 and the private handover, avoiding a self-referential report SHA.

## Authority, requirements and preservation

The final Lead authorization permits contributor37 repair and shared technical T17 work only in the isolated integration branch. It supersedes historical technical deferments, never open owner business policies. Reviewed sources include AGENTS/CONTRIBUTING, accepted ADR001/002/003, the decision ledger, canonical SRS/105-row acceptance register, complete private execution contract and the authoritative received owner answers. Private owner prose, source paths and full contracts are not published.

Relevant acceptance groups: FR-ID004 and tenant/session controls; FR-APP assigned-resource lifecycle/history; FR-DOC metadata/version/integrity; FR-REV correction/independent approval; FR-MAT initial ordered stages/history; FR-UX Arabic native presentation. Full original requirement acceptance states remain NOT_ACCEPTED in [ACCEPTANCE](../delivery/ACCEPTANCE.md). A tested bounded action is not the whole requirement.

Original checkout remains4b81e88 with its previously retained five-file archive, stash9b30bc6b578ac4b6c4ab1b7db035a775ea31a2fa and prior stash. No interrupted local promotion, stash application/drop, original demo fingerprint/grant/credential renewal, development database modification or unrelated app interference. Private preservation receipts retain hashes for the original environment/assets/archive/browser90 manifest and worktree/ref inventory. Only failed new rehearsals21/22 were stopped under separate explicit user approval; all their containers, volumes and evidence were retained. No main/contributor branch push, deployment or destructive cleanup.

## Implemented changes and security impact

**T17 candidate.** TenantAuthorization now exposes an explicit structural mutation boundary, not a permission grant. Supported writers acquire Organization X, ascending User PK X, ascending membership PK X, then role/domain locks. Only a live same-PDO/transaction proof enables the current joined role/permission locking read. Outside it, original authorization semantics remain. RoleManagement, organizational units and Approach creation share this prefix. The actual contributor adapter rechecks the current locked institutional principal, session password and real Gate after waiting, holding the transaction through mutation/audit/commit. Membership or a role name grants nothing. Transaction/PDO/context loss fails closed.

Real MySQL1205/1213 abort the entire enclosing application transaction, including Laravel13's nested DeadlockException wrapper, preserve the original SQL cause and do not automatically retry a non-idempotent operation. The deliberately inverted deadlock holder is test-only; supported structural writers acquire Organization first. Arbitrary external SQL or mutation APIs that do not follow the protocol are outside this guarantee. This is reviewable evidence for the tested integration, not unilateral closure of every T17 concern.

**Native organization flow.** A non-discovered Filament5 Page reuses AuthorizedUnitOperations and existing hierarchy/history Actions. It displays current institution metadata and a searchable/paginated unit tree, with create/rename/move. Native Split and ActionGroup make management controls usable at375px. Cycle validation is attached to the actual mounted parent-field path, rather than disappearing from Livewire's error bag. The real signed-form regression reproduced the missing error before the fix.

**Cohesive TEST_ONLY navigation.** The already isolated Review/Maturity provider explicitly registers existing Approach and Document Resources plus the new organization Page. Original real Policies remain decisive. It supplies the account link only under the explicit sealed fixture and current panel admission. Unit capability schema mapping is available before session middleware without allowing guests: a denial-only Gate restriction limits actors, then delegates to real authorization. An HTTP regression reproduces the cached-test-guard versus real-browser middleware-order issue. Normal providers/routes/guards, capability registry and operational grants remain unchanged.

No migration, SQL definition, dependency, package lock, Policy or CI configuration changed in this integration wave. The previous46-migration R68 persistence foundation remains intact. New test servers are exclusive disposable quality_platform_ci services guarded by namespace, database, version, UUID, loopback and testing opt-in; the development quality_platform database is never used. Existing runtime fixture accounts are DML-only.

## Actual verification history

| Check | Actual result | Scope |
|---|---|---|
| Original contributor SQLite |15 tests /336 assertions PASS|Published contributor baseline |
| Original contributor MySQL |1/12 ERROR|Existing populated identity rollback guard; retained baseline evidence |
| Actual-adapter fail-first |5/47,3 failures,0 errors|Role/permission/password race reproduced; membership already denied |
| Final focused adapter/T17 + unchanged J03 concurrency |16/157 PASS|Independent connections, RR current grants, writer-first actual lock wait, real1205/1213, full rollback |
| Core exact-head CI43105967011 |802/8439 PASS|SQLite515/4887; MySQL8.4.13 283/3533; Redis8.2.11 4/19;0errors/failures/skipped tests |
| Same-panel Approach admission fail-first |1/2 FAIL404|Real missing registration reproduced before resource reuse |
| Cycle-error fail-first |1/11 FAIL|Actual mounted field had no persisted validation error before path correction |
| Native unit + existing review Filament subset |12/136 PASS|Actual login, signed Livewire, create/rename/move, visible cycle error/no write, foreign/numeric IDs, current role/password, same-panel assigned resource/reviewer denial |
| Full native SQLite checkpoint |523/4962 PASS|Before final two added native tests; not the final source total |
| Full local MySQL first pass |290/3591,1 failure|Fixture had skip-log-bin but original1419 test requires log_bin1; no application test weakened |
| Corrected owned MySQL8.4.13 full rerun |RUNNING at this source checkpoint|Default log_bin1/trust0 and exact restricted CI account; no global configuration change |
| Current owned Redis |4/19 PASS|Authenticated separate service,DB15, exact-owned key cleanup only |
| Composer validate/install/platform/audit |PASS|Existing locked dependencies |
| npm ci/build |PASS|Existing portable Node24.22.2; no package/lock changes |
| Full Pint |PASS|Final format recheck also required before publication |

Core [CI43105967011](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43105967011) checkout7a36c84e1c755b1866946e7421b91b635794f449 has parents90894051/f2c1337 and tree54ce69d7c339af8e03ffc5e0641f4578e51d879f, exactly the local core tree. All required job logs were read. The Issue4 conditional step is not applicable; no tests were skipped. This run does not validate the later native changes. Final containing-head CI and full regression results must be verified separately in PR70/private final receipt.

The alternate-storage SQLite pass failed8 protocol-peer readiness assertions because the peer correctly restricts its canonical storage root; canonical-worktree-storage full rerun passed523/4962. The first newly added cycle test also exposed a self-FK teardown requirement. Its exact-created leaf IDs are now cleaned after assertions and fixture audit cleanup, with the self-FK still rejecting unexpected children; no FK disabling or migration guard bypass. These setup failures and original results remain private evidence.

## Browser-to-MySQL evidence

Retained real Chrome157 rehearsals used actual institutional login, real CSRF/session middleware and native Filament/Livewire, never admin/ui-preview. At375/768/1280 the actual native unit actions and mobile menu were checked, not merely the document's root scroll width. Screenshots were visually inspected. Existing initial content correction and independent approval were followed by five configured stages, exact authorized synthetic DocumentVersions and in-app read state.

Independent SQL and owned-byte readback verified2 content revisions,7 immutable review rounds (1 correction,6 approvals),5 configurations,5 immutable stage assessments with exact predecessor chain,10 version evidence links,14 atomic notices and actual read state. Unit create/rename/move yielded exactly3 audit events with the expected persisted parent after independent HTTP reload; attempted cycle added no event. All19 original SQL/byte checks passed. Reviewer/foreign unit denial, real CSRF rejection and logout reentry denial passed.

The retained rehearsals required explicit browser locator corrections/resumption: native modal wrappers can have zero height while their children are visible, custom Select's button label differs from its placeholder, and native menuitems appear asynchronously. They were resumed only after read-only SQL established exact state; no reset, duplicate replay or fabricated result. The corrected public runner must pass once on a fresh source-frozen fixture; that final result belongs to the final receipt. Screenshots, private account data, raw manifests and full diagnostics stay outside public Git.

## Completion matrix and explicit delivery cuts

| Phase / customer behavior | Candidate status | Remaining dependency / impact |
|---|---|---|
| P0 source and acceptance reconciliation |PARTIAL|105-row register retained; no full phase accepted |
| P1 identity/organization |PARTIAL; bounded login and native units demonstrated|Operational provisioning, invitations and granular D002 grants unavailable; organization metadata display is read-only |
| P2 Approach bank/content/history |PARTIAL; actual assigned native Resource connected|No implicit Quality grant or full creation workflow claimed for the contributor; original earlier J03 evidence retained |
| P3 real documents |PARTIAL / unavailable user journey|R68 foundation and synthetic workspace exist; real scanner/signatures, exact operation backing, independent release/download/native intake integration still need implementation and operational ratification |
| P4/P5 reviews/evidence/publication |PASS for named TEST_ONLY review/correction/independent approval and exact synthetic-version associations; overall PARTIAL|Broader evidence relationship/publication rules and operational authority unresolved |
| P6 notifications |PASS bounded in-app delivery/read state; overall PARTIAL|Full operational recipients/events and external distribution not authorized |
| P7 maturity/reassessment |PASS bounded five-stage initial manual cycle; overall PARTIAL|R68 reassessment history/Actions exist; downstream effective projection, notification/UI/new operation backing remain incomplete; operational D003/D014 not ratified |
| P8 references/mappings |BLOCKED / unavailable|Licensed source, approved cardinalities and relationship semantics required; no standards text imported |
| P9 coherent Arabic native UX |PARTIAL|Working protected native navigation and mobile unit controls; incomplete modules are not represented by fake-success screens |
| P10 complete operational customer acceptance |NOT_ACCEPTED|Only bounded local journeys and technical candidate evidence; no L4/production readiness |

Explicit technical cuts are not renamed owner blockers: new unit forms do not yet provide a request-key replay contract or optimistic stale-edit token; real-file/reassessment UI activation is unimplemented. Do not present a serialized current authorization check as either of those guarantees. Existing Approach/document/review idempotency/conflict tests remain intact. Settled bounded D007/D014 decisions are reused; they are not reopened or treated as operational ratification. Minimum genuine owner gates remain operational issuer/revoker/grant/resource/delegation scopes, D003 placement, operational D007/D014 and reference authority.

## Review and handover

No hosting, production permissions or main merge. Keep the final local demo running for manual review; credentials are retrieved only from its private local fixture.json. The [owner runbook](../delivery/OWNER-DEMO-RUNBOOK.md), [screen matrix](../delivery/OWNER-DEMO-COMPLETION-MATRIX.md) and [Arabic stakeholder summary](FINAL-MVP-STAKEHOLDER-AR-2026-11-12.md) distinguish working TEST_ONLY behavior from unavailable features. Cleanup is a separate owner-triggered action after testing, never an automatic handover step.

## Exact changed-file inventory relative to b3db07e

The following inventory includes preserved contributor source, technical repairs, native integration/tests and engineering records. Private files and generated assets are excluded.

- `app/Actions/Approaches/CreateApproach.php`
- `app/Actions/OrganizationalUnits/AuthorizedUnitOperations.php`
- `app/Actions/OrganizationalUnits/OrganizationalUnitManagement.php`
- `app/Authorization/RoleManagement.php`
- `app/Authorization/TenantAuthorization.php`
- `app/Filament/Institution/Pages/OrganizationWorkspace.php`
- `app/Http/Controllers/Institution/OrganizationOverviewController.php`
- `app/Http/Controllers/Institution/OrganizationalUnitController.php`
- `app/Http/Requests/Institution/MoveOrganizationalUnitRequest.php`
- `app/Http/Requests/Institution/StoreOrganizationalUnitRequest.php`
- `app/Http/Requests/Institution/UpdateOrganizationalUnitRequest.php`
- `app/Support/Institution/OrganizationalUnitTreePresenter.php`
- `docs/engineering/PHASE-06-ORGANIZATION-UI-IMPLEMENTATION-PLAN.md`
- `docs/engineering/delivery/ACCEPTANCE.md`
- `docs/engineering/delivery/DECISIONS.md`
- `docs/engineering/delivery/JOURNEYS.md`
- `docs/engineering/delivery/OWNER-DEMO-COMPLETION-MATRIX.md`
- `docs/engineering/delivery/OWNER-DEMO-RUNBOOK.md`
- `docs/engineering/delivery/RESUME.md`
- `docs/engineering/delivery/STATUS.md`
- `docs/engineering/reports/37.md`
- `docs/engineering/reports/FINAL-MVP-DELIVERY-2026-11-12.md`
- `docs/engineering/reports/FINAL-MVP-STAKEHOLDER-AR-2026-11-12.md`
- `lang/ar/institution_organization.php`
- `resources/views/institution/account.blade.php`
- `resources/views/institution/organization/overview.blade.php`
- `resources/views/institution/organization/units/create.blade.php`
- `resources/views/institution/organization/units/edit.blade.php`
- `resources/views/institution/organization/units/index.blade.php`
- `tests/Browser/FinalIntegration/OrganizationJourney.js`
- `tests/Browser/ReviewMaturity/Fixture.php`
- `tests/Browser/ReviewMaturity/Journey.js`
- `tests/Browser/ReviewMaturity/Run.ps1`
- `tests/Feature/Filament/InstitutionOrganizationIntegrationTest.php`
- `tests/Feature/InstitutionOrganizationOperationsTest.php`
- `tests/Feature/T17MutationBoundaryTest.php`
- `tests/Integration/MySql/InstitutionOrganizationNativeTest.php`
- `tests/Integration/MySql/InstitutionOrganizationOperationsTest.php`
- `tests/Integration/MySql/T17SerializationTest.php`
- `tests/Support/ReviewMaturityPanelProvider.php`
- `tests/Support/t17-deadlock-worker.php`
