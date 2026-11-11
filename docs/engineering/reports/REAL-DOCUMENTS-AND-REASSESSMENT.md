# Issue 68 — real-document and reassessment persistence

## النتيجة التنفيذية

نُفّذت ملفات R01–R26 المعتمدة، مع تعديلات التوافق C15–C18 فقط. نُفّذ SQL المعتمد على MySQL 8.4 معزول وSQLite في الذاكرة، وأُضيفت قيود الاستمرارية وتاريخ المحاولات والإجراءات الخلفية التي تبقى مرفوضة عبر السياسة الحالية. **هذا تنفيذ لأساس الاستمرارية، وليس اكتمال رفع الملفات وتنزيلها أو إعادة التقييم للمستخدم.** لا توجد منح تشغيلية أو واجهات أو مسارات جديدة مفعّلة. فحص MySQL الشامل ما زال جاريًا؛ لا توجد نتيجة CI لهذا العمل غير الملتزم بعد.

## Scope and authority

Issue [68](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/68), existing branch `feat/mvp-completion-2026-11`, protected base `90894051f249fe9b8aa78af728580bf96ddd9299`. Existing [Draft PR69](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/69) remains unmerged. No reset, branch replacement, force push, deployment or coworker37 edit.

Lead approval selects exact canonical v4 R01?R26 after independently verified full SHA-256, disclosed v3/v4 delta and inventory preconditions. The complete amendment, original contracts, exact SQL review and private receipts remain outside Git. A separate approval admits C15?C18 following reproduced compatibility failures. C01?C14/C19?C21 and new grant backing remain excluded. The separately approved two-fingerprint demo rebase is documented below.

Accepted ADR-001/002/003 and current decision ledger were checked. The SRS references DOC001?003/007?011/013?014, MAT015?016/020, REV003?010/013 and UX005?006 continue to apply. Bounded TEST_ONLY D007/D014 parameters are received; operational Product Owner D002/D007/D014 ratification and T17 integration remain unresolved. No settled local fixture question was reopened.

## Requirements and evidence

| Requirement | Implemented / verified boundary | Remaining acceptance |
|---|---|---|
| PDF/DOCX, decimal 30,000,000-byte maximum | Existing real-byte inspector/private quarantine reused; trusted detected MIME retained by R17 | Real authorized upload journey remains closed |
| Scan and release separated | Durable pending/scanning/clean/suspect/failed/released/release_denied states, lease generation and clean attestation checks | Actual AV engine/signature and independent human-release acceptance |
| Exact-version private download | Unregistered Action checks current actual Policy, tenant/version/release/digest, streams attachment with no-store/nosniff | Explicit operation grants, endpoint, native UI and real-browser SHA readback |
| Safe durable history | Five tenant/fixture tables, restrictive composite FKs, exact approved attempt/submission/review pair | DB operators are trusted; no claim of trigger-enforced raw-SQL immutability |
| Historical reassessment | New immutable submission/review and decision records; original configuration pinned; initial review reuse rejected under locks | New operation admission; full positive Action flow and downstream projection |
| Lower/equal/higher dependency behavior | Existing bounded pure evaluator retained; R Actions expose no effective-state claim | C01/C02 persistence/effective-state and approved notification integration |
| Tenant isolation/default deny | Actual unchanged Policies; old synthetic operations do not authorize any new operation | Positive operation acceptance cannot be claimed before reviewed backing |
| Existing demo preservation | Exactly two authorized source fingerprints updated; HTTP200, eight read-only browser and thirteen SELECT-only checks PASS | Finite assignments retain original expiry; no renewal |

## Exact implementation inventory

The six migration SQL definitions for **both engines** remain byte-for-byte identical to the approved v4 blocks. Added guards do not alter that SQL. No previously applied migration was edited.

| Row | Exact path |
|---|---|
| R01 | `database/migrations/2026_11_13_000001_add_reassessment_parent_key.php` |
| R02 | `database/migrations/2026_11_13_000002_create_document_intakes_table.php` |
| R03 | `database/migrations/2026_11_13_000003_create_document_intake_events_table.php` |
| R04 | `database/migrations/2026_11_13_000004_create_maturity_reassessment_attempts_table.php` |
| R05 | `database/migrations/2026_11_13_000005_create_maturity_reassessment_decisions_table.php` |
| R06 | `database/migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php` |
| R07 | `app/Models/DocumentIntake.php` |
| R08 | `app/Models/DocumentIntakeEvent.php` |
| R09 | `app/Models/MaturityReassessmentAttempt.php` |
| R10 | `app/Models/MaturityReassessmentDecision.php` |
| R11 | `app/Models/MaturityReassessmentEvent.php` |
| R12 | `app/Actions/Documents/RegisterDocumentIntake.php` |
| R13 | `app/Actions/Documents/ProcessDocumentIntake.php` |
| R14 | `app/Actions/Documents/ReleaseDocumentIntake.php` |
| R15 | `app/Actions/Documents/DownloadDocumentVersion.php` |
| R16 | `app/Actions/Documents/ReconcileDocumentIntake.php` |
| R17 | `app/Actions/Documents/CreateDocumentWithVersion.php` |
| R18 | `app/Actions/Maturity/OpenStageReassessment.php` |
| R19 | `app/Actions/Maturity/SaveStageReassessment.php` |
| R20 | `app/Actions/Maturity/DecideStageReassessment.php` |
| R21 | `app/Actions/Maturity/ReadStageReassessmentHistory.php` |
| R22 | `tests/Feature/Documents/RealDocumentLifecycleTest.php` |
| R23 | `tests/Feature/Maturity/StageReassessmentWorkflowTest.php` |
| R24 | `tests/Integration/MySql/RealDocumentLifecycleTest.php` |
| R25 | `tests/Integration/MySql/StageReassessmentIntegrityTest.php` |
| R26 | `tests/Integration/MySql/StageReassessmentConcurrencyTest.php` |

| Compatibility path | Reason / exact boundary |
|---|---|
| `tests/Support/TenantIsolationSchemaContract.php` | Recognize five tables and exact measured constraints; preserve original entries/methods; normalize proven `_ascii` introducers only outside literals |
| `docs/architecture/tenant-isolation-schema-contract.md` | Match actual 46-migration/45-table inventory and TEST_ONLY constraints |
| `tests/Integration/MySql/DocumentSchemaIsolationTest.php` | Explicit reviewed filename manifest; empty R-tail reverse before original rollback fixture; all original assertions retained |
| `tests/Integration/MySql/ReviewMaturityIntegrityTest.php` | Reverse only the empty exact R-tail before original parent-history/failure tests; preserve all original eleven-migration scenarios |

Engineering records updated separately: this report, `68.md`, delivery `STATUS.md` and `RESUME.md`. Complete per-path line counts and source hashes are retained privately before publication. No Policy, shared authorization, application route/provider, package/lock, CI or coworker file changed.

## Database safety and portability

Six additive migrations introduce five tables plus one explicit initial-assessment candidate key. The guarded target requires testing plus forced in-memory SQLite, or actual MySQL8.4 `quality_platform_ci` with explicit local opt-in. The unchanged repository GitHub Actions job is independently recognized for its earlier migration preflight; no CI/global database setting was changed. The private local harness additionally checks owned container labels, loopback port and server UUID. This environment guard is a DDL safety check, not an operational authorization mechanism.

R04/R05 enforce the approved parent state using stored generated references and pin the decision to the attempt's exact terminal submission/review pair. Composite actor references use existing `users(id,organization_id)` order. Acyclic numbered predecessors, one active attempt, unique review reuse prohibition, positive revisions and scenario/classification checks execute on both engines. An initial-stage review is additionally rejected by locked Action ownership invariants; direct privileged SQL is not represented as an authorized application review.

Before **any** reverse DDL, the package checks every new table and protected original document/version/review/submission/assessment history for rows, unknown external references, journal ownership, columns, tenant FKs, unique keys, enforced CHECKs and stored generated expressions. Populated, unjournaled or drifted state refuses reverse DDL. Recovery does not silently adopt an existing table. Missing-parent MySQL DDL fails atomically; SQLite received an explicit parent/candidate preflight because it otherwise accepts an invalid parent reference.

The rollback metadata codec preserves literal bytes and grouping. SQLite's valid canonical `AS(` syntax requires a bounded actual-schema expression fallback because the framework metadata regex expects `AS (`. This does not rewrite the approved SQL or ignore an unknown generated expression.

## Transaction, storage and authorization boundaries

Document registration validates before finalization, quarantines outside SQL, then locks organization ? actor/membership ? Document ? version/intake under the real session and unchanged Policy. Document + Version + intake + registration audit share one transaction. Only a confirmed absent owned attempt can be compensated. Ambiguous commit/unbound bytes are retained for explicit operator review; the reconciliation Action does not delete possibly committed files.

Scanner health/stream I/O occurs outside SQL locks under a finite generation/lease claim; result finalization rechecks current authorization, lease, generation, expiry and exact digest. Clean scan evidence is distinct from release. Human release excludes uploader/creator and requires separate current authority. Download audit means **authorized stream opened**, not proof of network delivery. Revocation is checked at authorization/stream-open boundaries; no global T17 serialization or mid-stream cancellation claim.

Reassessment locks organization ? actor/membership ? Approach ? ordered stage roots ? attempt/review and current Policy. Saving always creates a new immutable submission/review except exact request replay. Decisions validate original configuration, last saved ownership, independent reviewer and exact fingerprints; review update ? approved parent/pair ? decision ? audit is atomic. Stale revisions and request-key conflicts fail closed. Current implementation deliberately supports completed five-stage initial histories only; partial initial cycles remain unsupported.

New document operations (`upload_real`, `append_real`, `release_real`, `download_real`) and reassessment operations are **not** backed by any active assignment. Existing synthetic/create/append/view/answer/review grants do not activate them. Positive real-Policy Action acceptance, compensation under those authorized paths, scanner acceptance and new privilege revocation tests therefore remain gated; no allow-all mock was substituted.

The unchanged review table also requires its foundational `review_stage` assignment FK. R20 requires that **in addition to** separate `review_reassessment` admission, never as a replacement. The activation amendment must address this exact dependency. R-only records decisions/history; no downstream effective projection, reassessment notifications or native customer UI is activated.

## Actual validation checkpoint

| Check | Actual result |
|---|---|
| Meaningful fail-first | Missing new persistence/classes; R17 MIME loss; SQLite missing-parent partial creation; initial-review reuse and exact pair constraints |
| C15?C18 original 18 tests BEFORE | 18 tests /108 assertions; 2 errors,9 failures |
| Same 18 tests AFTER approved compatibility | **PASS18 /737 assertions**; no assertion or case removed/skipped |
| Selected R SQLite | **PASS13 /45** |
| Complete SQLite | **PASS495 /4529**, process-only512MB; first128MB attempt exhausted memory |
| Earlier selected MySQL R24/R25/R26 | **PASS4/19 +4/17 +3/25** before expanded recovery/constraint probes; not additive to a full suite |
| Expanded R25 first run | All-six missing-parent/retry and transaction rollback probes passed; one test harness call-signature error corrected; full rerun pending |
| Complete MySQL8.4.13 | Running on the new exclusively owned server; no PASS claimed |
| Redis8.2.11 | **PASS4 /19**, own labelled loopback container removed afterward |
| Composer | Strict validation/platform requirements/audit PASS; zero advisories |
| Pint | Whole repository PASS; fixes restricted to approved paths |
| npm | Locked offline install PASS; isolated existing build PASS62 modules. Windows wrapper first dropped outDir options; direct npm-cli forwarding corrected, public assets retained |
| Old demo | HTTP200 with owned fixture header; eight actual Playwright read-only checks and thirteen SELECT-only preservation checks PASS. Initial verification URL/404 expectations were corrected without another fingerprint rebase |
| CI | Prior committed head `b9cdf047bc2711a38a1608430d48ab6f62ce7a05`: [43025574535 SUCCESS](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43025574535). **Not current uncommitted source CI** |

Approved C15?C18 source delta (added/removed lines): schema contract322/25 (including formatting), architecture20/0, DocumentSchema74/2 and ReviewMaturity21/0. Original test-method inventories are identical; assertion-call counts increased33?35 and32?33. Original contract entries/method bodies were independently compared. Full53-path staged publication export contains no known private fixture values; offline Gitleaks scanned499,936 bytes with zero findings. These are scope/publication checks, not substitute test assertions.

The actual R26 tests use independent processes/PDO connections, verify MySQL8.4 and InnoDB REPEATABLE READ, observe real lock waits, distinguish old consistent snapshots from current locking reads, reject stale CAS updates and simultaneous duplicate active attempts. They prove bounded persistence races, not new privileged Action admission, shared permission-writer synchronization or T17 completion.

## Existing owner demo and confidentiality

The original browser-90 server/process/database, original40 migration journal, five fresh stages, nineteen finite workflow grants, pristine content/history and 24-hour assignment durations were retained. Only the two separately approved fingerprint values changed after byte-identity and semantic review. Original contract entries and method bodies were independently checked; only approved additions/ASCII metadata normalization differ. Private old/new fingerprints, exact diffs, manifest backup, screenshots and readback receipts are inside the project and ignored by Git.

The existing demo remains pristine for the owner's own interaction; viewing its empty history screen is not proof of populated assessment history. Normal `.env` and public build manifest remain unchanged. The formerly observed unrelated PHP3044 is no longer present; the task neither stopped nor restarted it. Existing server26688 was retained. No private owner documents, credentials, complete amendment or raw credential-bearing logs belong in this public report/PR.

## Remaining gates and next implementation

Full MySQL/CI regression must finish before reporting this foundation as verified. Any additional shared test compatibility change needs its own exact approval with actual failure evidence. C01?C14/C19?C21 remain excluded.

The next consolidated activation request must specify minimum exact grant persistence/issuer/revoker boundaries, Policies/admission, real evidence hydration, independent release and exact-version download, downstream projection/notifications and an isolated native browser harness. Real AV resources/signatures and actual PDF/DOCX browser-to-MySQL/SHA readback remain required. No operational owner ratification, real file serving, T17 resolution, overall Issue68 closure, deployment or self-merge is claimed.
