# Tenant authorization foundation

**IMPLEMENTED / TESTED technical foundation — no approved business permission matrix.** Issue [#18](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/18), Phase 03C (report naming: Phase-03B-B), 2026-11-03. Authority: accepted ADR-001/002/003; SRS BR-001..005, FR-ID-001..012 and DATA-01..10. Business decisions in [the ledger](decision-ledger.md) and Issues #12/#13 remain open.

## Boundaries

Global User authentication uses the existing web guard. Membership is the separate structural organization_memberships relationship; Spatie Teams never grants membership or implements invitation/activation/suspension. Role names are organization-owned data. Permissions are stable registered application capabilities. Resource ownership is a separately persisted organization relationship, never inferred from a role name. No Approach model, business action, privileged seed, public role-management surface or tenant-switch UI is introduced.

All application authorization must use TenantAuthorization or Gate::forUser($actor)->allows('tenant-capability', [$capability, $resource]) inside a trusted execution boundary. The default capability registry is empty, so a normal installation grants nothing. There is no unconditional Gate::before callback. Package automatic permission-to-Gate interception is explicitly disabled; it would otherwise bypass membership/resource checks.

## Trusted context and lifetime

TenantContext is Laravel-scoped, starts empty and exposes run(?User, int|string, Closure). Supply an actor obtained from authentication, or a persisted actor reloaded from trusted job payload, never a request-submitted user ID. Integers mean internal organization keys; strings mean public ULIDs. The service queries the organization and current database membership/user on entry. An existing organization identifier alone grants nothing.

The callback boundary restores the previous nested context in finally; the outer boundary restores null, including validation failures and exceptions. Passed actor roles/permissions relationships are cleared on entry/exit. TenantTeamResolver reads the currently scoped context at use time rather than capturing it in the singleton PermissionRegistrar. Arbitrary non-null setPermissionsTeamId calls throw; null only clears context for package lifecycle cleanup. Laravel scope reset and explicit consecutive/nested boundaries are tested. Retained model instances cannot grant trusted access through stale relationships.

TenantAuthorization revalidates the user/membership/organization, actor identity, registry resource type and persisted resource organization on every check. Only Organization and OrganizationalUnit are supported resource types. It then issues an exists query through the package roles/permissions relationships, constrained to the current organization and web guard. Direct user permissions, role names and cached definition/assignment collections confer no application grant. Unsaved resources and caller-modified organization_id attributes do not bypass the persisted lookup. Database/cache exceptions are never translated into allow.

Future feature Policies/Actions must add their approved assignment/state constraints around this technical predicate. Registering a capability is not approval of a workflow. There is no tenant data endpoint in this phase. Do not use raw Spatie hasRole(), hasPermissionTo(), @role, @haspermission or package middleware as tenant authorization: those APIs do not establish all these requirements. Blade/Filament presentation must call the approved Gate/Policy and backend Actions must enforce it again.

Future HTTP/Livewire adapters must establish and clear context on every actual request/update, verify membership and public-ID binding, and review persistent middleware ordering. Nothing reads a session/header/request organization automatically. Future jobs must carry explicit actor/organization identifiers, revalidate on execution and use run in finally-safe processing; revocation between enqueue and execution must be enforced. No domain job, worker deployment or Octane integration is introduced. Concurrent/fiber execution within one scoped instance is unsupported; an approved adapter must create isolated scopes. A permission check is a current database checkpoint, not a lock over a later unrelated action: future sensitive actions need transactional revalidation and appropriate locks.

## Internal role management

RoleManagement is trusted internal application infrastructure, with no route, Filament Resource, controller, Livewire component or user-facing command. Its methods require a verified context; that requirement is a structural prerequisite, **not authorization for an organization member to administer roles**. No current application-user call site invokes them. A future management interface must first apply an owner-approved management Policy/capability and then invoke these methods rather than duplicating scope/membership logic.

| Method | Behavior |
| --- | --- |
| list | Current organization's role definitions only |
| create / rename | Configurable 1..125 character name, web guard, immutable organization scope; same name may exist in separate organizations |
| syncPermissions | Recognized registry keys only; creates definition rows as needed and uses package sync; empty list revokes inherited capabilities |
| assign / revoke | Fresh target membership, package relationship mutation scoped to current organization |
| delete | Reject referenced assignments; explicit permission-link removal then delete unassigned definition |

Writes run in a database transaction, lock the actor membership and selected role, lock target membership where relevant, and insert a security change record atomically. Database unique constraints handle concurrent duplicate names; Spatie rejects ordinary duplicate creates earlier. The service propagates failures rather than silently granting/retrying privileged work. Assigned-role deletion is denied both by service and restrictive database FKs. Revoke assignments before deleting a membership; no implicit bulk removal process is approved.

config/authorization.php is the application capability registry, mapping stable literal names to supported resource classes. It ships empty. A future reviewed feature adds a recognized capability, its Policy/Action/resource constraints and tests together; a future authorized role interface may only choose these recognized capabilities. Synthetic fixture names exist only in isolated tests. Users cannot introduce arbitrary strings to unlock features.

Spatie ships mutation commands; container bindings replace create-role/create-permission/assign-role/setup-teams with explicit refusal (exit 1). Read-only inspection/cache reset remain operational tools. Tinker or direct SQL/application code are trusted operator facilities, not user APIs; their availability is not a role-management permission.

## Schema and deployment

Spatie Permission **8.3.2**, locked at e4e906f8ca5fe10fbe4b6a81f6c4e544a01d8053, supports PHP ^8.3 and Laravel 12/13. Existing Laravel 13.39.0/PHP 8.4.27 and the package-tools dependency already installed by Filament satisfy it. Exactly one package was added; existing locked versions were preserved. Teams is enabled before new tables, team key organization_id, web guard; no new guards or wildcard/global grant.

The default published package migration is deliberately replaced by six additive journaled migrations:

| Table | Scope and integrity |
| --- | --- |
| permissions | Global capability-definition allowlist extension; unique name/guard; no user grant or tenant content |
| roles | Non-null organization FK, unique organization/name/guard and organization/id candidate key |
| role_has_permissions | Global-definition-to-scoped-role link; restrictive FKs, no cascades |
| model_has_roles | Organization scope, composite FK (organization_id, role_id), composite membership FK (model_id, organization_id) |
| model_has_permissions | Package-compatible direct pivot with membership/permission FKs; unused by trusted role management and ignored by application authorization |
| authorization_changes | Tenant security history; actor/subject user and organization restrictive FKs; event, IDs, timestamp only |

All internal identifiers remain unsigned BIGINT on MySQL. Public ULIDs and original schemas/triggers are unchanged. No roles are nullable/global. Assignment tenant and membership integrity is enforced by actual MySQL FKs, not application assertions. Package polymorphic model_type remains for compatibility, but the database membership FK fixes model_id to global users. Only App Models User is supported; the discriminator is checked by Eloquent relationships rather than a DB class-name constraint. Adding another authenticatable model or morph map requires an explicit schema/design review. Guard/capability registration and model_type remain application invariants; raw SQL is not an authorized interface.

Each migration uses a single CREATE TABLE statement with inline keys/FKs for MySQL and SQLite; MySQL CREATE/ALTER splitting in a normal schema Blueprint could otherwise leave a partially constrained table. Missing referenced-table failure is tested to leave no new table and allow ordinary retry. Each successful table is journaled independently. No post-DDL cache invalidation can fail a migration. Pre-existing tables are never adopted/replaced. MySQL DDL still commits outside application transactions: process death/disconnect between CREATE and journal insertion requires operator inspection. Do not mark an unverified schema applied or automatically drop/adopt a table. Earlier successful migrations remain applied and a normal rerun resumes pending migrations after the actual prerequisite is fixed.

Deploy using the existing [separate migration operator procedure](../setup/herd-windows.md) in a maintenance window. Review target/backups/pending SQL first. The new migrations need CREATE, REFERENCES and metadata access; rollback additionally needs DROP and approval. Fresh installs also need the unchanged Phase03A trigger/binlog/cleanup privileges described in [Redis/setup prerequisites](../setup/redis-local.md). No global MySQL configuration change is introduced. A host that prohibits required triggers remains a deployment blocker. Runtime credentials stay DML-only. Do not publish or regenerate the existing private credentials.

Only additive migrations were executed against the verified development MySQL 8.4 schema. Rollback/fresh/recovery tests run exclusively in the opted-in disposable quality_platform_ci schema. Before/after original tenant table DDL fingerprints, row counts, both triggers, globals and private environment/service-file hashes were checked; no baseline alteration or privilege elevation. Native MySQL/phpMyAdmin is separate and untouched.

authorization_changes records management events with organization, actor, role ID snapshot, optional subject user ID and timestamp; no names, emails, secrets, permission lists or private content. It retains the role ID after safe role-definition deletion. There is no role FK for this historical snapshot. No route exposes audit data, and no purge/edit API is added. This is transactional application change history, not tamper-proof storage or a complete security monitoring system; the runtime DML account/operator can modify raw tables. Retention, residency, read access and eventual membership auditing remain owner gates. FKs intentionally prevent deleting referenced user/organization history.

## Cache and Filament

Permission definitions use the documented array store; shared Redis is not used for permission definitions/decisions at this stage. Spatie invalidates definition caches on role/permission changes, while user assignments can remain in loaded relations. The trusted authorization query uses current database relationships and ignores both sources of stale state. A real throwing cache-store fixture verifies that a revoked grant cannot reappear and a failed cached mutation rolls back. This is conservative and has a query cost; no benchmark or performance improvement is claimed. Membership is never cached as authority. Redis remains available for the existing namespaced cache/queues; no flush command is used.

User preserves authentication/password/hidden attributes and memberships while adding HasRoles and FilamentUser. canAccessPanel allows only the existing admin panel in local/testing; every production/staging/other environment is denied. /admin, /admin/login and /admin/logout stay unchanged; local shell access grants no tenant capability. AdminPanelProvider and every contributor-owned UI path remain untouched.

Issue #13 still must define owner-approved panel boundaries, enrollment/recovery/MFA, authorized policies/resources and backend Actions, revocation/session behavior, HTTPS/cookie/CSRF production configuration, sensitive-action audit/read policy and rollout/rollback tests. This fail-closed gate is not production authorization approval. Review the separate UI contributor PR before any integration.
