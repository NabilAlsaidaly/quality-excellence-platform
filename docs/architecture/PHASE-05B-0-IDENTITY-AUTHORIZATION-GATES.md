# Phase 05B-0 identity and authorization gates

Version 1, 2026-11-05. [Issue #30](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/30). **ANALYSIS COMPLETE / DEPENDENT IMPLEMENTATION BLOCKED.** Documentation only; accepted ADRs and runtime behavior remain unchanged. Detailed private source evidence, policy comparisons, alternatives and amendment instructions are delivered outside this public repository.

## Source authority and current decision state

Owner-approved business requirements govern. Received answers, canonical source amendment, proposed engineering design, accepted architecture and implemented behavior are separate facts. The accessible canonical source and its revision metadata were checked; it has not been amended in this task. Historical OPEN rows remain historical source state, not grounds to ignore explicit later answers or ask settled questions again.

| Gate | Verified state and exit criteria |
| --- | --- |
| G0 — source reconciliation | Authoritative responses received and compared with the actual source; private editorial proposal prepared. **Source amendment/scoped ratification still required** by the authorized owner/editor. No external edit authority is assumed. |
| G1 — D-021 identity compatibility | Additional remaining functional clarification was received during this task; do not reopen that answered question. **Engineering acceptance required** for the complete account-resolution/recovery/session/integrity/history design and a scoped ADR-002 amendment/successor. No replacement ADR is accepted here. |
| G2 — D-002 operational authority | General actor responsibilities are received; a concrete delegation/action proposal was explicitly supplied as **PROPOSED, not final owner approval**. Owner ratification of the selected increment's actor/action/resource/scope/state/ceiling/revocation matrix and lead review of enforcement/negative tests are required. No operational grant follows from this document. |
| G3 — version/review consistency | D-013 user-visible behavior has an explicit source answer; it is not an unanswered owner question. Revision-bound decisions, transactional concurrency checks and notification timing remain a **technical design proposal** for the dependent domain Issue. D-004/D-022 received rules must be preserved, not reopened wholesale. |
| G4 — dependent domain configuration | D-003/D-014 remain unresolved for their dependent module. **DEFERRED** to that implementation contract; they do not independently block entry analysis. Preserve historical configuration/results and do not invent calculations or cascades. |
| G5/G6 — operational and pilot acceptance | Release-specific operational approvals and complete pilot acceptance remain separate. A first entry increment is not pilot completion; a green test run is not production readiness. |
| G7 — dependency security | Separate reviewed security PR #29 is merged; fresh read-only audit is clean and current main CI passed. The older Phase05A audit failure is historical, not an active dependency blocker. Later PRs must verify their own exact HEAD again. |

G0/G1/G2 remain exit gates for the proposed [Phase05B-1 contract](../engineering/PHASE-05B-1-ENTRY-IMPLEMENTATION-CONTRACT.md). Partial answers must not be downgraded to wholly OPEN, and a proposed permission matrix must not be promoted to an approved grant.

## Verified engineering constraints

At base `78a594669f2b23050098f421848cae131bfcaec0`, the application uses Laravel13.39.0/PHP8.4.27, Filament5.11.0, Livewire4.4.8 and Spatie Permission8.3.2. Accepted ADR-001/002/003 retain shared MySQL, scoped deny-by-default access and stable internal BIGINT/public ULID boundaries.

- Current `users.email` is globally unique; default reset storage is keyed by email. The installed Eloquent provider resolves supplied credentials; the installed reset repository creates, looks up and deletes tokens using its reset identity. A future namespace change must cover provider, broker/token storage, notification delivery, links, throttling, verification and sessions together. A form-only change is insufficient.
- Membership uniqueness is `(user_id, organization_id)`; membership and organization lifecycle/status are not implemented. Existing global User/membership semantics and accepted ADR-002 need explicit compatibility review. No inference of account equivalence or administrator authority is permitted from contact attributes.
- Preserve stable user IDs, role/membership composite keys, restrictive actor/subject/history references and existing audit attribution. Do not merge by email, rewrite history or edit applied migrations. `sessions.user_id` has no FK; polymorphic role discriminators and supported authenticatable models need deliberate review before any change.
- TenantContext verifies persisted actor/membership and restores context; TenantAuthorization rechecks current resource scope and role-derived registered capabilities. The registry ships empty; supported resource types are bounded. Neither context nor an internal RoleManagement/OrganizationProvisioning call authorizes a management interface. Future feature Policies/Actions must add explicit assignment/state constraints and current transactional authorization.
- User::canAccessPanel continues to deny staging/production. One panel exists with three admin routes; there is no operational account/invitation/delegation interface. Presentation areas do not imply a fixed panel/application/database count or grant private-content access.

## Review requirements for any later identity change

The private proposal compares three options, explains the preferred design under the received clarification, and rejects incompatible shortcuts. It covers account namespaces, independent authentication, platform separation, recovery collisions, enumeration, invitations, verification, session/logout/remember behavior, revocation, future MFA readiness, role pivots, audit actors and migration compatibility. It is **PROPOSED engineering**, not a public replacement ADR or executed schema plan.

Require a separately authorized populated-data inventory before backfill; this task did not inspect application rows. Additive expansion, explicit ambiguous-identity handling, compatible key enforcement and controlled cutover must preserve history. Never enable a new namespace while an incompatible old resolver remains exposed. An old application rollback may become unsafe after new semantics are written; define that boundary and fail closed rather than deleting records to recreate old uniqueness.

The [schema contract](tenant-isolation-schema-contract.md) and [authorization contract](tenant-authorization-foundation.md) remain authoritative descriptions of implemented guarantees and limits. Extend them with the actual approved feature, not speculative entities or a predeclared final table count. No general delegation/workflow engine, new dependency, service split or database split is justified by this analysis.

## Technical references and limits

Verified [Laravel13 authentication](https://laravel.com/docs/13.x/authentication), [password reset](https://laravel.com/docs/13.x/passwords), [authorization](https://laravel.com/docs/13.x/authorization) and [transactions](https://laravel.com/docs/13.x/database) alongside installed13.39 code. Laravel supports credential predicates and configurable providers/brokers; these mechanisms supply no business permission. Policies must enforce scoped actions without an unconditional allow callback.

[MySQL8.4 foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html) require compatible keys/types/indexes; use reviewed unique candidate keys and restrictive history references without changing global server settings. [Filament5 user access](https://filamentphp.com/docs/5.x/users/overview), [installation/panels](https://filamentphp.com/docs/5.x/introduction/installation) and [persistent tenant middleware](https://filamentphp.com/docs/5.x/users/tenancy), plus installed5.11 code, distinguish panel entry, backend resource authorization and subsequent Livewire requests. The requested panel-configuration page timed out; verified official alternatives and installed source supplied evidence. No plugin example or obsolete version is adopted.

Software Engineering at Google [review](https://abseil.io/resources/swe-book/html/ch09.html), [documentation](https://abseil.io/resources/swe-book/html/ch10.html) and [testing](https://abseil.io/resources/swe-book/html/ch11.html) inform small reviewable work and traceable verification; absent local reference books are not claimed read. None overrides approved product requirements.

See the [permanent report](../engineering/reports/30.md) for actual verification. Stop before implementation and retain Draft status for engineering-lead review.
