# ADR-002 — Global identity, organization memberships and scoped authorization
Status: **PROPOSED — product-owner decisions required**, 2026-10-31. Issue #7.
SRS: BR-002..005, ACT-01..06, FR-ID-001..012, D-001/D-002/D-021/D-022/D-023/D-024.

## Proposed structure
- Global `users` stores identity, not tenant privileges.
- `organization_memberships` links users to organizations with explicit status and possibly multiple memberships, **only if D-021 is approved**.
- A trusted active organization context is selected from authorized memberships; no URL, header, form or JWT claim alone authorizes organization switching.
- Platform-level permissions are separate from organization-scoped permissions. Creating/managing an organization never implies access to its evidence or ability to approve its methodology.
- Organizational units own specific approaches. Participation and reviewer scope are explicit assignments, not an unrestricted global role.
- Server-side deny-by-default policies must consider `actor + action + organization + resource/assignment + current state`.
- Preserve audit trails of permission changes. Tenant-aware services, queues and storage enforce equivalent checks.

## Business policy decisions NOT approved
D-001 exceptional support access to tenant content; D-002 role matrix and delegation; D-021 multiple memberships; D-022 self-review/separation of duties; D-023 tenant suspension behavior; D-024 invitation/reset/MFA channels. Do not implement a product authorization model until these are resolved. Laravel's default `users` migration is only provisional scaffolding.

## Acceptance criteria for implementation Issue
Define roles/actions/scope matrix and negative authorization tests. Cover cross-tenant URLs, switching active membership, revoked membership, employee not assigned to approach, background jobs, and Platform Administration without tenant document privilege. Avoid generic super-admin bypass and avoid trusting client-supplied IDs.
