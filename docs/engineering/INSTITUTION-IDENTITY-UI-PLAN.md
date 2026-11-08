# Issue48 — institutional identity presentation plan

2026-11-09. APPROVED presentation-only scope from Issue48 and explicit engineering-lead approval. Record this plan before template changes.

## Preflight and authority

Existing remote branch `feat/48-institution-identity-filament-ui` selected locally with tracking; no reset/recreation. Clean baseline/latest fetched main: `5d9c8690dd24386b0a7f16e054c7ab49e0128acb`. Main CI42528268381 verified completed/SUCCESS on that exact SHA. AGENTS/CONTRIBUTING, identity-entry contract, decision ledger and installed Filament5.11/Livewire4.4 source reviewed with the current templates/controller/middleware/tests and merged Issue46. Accepted ADR001/002/003 security constraints and PROPOSED ADR004 status remain unchanged; D002/Issue13 operational gates remain independent. No business policy decision is required for this cosmetic slice.

## Exact approved paths (8)

| Path | Change / preserved contract |
| --- | --- |
| `resources/views/institution/entry.blade.php` | Native Blade inputs/wrappers/buttons and distinct sign-in/recovery groups. Preserve both ordinary POSTs, exact field names/types/limits/autocomplete/old-input behavior, CSRF and generic shared status/error semantics. |
| `resources/views/institution/reset.blade.php` | Same presentation; action remains literally request()->getRequestUri(), including signed query. Password/confirmation fields and limits unchanged; never echo password. |
| `resources/views/institution/account.blade.php` | Native styled greeting/actions; original canAccessPanel predicate and workspace route, logout POST/organization/CSRF untouched. |
| `resources/views/components/institution/identity-layout.blade.php` | New shared semantic Arabic RTL shell. Read existing panel visual configuration only, without booting/changing panel, guard, context or authorization. Existing local font/theme and native Blade components, no JS framework or external assets. |
| `resources/css/filament/admin/theme.css` | ONLY four exact @source lines for the above templates/layout; no other shared CSS/token/selector changes. |
| `tests/Feature/Filament/InstitutionIdentityPresentationTest.php` | New actual DOM/HTTP contract, escaping, signed-link and authorization visibility regressions. All old tests unchanged. |
| `docs/engineering/INSTITUTION-IDENTITY-UI-PLAN.md` | This pre-code plan, asset strategy and evidence refinements. |
| `docs/engineering/reports/48.md` | Final results, eight-file inventory, screenshots, risks, branch/commits/PR/CI. |

## Asset and UI strategy

Use supported static Filament Blade button/input/input.wrapper/fieldset/link components on existing Laravel routes; retain ordinary POST transport, not Filament auth pages or new Livewire endpoints. Do not reuse the authenticated panel layout, boot its middleware or mutate global color registration to render public entry.

Load existing Filament style assets and Vite's existing admin/theme.css entry. Ensure configured institutional primary palette and installed local font apply to the isolated identity shell, including native focus styling, while leaving panel color/font state unchanged. No new asset entry, dependency, published vendor override, global stylesheet or external font/CDN. Verify actual browser network and computed styles rather than assuming component markup is enough. Preserve the existing light/dark design using its existing palette and native dark variants; any theme-mode initialization stays presentation-only and scoped to this shell.

Sign-in remains first and visually primary; recovery stays visible in the same page, with its own heading/group. Both use the existing shared error bag: show the existing first error in a general alert without falsely attributing it to either form. Status feedback remains generic. Password reset and account reuse the shell. Maintain lang=ar/dir=rtl, label associations, LTR email/institution input, visible keyboard focus, responsive spacing and44px touch targets. No institution directory, profile editor, remember-me, provisioning, real grants or new authentication features.

## Execution and verification

1. Commit this plan; run baseline identity/guard/Filament access tests.
2. Implement shell/assets and three templates; add four source lines only.
3. New DOM/HTTP tests verify exact form actions/methods/CSRF and all attributes/values; raw signed query including extra signed parameters; ordinary login/recovery/reset/logout behavior and existing conditional link. Keep old tests immutable.
4. Run focused tests, all original identity/access tests, then full SQLite, guarded disposable MySQL8.4, namespaced Redis, Pint, Composer strict/platform/audit and Node24 npm audit/build. Do not mutate the user development database. Tests may use only documented disposable resources/isolated fixtures.
5. Actual local browser verification with synthetic data:375/768/1280, light/dark, success/error/recovery/reset/account views, loaded local CSS/fonts, native computed styles/focus and no overflow/external requests. Use ignored removable project-local fixture helpers and exact-ID cleanup; screenshots stay project-local ignored evidence because only eight public paths are authorized.
6. Commit permanent report, push this existing branch, create one Draft PR; inspect completed CI head_sha/logs against final report-containing HEAD. Record immutable final receipt in PR description and ignored project-local handover to avoid a circular self-hash report commit. No merge/deploy.

## Boundaries

No edits to Actions/controllers/routes/middleware/guards/Policies/providers/User/database/old tests/CI/manifests/vite.config/preview/coworker37. Existing no-store/private, no-referrer, nosniff and stage/production denial stay intact. Extra scope requires separate narrow approval; this approval does not ratify D002 or ADR004 and grants no real access.

## Verified presentation refinements

Standalone styles supply native defaults while the shell scopes the institution's existing primary CSS variables/font to its own document. Primary buttons also receive the existing palette directly through Filament's supported array-color API so contrast-slot selection uses that palette; this does not mutate FilamentColor. Actual browser measurement found the native dark600 background below4.5:1 with white text in this version; only these primary buttons use existing dark700/hover800 utilities. Final sampled contrast6.63:1 light /5.64:1 dark. Shared theme remains exactly four added source directives.

Validation feedback is passed explicitly from the original view error bag to the shell; no named error bags or backend attribution added. Actual ordinary POST→GET behavior is tested; avoid starting the JSON-serialized test session again through an intermediate session assertion before the following render. Existing old tests are untouched. Local assets and all three surfaces are verified in both modes at375/768/1280; ordinary browser same-origin origin verification is distinguished from the untrusted/no-token419 test in Laravel13.
