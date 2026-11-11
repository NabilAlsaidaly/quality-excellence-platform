# Issue68 — gated persistence and isolated source correction

## النتيجة التنفيذية

نُفّذت التصحيحات الأربعة المعتمدة في worktree معزول، مع إبقاء النسخة الحية والعرض وملف الاتصال والبصمات كما هي. اجتازت الحالات الـ17 السابقة الفحص الفعلي، وأُضيف تحقق صارم قبل التراجع وبعده، واختبار فعلي لإخفاق تنظيف Laravel. نجح التحقق المحلي الشامل في773 اختبارًا و7966 تحققًا دون أخطاء أو إخفاقات أو تخطٍ؛ وCI على النسخة المرشحة يلي نشرها؛ لا يُدّعى قبول ميزات الملفات أو إعادة التقييم أو أي صلاحيات تشغيلية.

## Authority and submitted source

Issue [68](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/68); existing [Draft PR69](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/69), feature branch `feat/mvp-completion-2026-11`. Protected main/base remains `90894051f249fe9b8aa78af728580bf96ddd9299`; correction starts from `4b81e88cbbd59a0280f58957a375eb2bdb2f5e8a` in a separate detached validation worktree. The live branch/HEAD and all five existing dirty files are preserved. Candidate commits may be pushed normally to the same remote feature branch; live-checkout promotion and fingerprint writes are **not authorized**.

The Lead conditionally approved four exact test/support source paths, preservation of the already-authorized R25 negative and existing evidence, strict journal preflight/postconditions and actual Laravel failure injection. No product source, migration/SQL definition, Policy/permission/authorization, route/provider, dependency/lock, CI or coworker37 file changes. Accepted ADR-001/002/003, task Issue and decision ledger were rechecked. No engineering approval ratifies operational owner D002/D007/D014 policy. T17 remains deferred.

Relevant original requirements: DOC001-003/007-011/013-014, MAT015-016/020, REV003-010/013 and UX005-006. This correction restores regression coverage and fixture safety; it creates no new business rule, permission or user-facing capability.

## Persistence foundation retained

Previously approved canonical v4 R01-R26 implements six additive migrations/five tenant+fixture tables, guarded persistence models, unregistered real-document Actions and immutable reassessment Actions. Exact SQL for both engines remains unchanged. Approved-attempt generated FK and exact attempt/submission/review pairing, independent reviewer Action invariants, immutable original configuration/history, one active attempt, CAS/idempotency and populated rollback refusal remain.

New file operations and reassessment operations are still denied by the unchanged actual Policies. Synthetic grants do not activate them. Real scanner/signatures, operation-grant backing, native new-feature UI/download, effective downstream reassessment projection and notifications remain separate gates. R-only persistence and this test correction are not full feature/operational acceptance.

## Exact changed paths for this correction

| Path | Change and necessity |
|---|---|
| `tests/Support/ApproachAssignmentFixtures.php` | Reverse only the complete, exactly journaled, EMPTY approved R tail before the original workflow fixture; reject partial/unknown/inconsistent state before DDL and assert exact journal/table/key postconditions |
| `tests/Integration/MySql/ApproachSchemaIsolationTest.php` | Exact46-file inventory plus equality; additional real-MySQL journal inconsistency/absent-state tests and identifier/literal regression. Original13 direct test methods and all original assertions retained; inherited scenarios unchanged |
| `tests/Integration/MySql/InstitutionPanelJourneyTest.php` | Original cleanup retained inside try/finally; restore testing before actual framework teardown even when cleanup throws. Original HTTP/local-CSRF scenarios unchanged |
| `tests/Support/TenantIsolationSchemaContract.php` | One bounded regex alternative handles only actual ASCII/UTF8 charset introducers directly before literals; preserves identifiers/backticks and quoted content |
| `tests/Integration/MySql/StageReassessmentIntegrityTest.php` | Preserve the already-authorized, actual MySQL ASCII identifier/literal negative test byte-for-byte from the live uncommitted evidence; it now passes |
| `docs/engineering/reports/REAL-DOCUMENTS-AND-REASSESSMENT.md`, `68.md` | Current scope, actual failures, tests, risks and review boundary |
| `docs/engineering/delivery/STATUS.md`, `RESUME.md` | Durable candidate state and explicit no-promotion/no-fingerprint gate |

These are the only nine candidate paths beyond4b81e88. The four newly approved corrections are test/support code. R25 and report evidence existed before this approval and are retained in the candidate without committing or changing the live checkout.

## Root causes and fixes

The4b81e88 full run and [CI43060745091](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43060745091) both reported266 tests/3032 assertions,16 errors/1 failure. Twelve errors came from reversing original workflow parents while the new empty FK children remained; the unchanged historical guard correctly refused. Four HTTP cases deliberately used local for actual CSRF but left that state for MySQL teardown; the new testing-only migration guard correctly refused. One old inventory assertion expected40 filenames rather than46, preventing later schema assertions from running.

The fixture now verifies unique known journal entries/positive IDs and batches; requires all six entries in the exact ordered tail and one cohort when present; checks complete schema presence, exact parent/original keys, unknown persistence objects and the existing full data/shape/constraint down guard before the first reverse command. A fully absent tail is accepted only when its tables and parent key are absent. The helper uses the real migration down methods; no raw purge, disabled FK, bypass or swallowed exception.

After rollback it compares all remaining journal rows including original IDs/batches, all retained table names and absence of the added key. No original workflow reverse begins if these postconditions fail. Laravel returns qualified table names by default; the first postcondition test exposed this API detail, corrected with `schemaQualified: false` on both metadata reads, without weakening the equality.

Five new fail-first cases (missing/duplicate/unknown journal, inconsistent cohort, missing table) originally attempted DDL. Actual MySQL journal/metadata probes now refuse before a spy sees any DDL request; exact mutated state is preserved and only each owned test mutation is restored. A separate positive test executes the actual six-migration reverse, asserts exact postconditions and proves the absent state is a no-op. The missing-table negative explicitly verifies the owned table is empty before removing that fixture.

The original `_ascii` alternative also erased a suffix inside an identifier. Real metadata regression proved the same problem for the existing `_utf8mb4` alternative, so the same literal-introducer boundary now covers both recognized forms. Actual MySQL tests preserve `source_ascii`, `source_utf8mb4`, quoted `_ascii` identifiers and charset text inside literal values. This remains a bounded metadata codec, not a generic SQL parser.

## Actual Laravel teardown failure injection

A private reproducible PHPUnit harness instantiates the actual candidate InstitutionPanelJourneyTest through its real setup/teardown methods against the verified disposable MySQL server. It injects an exception in the real connection's first owned cleanup DELETE. A second case also fails a framework destruction callback. Both assert the actual DatabaseMigrations rollback completed, later callbacks saw app/config testing, the application was flushed and lifecycle state reset, and the original exception remained visible/chained. A genuinely fresh application/test instance then verifies no environment/callback failure leaked.

Result **PASS2 tests/23 assertions**, separate from public suite totals. No Policy or application admission was mocked. The earlier pure-PHP finally demonstration is not substituted for this actual framework evidence. The private harness, source hashes, invocation and JUnit receipt remain inside the project and outside Git; the public original four HTTP scenarios also run in complete regression/CI.

## Actual verification checkpoint

| Check | Result |
|---|---|
| Original17 failing cases | All17 matched individually to passing actual candidate JUnit records |
| Complete affected MySQL suites | PASS58 tests/938 assertions, zero errors/failures/skips |
| New journal fail-first | RED5 tests/5 failures before stronger preflight; no premature DDL executed by the spy |
| Focused journal/postcondition/ASCII tests | PASS7/34 before adding the explicit empty-fixture assertion |
| Actual ASCII and UTF8 metadata final tests | PASS2/4; real UTF8 fail-first retained before final boundary correction |
| Actual Laravel failure injection | PASS2/23, zero errors/failures/skips, private independent harness |
| Complete SQLite | PASS495/4529, final-source complete suite; zero errors/failures/skips |
| Complete MySQL8.4.13 | PASS274/3418 on MySQL8.4.13; zero errors/failures/skips |
| Redis8.2.11 | PASS4/19; fresh labelled loopback container removed afterward |
| Composer | Strict validation/platform/audit PASS, zero advisories |
| Pint | Candidate repository PASS; final check repeated before publication |
| npm | Locked offline install and existing isolated build PASS62 modules; no package/lock or live asset change |
| Exact-head CI | Pending candidate publication/verification; earlier4b81e88 CI failure is baseline evidence, not candidate success |

Complete local total: **773 tests/7966 assertions,0 errors/0 failures/0 skipped** (SQLite495/4529 +MySQL274/3418 +Redis4/19). The private actual Laravel injection2/23 is separate. Every original17 case also matched a passing record in the complete MySQL JUnit, not only in the targeted subset. Counts are actual; subsets are not added to the full suites. Full exact-head results and source identity are recorded in Draft PR/private final receipt after the containing commit exists. No identifier-only commit is needed to record its own SHA.

## Isolation, preserved state and remaining review gate

Dependencies/autoload were copied into the detached candidate; Reflection and Laravel base-path checks prove source resolves there, not in the live checkout. The private candidate harness verifies checkout path, container issue/session labels, loopback port, actual MySQL8.4/database/server UUID before testing. All DDL is confined to the existing exclusively owned issue68 disposable server or forced SQLite memory. No normal developmentDB, original demoDB or global PHP/database settings were changed.

An independently captured receipt records original HEAD/branch, four existing worktrees, exact five dirty-file hashes, every frozen demo source, normal.env, public build manifest and browser90 connection bytes. In-progress comparison is unchanged; original login returnsHTTP200. No fingerprint was modified, no grant renewed, no server stopped and no live checkout promoted. The original finite expiry remains authoritative; automatic renewal is prohibited.

After complete candidate validation and final-head CI, provide final source hashes and a proposed **two-value-only** fingerprint diff. This is review evidence, not permission to apply it. Stop for separate explicit fingerprint/promotion approval. Preserve the current live checkout even though the remote feature branch may contain the validated candidate. No merge, deployment, operational grant, T17 completion or new functional acceptance.
The original demo assignments reached their unchanged24-hour expiry on2026-11-11 at19:00:15UTC (22:00:15 Asia/Damascus). They were not renewed; source/fingerprint approval does not renew authorization. Login HTTP and frozen-source preservation remain independent of expired workspace access.
