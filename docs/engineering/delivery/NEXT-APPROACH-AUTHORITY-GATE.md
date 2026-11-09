# Minimum next-slice authority gate

This is an engineering dependency analysis under Issue53, not a grant proposal or owner ratification. Public Issue12/50 status remains binding. The current J01 identity journey needs no D-002 content privilege.

## Smallest owner decision for the next Approach journey

The owner can ratify a bounded local acceptance matrix first, without implicitly approving all onboarding/delegation features. It must identify the actual eligible principal, operation, institution/unit/resource scope, issuer, revoker and validity boundary. A category name, membership, creator or owning-unit relation alone is insufficient. A local fixture must be explicitly owner-approved, persisted in the isolated test data and evaluated by the actual application Policy; a SyntheticApproachPolicy replacement does not satisfy L4.

| Required row group | Minimum explicit choice | Why it gates the journey |
|---|---|---|
| Initial issuer | Who may establish the first authorized Quality actor or issue the bounded acceptance assignment, and who may revoke it | No bootstrap administrator may be inferred |
| Workspace, list and read | Who may enter the workspace through its structural approaches.access predicate; whether list visibility is institution-wide metadata or assignment-filtered; which named resources/history may be read | Current synthetic capability/list semantics are not owner-approved policy |
| Create and ownership | Who may create, select the initial unit and reassign ownership; institution/unit/resource bounds | No automatic creator/owner-unit privilege |
| Edit | Which actor may update metadata/save drafts/read draft or audit history for which Approach | Actual per-operation resource assignments are required |
| Grant lifecycle | Effective start/end, revoker, whether delegation is allowed in this bounded slice, and required audit | No default perpetual or redelegable grant |

Review/publication, invitations, unrestricted user management and enterprise-wide delegation can remain outside that first bounded draft-workflow slice. They need separate authority before implementation. Existing no-self-approval requirements are not asked again. No answer is prefilled here.

## Separately reviewed protected changes

| Surface | Required or conditional | Exact purpose / evidence required before editing |
|---|---|---|
| app/Policies/ApproachPolicy.php | Required for a real positive assignment path | Its current hasAssignment returns false. Any replacement needs owner-approved semantics and actual same-tenant current assignment checks; preserve all final verification methods and default-deny |
| New additive assignment migration(s), exact filename/schema to be proposed after ratification | Required if durable assignment storage is selected | No current operational assignment store exists; independently review composite tenant constraints, history, atomic DDL/retry/rollback and populated synthetic MySQL expansion before writing |
| config/authorization.php | Required for the existing verifiedActor boundary's approaches.access predicate | Registry is intentionally empty. Approve this exact capability/resource anchor separately; a registered predicate alone is no grant. No Issue50 provisional management capability activation |
| app/Filament/Institution/Approaches/ApproachAccess.php | Conditional on selected list scope/serialization | Current listing returns institution metadata after list authorization. Assignment-filtered listing or changed mutation authorization must be explicitly reviewed and tested |
| app/Authorization/TenantAuthorization.php and app/Authorization/RoleManagement.php | Conditional; no amendment assumed | Current allows() queries live role/permission relations without explicit locking. RoleManagement locks role/membership for its internal mutations; ApproachAccess locks organization/user/membership/resource. Prove a compatible revocation-versus-write lock order with MySQL concurrency tests before claiming strong serialization or changing these shared services |

The existing local/testing Approach workspace is already registered. This first bounded slice does not inherently require changing routes, guards, session lifecycle, panel providers, shared theme, CI or coworker37 files. Do not request or apply blanket approvals. Operational account provisioning/grant-management UI is a separate scope; internal RoleManagement is not an authorized public management boundary.

## Continue safely now

Issue53 verified the real J01 browser-to-MySQL journey with no roles, permissions, capability registration or Policy override. The original demo and development data were untouched. Overall P0/P1/P7 and L4 remain unaccepted while their actual gates remain open.
