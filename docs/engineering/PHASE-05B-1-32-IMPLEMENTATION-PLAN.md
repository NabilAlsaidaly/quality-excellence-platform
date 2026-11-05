# Issue32 institution identity entry implementation plan

2026-11-05. Base `2a4f49a3c0eafe299f88147bbffeff2fc964b0c8`; branch `feat/32-institution-identity-entry`; [Issue32](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/32). The newer explicit implementation contract authorizes this candidate branch, superseding the earlier planning-only stop for this slice. D-021 functional requirements are received; G0 source amendment, G1 successor acceptance and G2 operational delegation approval are NOT claimed. No management privileges, production entry or merge.

## Design and compatibility checklist

Retain users.id/history and legacy rows. Add explicit legacy/platform/institution principal type, nullable institution binding, public account ULID, generated non-null namespace and namespace/email unique key. Existing rows remain legacy; no mapping by membership/email. Institutional/platform new emails use trimmed lowercase ASCII mailbox syntax; Unicode mailbox/domain input is explicitly unsupported rather than silently normalized. Candidate DB checks and scope-specific uniqueness guard namespace collisions without nullable platform uniqueness holes. Existing globally unique legacy email behavior is preserved within the legacy namespace. Platform entry is not implemented.

One atomic MySQL users ALTER replaces global email uniqueness only alongside the new namespace key/checks/FK; SQLite changes are transactional. New membership INSERT/UPDATE and user-binding UPDATE guards are separately journaled, one CREATE TRIGGER per migration, avoiding multi-trigger partially applied migrations. Existing actor IDs, composite membership/pivot keys and restrictive history stay unchanged. Account-keyed token storage has a same-institution composite User FK. No existing migration is edited. Down refuses identity-semantic loss; document incompatible binary rollback and DDL/journal crash recovery. Populated legacy fixtures test expansion/retry; no application rows queried.

Separate institution session guard/provider; legacy web provider excludes new principals. Laravel broker/token adapter keys resets by account, with real contact-email delivery and signed scope/account-bound URLs. Serialize token issuance/consumption against the account; invalidate remember tokens on reset. Thin controller/Blade, focused entry/recovery Actions and self-account Policy; every institutional request verifies binding/membership/context, finally clears context, blocks stale scope/session and disallows production/staging entry. No Livewire entry feature is added; existing Filament stays isolated/fail-closed. No registry capabilities, roles, accounts or operational seed grants.

## Exact intended paths

New:
- `database/migrations/2026_11_05_000001_expand_user_identity_namespaces.php`
- `database/migrations/2026_11_05_000002_guard_identity_membership_insert.php`
- `database/migrations/2026_11_05_000003_guard_identity_membership_update.php`
- `database/migrations/2026_11_05_000004_guard_identity_binding_update.php`
- `database/migrations/2026_11_05_000005_create_institution_password_reset_tokens.php`
- `app/Identity/LoginEmail.php`, `app/Identity/InstitutionUserProvider.php`, `app/Identity/LegacyUserProvider.php`, `app/Identity/AccountTokenRepository.php`, `app/Identity/AccountPasswordBrokerManager.php`
- `app/Actions/Identity/InstitutionEntry.php`, `app/Actions/Identity/InstitutionRecovery.php`
- `app/Http/Controllers/InstitutionEntryController.php`, `app/Http/Middleware/InstitutionSession.php`, `app/Policies/InstitutionAccountPolicy.php`, `app/Notifications/InstitutionPasswordReset.php`
- `resources/views/institution/entry.blade.php`, `resources/views/institution/account.blade.php`, `resources/views/institution/reset.blade.php`
- `tests/Support/InstitutionIdentityScenarios.php`, `tests/Feature/InstitutionIdentityTest.php`, `tests/Feature/InstitutionEntryTest.php`, `tests/Integration/MySql/InstitutionIdentityTest.php`, `tests/Integration/MySql/InstitutionIdentityMigrationTest.php`
- `docs/adr/ADR-004-institution-identity-candidate.md` (PROPOSED limited ADR-002 amendment), `docs/architecture/institution-identity-entry.md`, `docs/engineering/reports/32.md`, this plan.

Modify after full-file inspection: `app/Models/User.php`, `app/Authorization/TenantContext.php`, `app/Providers/AppServiceProvider.php`, `config/auth.php`, `routes/web.php`, `tests/Support/TenantIsolationSchemaContract.php`, `tests/Integration/MySql/TenantIntegrityTest.php` (exact new migration count only, no assertion removal), `docs/architecture/tenant-isolation-schema-contract.md`, `docs/architecture/decision-ledger.md`, `docs/README.md`. Any further path growth must be explicitly justified in this plan/report before staging.

The unrelated change to `docs/engineering/collaboration-security.md` appeared concurrently after initial status; preserve it unstaged. Preserve the existing untracked storage artifact and contributor branch. Never stage private files or unrelated work.

Scope clarification after inspecting full regression files: also modify `tests/Integration/MySql/OrganizationHistoryIntegrityTest.php`, `HierarchyHistoryIntegrityTest.php`, `PermissionIntegrityTest.php` for exact19/18 journal expectations (five new migrations), and `TenantIsolationSchemaContractTest.php` to assert the new nullable User binding explicitly while retaining every other global/root exception. Organization-history rollback must target its original migration by path now that later migrations exist. These are necessary additive-schema expectation updates, not removed/weakened negative coverage; their current integrity assertions stay intact.

Concurrency-test support: add `tests/Support/identity-worker.php` for guarded real two-process account-token issuance/consumption and old-snapshot tests, using the established existing concurrency-test approach. Recovery also binds each token to the current contact/password digest, so a subsequent credential/contact change invalidates an outstanding token. No new production service or package is introduced.

## Validation and physical deliverables

Test same-address/different-password namespaces, ASCII/case/Unicode validation, platform/legacy isolation, immutable binding and raw cross-tenant membership/pivots; populated MySQL legacy/history/retry and rollback refusal. Feature tests cover correct/wrong/fake-scope login, session/CSRF/logout, cross-account/revoked/stale/remembered context, generic recovery, signed URLs, account-bound token/expiry/replay/throttle/concurrency and unchanged production panel/entry denial. Existing suite assertion expectations change only where this approved additive schema necessarily changes the inventory; no test or guard weakening.

Run targeted tests then full SQLite/MySQL/Redis and existing unchanged Composer/Pint/build/cache/HTTP CI on final report-containing HEAD. Only guarded disposable quality_platform_ci may run destructive integration checks; no application DB/service/secret modification. Failures are fixed or explicitly marked incomplete.

Private path ignore was verified before writing: `.local-tools/reports/phase-05b-1/32/`. Physically create private-handover.md, migration-and-history.md, test-evidence.md, file-change-inventory.md there, and commit the public report under docs/engineering/reports/32.md. Before every push: status, private tracked-file check, full allowed-path/confidentiality review. Draft PR only, no ready/merge/deploy; independent lead acceptance required.
