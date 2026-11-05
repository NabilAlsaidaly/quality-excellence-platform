# Phase 05B-1 authorized entry implementation proposal

Version 1, 2026-11-05; prepared under [Issue #30](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/30). **PROPOSED NEXT CONTRACT — NOT APPROVED FOR IMPLEMENTATION.** No code, schema, credential, route, permission or panel change is authorized by this document. The lead must open a separate implementation Issue after the [G0/G1/G2 gates](../architecture/PHASE-05B-0-IDENTITY-AUTHORIZATION-GATES.md) pass.

## Preconditions requiring explicit evidence

1. Reconcile affected source clauses/acceptance with received owner answers through the authorized source editor, or record an explicitly scoped ratification. Retain approval provenance privately; public Issue content remains sanitized.
2. Lead accepts a scoped ADR-002 amendment/successor and complete identity/recovery/session/key/history compatibility design. Accepted ADR-001/003 and unaffected deny-by-default guarantees stay intact. No older implementation assumption silently overrides a received requirement.
3. Owner ratifies the exact minimum actor/action/resource/scope/state/revocation matrix for the selected increment. The detailed proposal received in Phase05B-0 is not final approval. Approve grant ceilings and separation of duties explicitly; no generic platform/tenant administrator default.
4. Approve deployment-compatible schema/backfill/rollback planning if required. Do not infer actual legacy rows from fixtures or authorize production access because the architecture is reviewed. Define any institution locator and external account identifiers without exposing internal numeric keys or a private directory.
5. The new Issue pins the approved requirements revision, acceptance IDs, design decision, exact file allowlist and environment-specific tests. No package or CI/deployment change without independent authorization.

Relevant canonical IDs to reconcile privately: BR-001/002/034/035, FR-ID-003/004/005/006/008/010/012 and corresponding AC/T-ID; NFR-SEC-001..005/009/011; DATA-02/09/10; AC-GATE-001/002/027/028. These references do not promote the canonical document's older proposals or stale examples into approval.

## Smallest proposed vertical slice

Demonstrate authorized account entry, verified institutional context, a minimal permitted Arabic RTL destination and logout using isolated, explicitly authorized fixtures. Repeat after logout/login with two institutions and independent test principals. Resolve recovery/credential namespace compatibility before any related path becomes reachable; a partially adapted resolver or ambiguous recovery path is unacceptable.

Choose one bounded delivery: identity-compatible storage/provider/recovery foundations may need their own preceding PR if too large to review with entry. Do not bundle all account administration, role delegation, invitations, document approval and pilot modules into entry. A flow outside the ratified matrix stays unavailable. Any intentionally deferred recovery/invitation UI must remain unregistered, with no incompatible old path reachable; the dependency contract must still be designed and tested where implemented. No seed/default privileged user or real institutional account creation is part of acceptance.

Reuse existing context, scoped Gate/Policies, role infrastructure and framework mechanisms. Platform provisioning remains a distinct approved boundary; do not weaken membership verification to make it convenient. Every HTTP/Livewire request/update must resolve/clear trusted context and recheck current account/institution/resource eligibility. Bind sessions and remember behavior to the approved account semantics; a cached context is not authorization. State enforcement must honor the approved lifecycle contract, without inventing names or granting writes where only reads are permitted.

## Candidate paths for later approval

The future Issue must convert these candidate areas to an exact file list before work; they are **not an allowlist for this phase**:

- Narrow identity application Actions and any necessary framework provider/broker adapter; current User/context/authorization changes only where the accepted design demonstrates need.
- New additive migrations and narrowly scoped model relations/constraints only after populated-data compatibility review; never rewrite applied migration files.
- Specific authentication/Policy/presentation adapters, explicit route registration and minimal Arabic strings/views; no whole-directory permission or speculative panel split.
- Focused Unit/Feature/MySQL tests and, when schema actually changes, explicit updates to the existing schema contract and its documentation.
- The next Issue's plan, deployment/rollback instructions and permanent report.

Dependency manifests/locks, unrelated migrations/Actions, contributor UI branch, broad application refactors, CI/settings and deployment/infrastructure files remain excluded unless separately reviewed. Filament presentation must call backend authorization; no business rules in views/resources/controllers.

## Required acceptance evidence before review recommendation

| Boundary | Positive proof | Negative / cross-tenant / concurrency proof |
| --- | --- | --- |
| Account lookup/authentication | Correct approved namespace and credentials reach only the selected principal/context | Wrong scope/credentials, forged locator, ambiguous/missing/disabled principal, platform-vs-institution confusion; generic responses without account/directory enumeration |
| Recovery/verification where implemented | Valid token resolves exactly the intended principal and updates only that credential/verification state | Wrong account/scope/token, expiry/reuse/throttle, concurrent reset tokens, collision and old resolver reachability; mail destination is not the token identity |
| Context/session/logout | Fresh context on each request/update; session regeneration and logout invalidation; repeatable isolated journey | Forged IDs/header/session, stale context across requests/jobs, remembered wrong principal, intended-URL cross-scope redirect, CSRF failure, revoked membership/grant |
| Minimum authorization | Only a ratified action on its allowed resource/scope/state succeeds | Unknown capability, direct package grant, role-name bypass, unassigned resource, privilege escalation, self-grant, missing assignment, disallowed state, absent delegation ceiling |
| Transaction/revocation | Current authorization and mutation/audit are atomic under reviewed lock order | Grant/membership/state revoked between earlier request check and write; stale MVCC snapshot; rollback has no committed grant/audit/notification side effect |
| History/schema if changed | Existing actor IDs/references and new namespace uniqueness/FKs remain valid on populated synthetic fixtures | Legacy multi-membership ambiguity, cross-tenant pivot, duplicate/collation collision, restrictive historical deletion, partial DDL/journal failure and ordinary retry; incompatible down/old application rejected safely |
| Presentation/security | Arabic RTL, unauthenticated denial, authorized minimal landing, safe errors | Nonapproved production/staging entry remains denied; hidden button never supplies authorization; no unapproved management/directory/private-content route |

Extend job/notification/download enforcement only when that feature is in the new Issue; record dependencies now without adding a generic system. Revalidate actor/resource scope when those later paths execute. Domain authorship/version approval tests belong to the revision slice, not a newly implemented review engine inside entry.

Run meaningful feature tests plus the entire existing SQLite, guarded disposable MySQL8.4 and real isolated Redis suites, strict Composer validation/platform/audit, formatting/build and unchanged cached-runtime/HTTP CI. Do not weaken or rename existing checks to mask incompatibility. Require actual exact-final-HEAD CI. Real-data backfill, deployment, recovery/performance/legal readiness need their separately authorized evidence; CI alone does not establish them.

## Migration and rollback planning requirements

Inventory/classify only under explicit data-access authorization; no email-based merging or history rewriting. Expand compatibly, handle ambiguous mappings explicitly, switch all dependent entry/recovery paths together, then enforce reviewed constraints. Keep current membership/role/history FK targets stable where feasible. Define uniqueness for every namespace without relying on nullable-key assumptions. MySQL DDL/journal crash windows remain operator-recovery concerns.

Before new semantics are written, unused additive expansion may remain safely dormant. After new namespace/account data exists, restoring old code or uniqueness may be unsafe; fail closed and use a reviewed forward correction. Never delete identities/assignments/history to make rollback appear successful. Test both compatibility boundaries against populated synthetic fixtures. No actual migration/backfill/rollback is performed by Phase05B-0.

## Explicit stop and deferred scope

Stop if G0/G1/G2 lacks approval, a new owner detail changes the selected contract, identity/history mapping is ambiguous, an unapproved permission/schema/package/panel is required, tenant isolation fails or the diff exceeds the approved new Issue. Report the reproducible conflict; do not broaden implementation autonomously.

D-003/D-014 gate their dependent module, not this entry analysis. Review/evidence/versioned configuration, document approval, full user/delegation management, maturity, mapping, operational upload/retention/residency/recovery, external assessment/audit and complete pilot acceptance remain separate increments. The first slice is not the pilot.

Use verified [Laravel13 authentication](https://laravel.com/docs/13.x/authentication), [passwords](https://laravel.com/docs/13.x/passwords), [authorization](https://laravel.com/docs/13.x/authorization), [database](https://laravel.com/docs/13.x/database), [Filament5 access](https://filamentphp.com/docs/5.x/users/overview) and [MySQL8.4 FK](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html) behavior with installed-source review. No framework example supplies owner policy. Work via new Issue → plan-first branch → validation → Draft PR → lead review; no self-merge or deployment.
