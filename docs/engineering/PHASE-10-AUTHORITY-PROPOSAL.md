# Phase 10 authority proposal — Issue 46

2026-11-08. **ENGINEERING CANDIDATE, not a grant matrix ratification.** Received owner categories and responsibilities are not absent; precise issuer/subject/action/resource/assignment/state/expiry/revoker remain OWNER-REQUIRED. Accepted ADR001/002/003 deny-by-default and history invariants remain binding; ADR004 is still PROPOSED. No RATIFIED GRANT is created by this document.

## Explicit authorities and status

| Decision | Status | Evidence and limit |
| --- | --- | --- |
| Register institutional native Panel with runtime local/testing + explicit access checks | APPROVED | Issue46/Phase10; engineering integration only |
| Use isolated synthetic fixtures to execute named operations | APPROVED | Issue46/Phase10; no real user/durable grant/production flag |
| Legacy admin development shell remains separate | APPROVED | Existing tested User/provider contract; not tenant access |
| Platform defaults to tenant-content access | DENY | ADR001/002; no support delegation implemented |
| Membership/owning-unit/creator automatically grants user actions | DENY | ADR002 + received D002/D018 |
| Approve own prepared/modified content | DENY | Received D022 direction; no approval engine or bypass |
| New platform guard/login, production access | DENY | Separate security/Issue13 approval required |
| All actual institution business grants below | OWNER-REQUIRED | No signed precise assignment/delegation matrix |

## Proposed grant record contract — no persistence model implemented

A future reviewed authority record must identify issuer and its authority; principal public ULID and any ratified role; exact action; organization public ULID; resource public ULID or explicit bounded list/create scope; actual-user assignment; scope/state constraints; start/expiry; revoker/revocation time/reason; and an immutable audit receipt. User-provided scope is never authority. A role label or owning-unit membership alone is insufficient. Direct/support/administrative participation must not imply review/approval.

| Action | Candidate subject/context | Resource/assignment boundary | Issuer / revoker | Status |
| --- | --- | --- | --- | --- |
| list metadata | Explicit institutional reader | Exact approved institution/list visibility; no drafts/details inherited | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| create approach | Explicit quality operation assignee | Institution/create scope + within-tenant owning-unit selector; creation assigns no rights automatically | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| view detail | Explicit actual-user assignment | Approach ULID + institution; separately bounded metadata/content visibility | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| edit metadata | Explicit editor | Approach ULID + current assignment + fresh edit proof | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| change owner | Explicit quality operation assignee | Source Approach + destination unit in same institution + current proof | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| edit draft | Explicit preparer/contributor | Approach ULID; append revision; stale proof409; no approval | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| view draft history | Explicit draft-reader assignment | Approach/revision scope, independent of list/detail/edit | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| view audit | Explicit history-reader assignment | Approach + protected metadata/ownership snapshots | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| invite staff | Explicit institution account manager | Invitation institution/target/allowed role limits; no content right inferred | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| assign quality role | Explicit delegator whose bootstrap authority is ratified | Target institution/account and exact sensitive operation scope | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| revoke | Explicit issuer/revoker | Exact appointment/assignment; invalidate ongoing write authorization safely, preserve history | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| review | Independent, explicitly assigned reviewer | Stable target + actual revision; no all-tenant broad role | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED |
| approve | Independent formally authorized approver | Actual target/revision/state + SoD + current assignment | OWNER-REQUIRED / OWNER-REQUIRED | OWNER-REQUIRED; implementation DENY |

**Critical unresolved decision:** who appoints the first Quality & Excellence administrator, and who may revoke that appointment? The initial institution administrator does not automatically receive all quality or document approval powers. Who may delegate, within what scope/expiry, and whether delegation may be propagated must be ratified explicitly. Do not infer these from platform provisioning.

## Test authorization versus operational authority

The shipped Policy denies all assignments and the shipped registry stays empty. Test-only subclasses/capability fixtures are loaded by isolated PHPUnit or the explicit removable disposable browser harness; no environment-only production allow or real privileged seeder. Each fixture names actor/institution/operation/resource; list-only remains independent of details/history/owner change/approval. Fixture issuer may explicitly assign the newly created public resource for subsequent test steps; application creation itself does not grant access.

Test revocation after mount and before persistence, current membership/password changes during a request, stale writes and real MySQL competitors. Future durable grants must share a reviewed lock/current-read protocol with mutations, expiry/revocation, list query scoping and audit. No new assignment table/model or production authority engine is implemented in Issue46.

Source statuses: RECEIVED BUSINESS RULE (owner direction); ENGINEERING CANDIDATE (this design); RATIFIED GRANT (none); OPERATIONAL LOCAL (only actually demonstrated synthetic journey); PRODUCTION GATED (all real access/release). No private owner source is reproduced here.
