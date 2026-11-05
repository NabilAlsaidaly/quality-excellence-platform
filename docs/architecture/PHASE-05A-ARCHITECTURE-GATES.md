# Phase05A architecture decision gates

Date: 2026-11-05. Issue [#26](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/26). Base: `2382adce5cfdb826d3caffb23ed61bec99fcf0a3`. **Documentation reconciliation only; no architecture replacement or operational authority is approved here.**

## Authority and readiness

Authorized private requirements were read and compared with current source and implementation. Canonical decision reconciliation is pending. Private source titles, passages, detailed policies, actor matrices and proposed source amendments are deliberately absent from this public document. The private lead handover contains that evidence.

**CONDITIONALLY READY for further planning; dependent implementation is BLOCKED** until the gates below are satisfied. A source/ADR conflict must not be resolved by silently retaining an older assumption or rewriting an accepted decision. No Phase05B feature is implemented.

| Gate | Decision owner / exit evidence |
| --- | --- |
| G0 — Source authority | Owner/editor reconciles current decisions and acceptance scope in the authoritative register, or records an explicit scoped approval with a traceable source. Receipt is not proof of source amendment. |
| G1 — Identity compatibility (D-021, ADR-002) | Owner resolves remaining identity/login semantics; lead accepts the resulting minimal architecture and migration compatibility analysis. No identity option is selected by this PR. |
| G2 — Operational authority (D-002, Issue #13) | Owner/lead ratify the smallest action/resource/scope/state matrix for the selected increment; backend Policies/Actions, revocation and negative tests are defined before any management entry point. |
| G3 — Version/review consistency | Resolve the exact reviewed revision, concurrent edits and immutable historical-reference contract before domain writes/approvals. Preserve old evidence and decisions. |
| G4 — Configurable domain history | Resolve D-003/D-014 and versioned configuration semantics before the dependent module; no invented aggregate result or automatic historical cascade. |
| G5 — Operational evidence safety | Security/operations approve upload handling, private access, deployment location, retention/recovery and verification procedures before relevant exposure. Provisional operating amounts remain proposals. |
| G6 — Pilot acceptance | Owner confirms the complete pilot checklist and supplies authorized test/reference material; a first increment is not the full pilot or a license to shrink it. |

G1/G2 block the next identity/authorized-entry implementation. G3/G4 block their dependent domain slices. G5/G6 block relevant pilot/release acceptance. Issues #4/#6/#12/#13 retain their independent owner/lead gates.

## Current engineering foundation

- Laravel13.39.0, Filament5.11 and a modular monolith with lightweight DDD; no new package or generic architecture is needed for this audit.
- `users.email` is globally unique; password reset storage is keyed by email. Membership uniqueness is `(user_id, organization_id)`, permitting a global user to have several memberships. These are implemented facts, not a newly approved identity policy.
- TenantContext verifies actor/membership; TenantAuthorization checks current persisted scope and the registered capability. The registry is empty. Internal provisioning/role/unit services do not authorize a user-facing administrator.
- Assignment pivots use scoped role/membership FKs. Audit actors reference global users restrictively, including initial provisioning without membership. Any future principal conversion must preserve attribution, assignments and existing history rather than delete or merge identities by email.
- Public ULIDs, composite tenant relationships, restrictive history and the schema gate remain intact. The [schema contract](tenant-isolation-schema-contract.md) explicitly handles root/global/indirect exceptions; it does not require organization_id everywhere.
- The14 migration files match the Phase04C baseline. Its observed20-table/112-column/21-FK/49-index/2-CHECK inventory is historical evidence, supported by unchanged source; no fresh local MySQL introspection or DDL was performed in Phase05A.
- Production/staging panel access remains denied by User::canAccessPanel. A presentation boundary, login session or membership is not a content-access grant.

## Safe design constraints for later proposals

Keep one application/shared database unless a separately approved requirement justifies otherwise. UI areas and security boundaries do not determine application/database/panel count. Prefer the least configuration that makes routing, context and authorization explicit; a second Filament panel is an implementation option, not approval or isolation by itself.

Retain stable internal actor IDs and public resource IDs. A future identity change must cover login/provider resolution, reset-token namespace, invitations, sessions, platform principals, memberships, pivots and historical actors together. Do not casually remove memberships, change applied migrations or infer identity equivalence from equal email addresses.

Future tenant relationships require reviewed same-organization keys/FKs where feasible, immutable version references, restrictive historical deletion and indexes tied to actual query paths. Extend the schema gate alongside approved migrations in their own Issues; do not predeclare a speculative final schema or table count.

Business rules belong in Actions/Services/Policies. Use Laravel validation, transactions, private Filesystem, Notifications and Queues with tenant/actor revalidation before adding packages or a generic workflow/form/rules engine. A permission checkpoint is not a lock over a later mutation. Transactional revision checks and after-commit notification behavior need future tests.

Raw SQL can bypass model/service invariants. The schema gate is not production drift monitoring; audit tables are not tamper-proof storage. No performance, resilience, recovery or legal-compliance certification is inferred from tests. Existing trigger privileges and DDL/journal recovery prerequisites remain applicable.

Official references reviewed: [Laravel13 authentication](https://laravel.com/docs/13.x/authentication), [password reset](https://laravel.com/docs/13.x/passwords), [Filament5 panel configuration](https://filamentphp.com/docs/5.x/panel-configuration), [MySQL8.4 FKs](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html). Installed provider/reset implementations were inspected; framework availability does not approve product policy.
