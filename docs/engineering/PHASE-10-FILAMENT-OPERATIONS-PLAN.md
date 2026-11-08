# Issue 46 — Phase 10 institutional operations plan

2026-11-08. Existing branch `feat/46-filament-institution-vertical-slice`, clean at `aa974c8c16099eb55018111d323b2caad4cfbc14`; latest fetched protected main matches. PR45 is merged and its actual tree matches reviewed `c1365f83ee1729d136cf4823d3f6e2e6c5bb86fd`. Baseline main CI42503115833 SUCCESS; prior verified regression470/4834. Do not recreate/reset branch or modify coworker37.

## Authority and preflight

Owner's received functional requirements precede conflicting historical SRS scope text. Accepted ADR001/002/003 isolation/identity/integrity remain mandatory; ADR004 is PROPOSED. D002 categories are received; exact grants/bootstrap/revocation are not ratified. Synthetic test-only execution is expressly authorized by Issue46/Phase10, not an operational owner grant. D004 requires real published approval before ordinary draft release. D005 existing creation fields are reused. D015/016 require operational five-stage MVP eventually; that engine remains outside this Issue. No source/ledger/ADR approval is invented. Prior privately reviewed owner sources are rechecked for current availability/version and never copied publicly.

Read actual identity/tenant contracts, merged reports44/42, setup, auth/session/providers, native candidate Resource/Pages/Policy/Actions and tests. The previous prepared panel has no registration/login, User denies it, owner options stop at100 and row URLs run one authorization transaction per record. Reuse and correct these; do not attach live data to the preview.

## Scope and intended changes

The Issue itself explicitly authorizes reviewed shared-path changes required for this local institution bridge. This is sufficient authority for the two changes below; do not request the same approval again:

| Existing shared path | Exact purpose / preservation |
| --- | --- |
| `bootstrap/providers.php` | Register only InstitutionPanelProvider. No platform provider/guard registration. Runtime environment middleware must deny staging/production, including cached local routes. |
| `app/Models/User.php` at canAccessPanel | Keep exact legacy/admin/local-testing predicate; add institution-only branch requiring current guard/session/account/context and explicit Policy list permission. Never membership-only or app.env-only allow. |

Other named sensitive candidates (AdminPanelProvider, BrowserAccountSession, config/auth, AppServiceProvider, CapabilityRegistry) are unnecessary and remain unchanged. Existing guards, login URL, browser transition listener and ordinary login redirect are preserved.

| New/modified initially allowed path | Purpose |
| --- | --- |
| `app/Providers/Filament/InstitutionPanelProvider.php` | Native provider reusing the existing Panel configuration |
| `app/Filament/Institution/InstitutionPanel.php` | Explicit resources/dashboard, native navigation, persistent security middleware; no login duplication |
| `app/Filament/Institution/InstitutionPanelEnvironment.php` | Earliest runtime local/testing gate, independent of APP_DEBUG/cache |
| `app/Filament/Institution/InstitutionPanelSession.php` | Per HTTP/Livewire proof, host/private headers, finally cleanup; route-specific native logout delegates existing InstitutionEntry AFTER CSRF |
| `app/Filament/Institution/InstitutionAuthenticate.php` | Narrow native Authenticate subclass: existing login redirect and CSRF-protected native logout delegates existing Action before role checks; required by actual Laravel middleware priority |
| `app/Filament/Institution/Pages/InstitutionDashboard.php` | Actual native dashboard with authorized bank entry and honest unavailable publication/features |
| `app/Filament/Institution/Approaches/ApproachAccess.php` | Existing Action adapter; public panel predicate, bounded scoped unit search/labels, batched view-link hints |
| `app/Policies/ApproachPolicy.php` | Keep default DENY; reuse structural prerequisites for bounded batch view hints, never a durable grant |
| `app/Filament/Institution/Resources/ApproachResource.php` | Reuse existing Resource, official institution navigation |
| `app/Filament/Institution/Resources/ApproachResource/Pages/ApproachBank.php` | Searchable bounded owner picker; remove per-row authorization queries |
| `app/Filament/Institution/Resources/ApproachResource/Pages/ApproachWorkspace.php` | Clear general/draft/history/audit sections; searchable owner picker; keep real409 and append-only behavior |
| `lang/ar/approach_workspace.php` | Arabic native labels/notifications |
| `tests/Feature/Filament/InstitutionPanelJourneyTest.php` | Real HTTP+native Livewire writes and adversarial sessions/assignments; synthetic authority only in tests |
| `tests/Feature/Filament/InstitutionPanelRoutesTest.php` | Cached/uncached four-environment routes, persistent middleware/CSRF and direct denials |
| `tests/Feature/Filament/InstitutionPanelPerformanceTest.php` | More than100 units, bounded picker/constant-query listing/moderate fixture timings |
| `tests/Feature/Filament/PanelSeparationTest.php` | Adapt historical unregistered-institution expectation to this authorized registration; retain platform absence, no real-user entry, legacy/production denials |
| `tests/Feature/Filament/ApproachFilamentIntegrationTest.php` | Preserve all old scenarios; strengthen revoked native action expectation from silent no-op to403; use a fresh denied request after HTTP rejection because test transport discards its snapshot on403 |
| `tests/Integration/MySql/InstitutionPanelJourneyTest.php` | Disposable MySQL HTTP/Livewire persistence, revocation and historical/conflict regression |
| `docs/engineering/PHASE-10-FILAMENT-OPERATIONS-PLAN.md` | This prior plan and approved amendments |
| `docs/engineering/PHASE-10-AUTHORITY-PROPOSAL.md` | Public-safe issuer/action/resource/scope/expiry/revocation/audit proposal; no grants |
| `docs/engineering/reports/46.md` | Permanent complete evidence/AC/file inventory/risks/walkthrough |

## One narrow additional-path proposal — pending approval

`resources/views/institution/account.blade.php` currently has a self-account greeting/logout and no route to the institution workspace. Add only an Arabic workspace link when the existing institution account passes the same reviewed panel-entry predicate; keep login redirects/account content/logout unchanged. This makes the existing login → account → native dashboard journey discoverable without a second login or controller/route changes. Tests must prove default-denied/list-only/foreign users do not gain content, existing identity tests unchanged, and production denial. This view is outside the initial allowlist: request approval before editing; continue the independent panel/search/tests first.

## Trusted-context and registration design

One Laravel app, web legacy and existing institution guard only. Register the Panel definition consistently so cached route middleware can still identify its panel; **registration is not access activation**. Its earliest runtime environment gate returns404 outside local/testing before private content. It has no login flow. Session bridge obtains only institution guard/current provider and server session organization marker, checks current principal/password/member and panel Policy, establishes TenantContext in finally-safe scope, and restores the original default guard after request. No query/header/hidden field chooses actor or institution. Every read/write adapter still authorizes independently inside existing current locking boundary; no generic Model save.

Native logout POST must invoke the existing InstitutionEntry/BrowserAccountSession contract instead of Filament's default logout behavior that could rotate a shared remember token or leave the other browser guard. Interception occurs only after standard CSRF for that exact named institution logout route; no shared auth flow changes.

Synthetic positive execution uses isolated test fixtures and explicit test-only Policy subclass/capability setup. Ordinary shipped Policy stays false; no grant seeder, new assignment model, role engine, runtime test-bypass flag or real data. A reproducible browser demonstration may launch an ignored loopback test router using those same test-only fixture classes, a verified disposable schema and private random credentials/fixture manifest. Never run that helper through Herd's user database, staging or production. Cleanup only tracked synthetic IDs.

Selector uses a bounded server-side scoped read under the existing adapter boundary (20 matches, public ULID/name only), not a full preload or first100 cutoff. This read does not duplicate mutation rules; existing Create/ChangeOwner Actions remain authoritative. Batch view hints remove per-row authorization queries; detail/action requests always reauthorize, so a stale hint grants nothing. No invented production assignment/query semantics.

## Validation and order

Commit this plan before runtime code. Baseline identity/panel tests; implement bridge and native UI; HTTP login→account/dashboard→list/create/edit/draft/history/logout; verify persisted synthetic MySQL. Adversarial actor/tenant/ULID/CSRF/Livewire/password/member/assignment/session/cache tests and real InnoDB concurrency. Keep original FK/UNIQUE/CHECK/history/trigger tests.

Run SQLite, guarded disposable MySQL8.4, real namespaced Redis, Pint, Composer strict/platform/audit, npmci/audit/build; four-environment cached/uncached routes; actual375/768/1280 browser layout/keyboard checks. No quality_platform migration/fresh/wipe/rollback. No CI/package/theme/coworker edits. Final report committed, exactly one Draft PR, inspect exact report-containing HEAD CI/logs and record final receipt in PR/private handover without circular self-hash commits. Stop for lead; no merge/deploy.

Authority status labels: RECEIVED BUSINESS RULE, ENGINEERING CANDIDATE, RATIFIED GRANT (none), OPERATIONAL LOCAL (only proven synthetic path), PRODUCTION GATED. Detailed first-quality-administrator appointment/revoker question remains OWNER-REQUIRED; no first-admin privilege is inferred.

## Lead amendment received

Before editing the account view, the engineering lead explicitly approved **only** `resources/views/institution/account.blade.php` to add one Arabic workspace link gated by the same backend panel-entry predicate. Preserve account/login/logout/recovery; no production/staging link/access, no independent Blade permission rules. Test authorized/denied/foreign/revoked/expired sessions. No wider path authority inferred.

## Lead amendment: exact historical route-test compatibility

The engineering lead approved only `tests/Feature/Approaches/ApproachManagementTest.php` and `tests/Feature/InstitutionApproaches/ApproachHttpAuthorizationTest.php` in addition to the existing scope. Their blanket absence-of-any-Approach-route assertion predates the authorized panel registration. Exempt only the exact native list/workspace route name and URI pairs, after asserting environment, session proof and authentication middleware; preserve the original absence assertion for every other route, all legacy/admin 404 checks, and all prior security scenarios. New journey/cache tests prove default-denied membership, invalid sessions, revocation, foreign institutions and staging/production denial. No database/CI/permission changes are authorized by this amendment.
