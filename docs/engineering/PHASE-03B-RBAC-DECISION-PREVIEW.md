# Phase 03B — Authentication, tenant context and authorization preview

**PROPOSED — NOT OWNER APPROVED**

Date: 2026-11-02. Documentation only for Issue #16. This is not permission to install a package, seed roles, implement tenant switching or expose private content. Accepted ADR-001/002/003 establish technical foundations; SRS v3.0 and explicit owner decisions govern behavior. Issues [#12](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/12) and [#13](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/13) remain gates.

## Existing foundation

Global `users`, organization-scoped `organization_memberships`, hierarchical `organizational_units`, BIGINT relational keys and public ULIDs are implemented. The Filament 5 panel is a development shell. A membership, a public ULID, local login or a platform staff identity grants no approved tenant-content permission. No role tables, permission package or tenant context resolver is implemented.

## Possible future boundaries

1. Authenticate a global identity using the owner-approved enrollment/recovery/MFA policy. Resolve an active organization from an authenticated, valid membership under the approved lifecycle/switching policy; never trust a submitted organization ID alone.
2. Treat organization-specific role/permission assignments as one input to authorization. Evaluate actor + action + organization + resource ownership/assignment + workflow state. Deny by default. Platform support does not receive implicit tenant access.
3. Laravel Policies make explicit resource-level authorization decisions. Approved Application Actions coordinate domain workflows and transactional writes; controllers/Filament presentation delegate rather than contain business rules. Database tenant FKs supplement these controls without replacing them.
4. Keep an organizational unit's ownership of an Approach separate from any configurable user role called "Approach Owner". A broad role does not prove that a user owns/is assigned to a particular Approach or may approve it. Supporting units do not automatically gain ownership or review authority.
5. Audit sensitive membership and permission changes with actor, organization, target and approved change context, avoiding secrets/private document contents. Retention, audit-read access and exceptional support policy still require owner decisions.

## Candidate package, subject to review

Spatie Laravel Permission **v8 with Teams** is a candidate, not a selected/installed dependency. Its current official compatibility matrix lists Laravel 13 and PHP 8.3+ support; PHP 8.4 fits that declared range. Exact released-version compatibility, transitive dependency constraints, guard strategy and migration/index design must be reviewed against the committed lockfile in a separately authorized implementation task. No dependency resolution/install was attempted here. [Official prerequisites](https://spatie.be/docs/laravel-permission/v8/prerequisites).

A possible Teams mapping uses `organization_id` as the team key, chosen before package migrations. It must not create a generic platform super-admin bypass. Team selection must follow verified membership resolution, and changing context requires clearing loaded role/permission relations. Policies must still enforce tenant and per-resource rules. Package pivots/cascade behavior require review against the platform's historical-record integrity rules. These are review questions, not approved schema changes. [Teams documentation](https://spatie.be/docs/laravel-permission/v8/basic-usage/teams-permissions).

## Permission cache and long-lived execution

Redis permission caching is **not authorized in the current phase**. If separately approved later, define organization/guard/configuration-version isolation, explicit invalidation on committed grant/revocation changes, and handling of in-process loaded relations. A cached permission definition must never become an authoritative membership or workflow decision. Cross-organization cached state must be impossible to reuse. Review package cache behavior and avoid trusting TTL alone for revocation. [Cache documentation](https://spatie.be/docs/laravel-permission/v8/advanced-usage/cache).

HTTP/Livewire requests must establish context before authorization, with middleware ordering/persistence reviewed for Filament/Livewire. Future jobs carry explicit actor/organization identifiers and revalidate membership/permissions at execution. In workers or any long-lived process, set context per execution and clear team/actor/loaded relations in a finally boundary; do not inherit the previous request/job's context. Deny missing, stale or forged context. Test organization A -> B -> A reuse, revocation between enqueue/execution, stale cache and exceptions during context cleanup. No workers or domain jobs are introduced by this preview.

## Owner decision gates

| Decision | Required answer before implementation |
| --- | --- |
| D-001 | Whether exceptional support access is ever allowed, its authorization/audit/expiry; default is no access |
| D-002 | Exact actions, role matrix, assignment/unit scopes, delegation and approval powers |
| D-021 | Invitation eligibility, acceptance/activation, multiple memberships and organization switching rules |
| D-022 | Self-review/self-approval and conflict-of-interest policy |
| D-023 | Suspension effects on sessions, writes, jobs and preserved history |
| D-024 | Enrollment/invitation, recovery channels, reset expiry and MFA requirements |
| D-010/D-019 | Platform/organization panel boundaries, authenticated destinations and UI ownership |
| D-008 / D-027 | Audit retention/access and detailed ownership/domain relationship rules as relevant |

Do not mark the decision ledger or SRS approved from this document. The owner must record answers; engineering then defines narrow contracts and acceptance tests.

## Next engineering phase acceptance proposal

After those approvals, prepare an implementation Issue covering backend context resolution, explicit panel gate and Policies, with positive/negative membership, assignment, forged-ULID/org-ID, expired/revoked access, cross-tenant query/download/job and cache-reset tests. Include an unauthenticated redirect and authenticated unauthorized 403 test. Filament `canAccessPanel()` and related gates must reflect approved policy before rollout. Successful local shell login is only framework smoke evidence, not production approval. [Filament user access](https://filamentphp.com/docs/5.x/users/overview/).
