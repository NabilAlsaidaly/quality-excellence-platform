# Document / DocumentVersion foundation — Issue42

## Status and authority

A is an internal, local/testing-only synthetic-byte foundation. B is genuine Filament5.11 presentation prepared outside panel discovery, not operational. FR-DOC002/003 separate identity from immutable versions; FR-DOC001 remains partial because operational owner/authorization/intake are blocked. FR-DOC006 supports internal bounded reads only. FR-DOC004/005/012/013 guide future historical integrity, not permission to implement Evidence links or approvals. FR-DOC007..010/014 are PROPOSED and011 OPEN. No MIME/size/quota/dedup/lifecycle policy is invented.

Accepted ADR001/002/003 apply; ADR004 remains PROPOSED. Current identity supports trusted legacy operators and institution-bound principals; platform principals are rejected. A structural membership/context is never an end-user grant. No Policy/capability/assignment/route/panel/provider/package/CI changes. Creator is attribution, not owner. D002/G2, D006, D007, D008/011/028, D013/022, D027/030 and Issue13 production remain BLOCKED.

## Persisted contract

| Table | Facts |
|---|---|
| documents | Internal unsigned BIGINT, unique immutable ULID, required organization root FK and creator users FK, both RESTRICT; title255/descriptionLONGTEXT/created_at. UNIQUE(org,id), index(org,created_at,id). Duplicate titles allowed. |
| document_versions | Independent id/ULID, required org/document composite RESTRICT FK and root/actor RESTRICT FKs; positive unsigned version1..4294967295 and UNIQUE(document,version). |
| Bytes metadata | Fixed local disk, unique service-generated storage_key255; lowercase SHA25664 validated by CHECK; nonnegative unsigned byte_size; display-only original_filename255, detected_media_type nullable and currently unused. created_at only. |
| Explicit absences | No owner, reviewer, status, approval/acceptance, soft delete, updated_at, polymorphic target, Evidence linkage or dedup constraint. |

Document and DocumentVersion ordinary Eloquent updates/deletes are denied, guarded against mass assignment, and hide internal identifiers/storage metadata. Public contracts use explicit arrays, not model serialization. Raw SQL/bulk/quiet/event bypass can alter compatible records; this is not WORM or cryptographic historical authenticity. FKs protect relationships and RESTRICT deletion, not truth of bytes or authorization.

## Application and storage responsibilities

CreateDocumentWithVersion owns a Documents-specific current-read boundary and attempt orchestration. It reuses TenantContext, without depending on or broadening CreateApproach. Lock order is organization→current user→membership, then document→latest version. Persisted account/binding/membership is revalidated. Reads can occur under an existing transaction, and locking revalidation defeats an old membership snapshot.

Create/Append writers explicitly reject outer transactions before byte writes. This avoids orphan blobs after a later caller rollback; no fake DB/filesystem transaction is claimed. They run only in local/testing. Internal input is a nonempty, seekable php://memory or php://temp fixture resource at position0; UploadedFile/path/string inputs and unknown authority/storage/owner/status keys are rejected. Filename is display-only and rejects separators/control characters; no MIME allowlist or customer quota follows. Title255/description65535 are technical text bounds.

Preflight verifies current scope and resource, then releases locks. PrivateDocumentBlobStore reserves an unguessable new key exclusively, writes the synthetic stream using the existing private local disk and reads it back, verifying SHA256 and byte count. Paths contain organization/document/version public ULIDs plus random256-bit suffix; filename never controls a path. Local adapter/private physical root is checked; public roots are rejected. Default root remains storage/app/private; Storage::fake provides isolated tests. No Storage URL/temporary URL, symlink, public disk or download response is created.

After byte I/O, a new locked transaction revalidates actor/scope and inserts document+v1 or versionn+1. Identical bytes append an independent version/key; no inferred dedup/no-op. Atomic unique numbers reinforce the current locking read. Byte I/O is outside SQL locks; there is no remote I/O or silent transaction retry. Existing broad organization locks still serialize cooperative operations; no throughput benchmark is claimed.

One store instance owns write/retain/compensation for an attempt, including Append calls; transient container instances must not split that ownership. Normal SQL failure rolls back rows and removes only that attempt's reserved blob. Cleanup failure logs a generic operator-recovery failure and preserves the original exception. A post-COMMIT/ambiguous failure first probes version persistence: committed/unknown outcomes retain bytes rather than risk deleting a committed version. Caller still receives the original error, not false success.

Crash after blob write before SQL may leave an orphan. Crash/connection loss after SQL can leave an uncertain committed outcome. Recovery needs private inspection of DB/blob identity, no automatic broad purge. No retention/reconciler service is invented. Future operational intake needs staging/recovery and approved file/AV/quarantine policies, not merely removal of the environment check.

## Read contracts

ListDocuments provides scoped LIKE-escaped title search, title/created_at allowlisted sorting, page1..1000000/perPage≤100, counts and latest version number through tenant-bound subqueries without N+1. List/show fields: document_public_id,title,description,created_at,versions_count,latest_version_number. No creator contacts, filename/hash/key/path/bytes.

ReadDocumentHistory returns bounded≤20 version metadata in asc/desc number order: document/version public ULIDs, version_number,created_at. Foreign/missing/malformed/numeric identifiers have identical ModelNotFound semantics. Explicit internal verify reloads the scoped persisted version, verifies bytes outside SQL locks, then rechecks current membership before returning digest/size; it never returns bytes or a URL. Filament never invokes this integrity probe or displays hashes.

Lists under an old external MVCC snapshot can show old metadata; current actor/authorization checks use locking reads. No HTTP or user authorization completeness is claimed.

## Filament foundation

Four blueprints live in app/Filament/Documents/Blueprints, not Resources/Pages:
- DocumentTableBlueprint: native Table/TextColumns, service-backed custom records, native search/sort/pagination/empty state, no record/header/toolbar mutation actions.
- DocumentVersionsBlueprint: native Table, descending history via read callback, no Download/Preview/Delete/Replace/hash/filename.
- DocumentDetailsBlueprint: native Schema/Section/TextEntry, public-safe metadata/latest saved version only.
- DocumentFormBlueprint: disabled TextInput/Textarea, nondehydrated fields, native Text and unauthorized disabled Action; no FileUpload.

The shell uses native Filament sections and existing qep-preview/qep tokens/theme. No new CSS system, mockup edits or alternative CRUD frontend. Private payload fields are defensively removed from record arrays before Filament receives them. User-provided content remains escaped. Owner/creator approval is not inferred.

A Livewire harness is defined only in the allowlisted PHPUnit file. It obtains synthetic read results through the real Actions/current TenantContext and never persists UI data. Its explicit synthetic grant is not an application Policy. Native InteractsWithSchemas transitively includes an upload trait; the test harness explicitly denies _startUpload, and no runtime Livewire component or Documents route is registered. Any future integration needs a fresh resource/assignment Policy and upload-surface audit, not reuse of test grants.

Existing local-disk signed-serving support and Livewire framework endpoints are baseline framework facilities; this slice creates no serving route, signed URL or upload component. Known key/ULID alone fails file access tests. Operational storage exposure review remains a release gate.

## DDL, compatibility and deployment

Two additive migrations follow Approach audit: physical inventory24, journal24, base tables26 measured on disposable MySQL. MySQL uses single CREATE per table with inline constraints/indexes; SQLite table/index DDL is transactional. Failure leaves no partial table; existing tables are never adopted or removed automatically.

Newest down refuses before FIRST DROP when any document or version exists, including identity-only documents. Empty rollback/reapply is tested. Populated recovery requires backups, an exclusive write-stopped maintenance window and reviewed forward recovery. Process death between successful DDL and migration journal requires operator inspection. DDL+SQL journaling is not atomic.

MySQL8.4/InnoDB/enforced CHECK/JSON-era engine and appropriate CREATE/REFERENCES/INDEX privileges are prerequisites. Historical trigger/binlog prerequisites, including1419, remain; no SUPER use or SET GLOBAL changes were introduced. Runtime user remains separate from migration operator.

Lead-approved eight-path compatibility scope registers the tables and preserves original fixture targets. A further explicit lead approval adds ApproachSchemaIsolationTest only: it now names its original migrations explicitly, so new Documents migrations cannot substitute for the intended rollback target. Full counts24; selected identity five-path removal leaves19, history one-path removal23, original Approach3/2/1 removals leave21/22/23. Permission offsets16/18 retain earlier targets. Trigger test needs no edit because Documents have no unit FK. No prior negative scenario is removed.

See the plan and permanent report42 for actual tests, failure fixes, visual checks, commit/PR/CI evidence and remaining owner decisions.
