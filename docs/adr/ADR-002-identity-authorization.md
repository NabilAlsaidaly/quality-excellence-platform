# ADR-002 — Global identity and organization-scoped authorization

**Status: ACCEPTED — technical architecture, 2026-10-31.**
The engineering lead approved a global identity model with support for multiple organization memberships. Product-owner role and access policy is **not** implicitly approved.

SRS: BR-002..005, ACT-01..06, FR-ID-001..012, D-001/D-002/D-021/D-022/D-023/D-024.

## Decision
- Store global user identity in `users`; a global identity alone grants **no** organizational permission.
- Support a user having **more than one organization membership** through `organization_memberships` (D-021 technical capability selected). Whether cross-organization participation is permitted for a given business role, who can invite and switch, and how invitations are approved require owner policy.
- Resolve an active organization only after verifying an active membership for the authenticated actor; client-controlled org IDs, headers, routes, form fields or tokens do not grant permission.
- Separate platform administration from tenant roles. Platform staff do not gain unrestricted evidence/document access.
- Keep organizational-unit responsibility and approach ownership/reviewer assignment explicitly scoped; never infer review scope from a broad role alone.
- Enforce **deny by default** in backend Policies/Actions/Services for `actor + action + organization + resource/assignment + state` and equivalent queue/download paths.
- Retain an audit trail for membership/permission changes, and test forged org switching, revoked membership, unassigned reviewer, cross-tenant object ID and privilege escalation.

## Not decided by this technical approval
- D-001 exceptional platform support access to tenant content.
- D-002 exact role/permission matrix, delegation and approval authority.
- D-022 whether a contributor can review/approve their own work (separation of duties).
- D-023 the behavior of suspended organizations.
- D-024 invitation/reset/MFA requirements.
- D-008 storage, retention and jurisdiction policy.

**Implementation rule:** Do not implement or seed speculative permissions or bypasses. Laravel's default `users` migration is provisional until an implementation PR explicitly checks compatibility with this ADR. Functional authorization cannot be considered complete until product-owner policies are agreed.