# بيان المستودع والتسليم

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.


المصدر التنفيذي الحالي main03c829f؛ PR69 وPR70 merged، CI43289862828 SUCCESS. فرع docs/71-final-catalog-handover مؤقت ولا يصبح جزءًا من main قبل المراجعة والحماية. لا self-merge. عند نشر الفرع سيبقى فرعان بعيدان حتى الدمج المصرح به من المراجع وتقاعد فرع التوثيق؛ لا تُوصف هذه الحالة بأنها main فقط.

## سجل الفروع

| النوع | الفرع | SHA محفوظ | النتيجة/السبب |
|---|---|---|---|
| remote | `feat/mvp-completion-2026-11` | `b3db07e8afe4cc6f637946865f0d0e51fd9d07ca` | DELETED ancestor verified |
| remote | `integration/mvp-final-2026-11-12` | `fae7f17ee7e4fc3cbf387dcf1b537204355a6809` | DELETED ancestor verified |
| remote | `feat/37-institution-org-units` | `5c86b4fdc8327975dab84faf9a4f39f82ad694fe` | DELETED ancestor verified |
| local | `chore/16-herd-local-readiness` | `514778f813078087df30a04ae7567c9a85707af4` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `chore/filament-foundation` | `90ee99d101c5fc696cd2bdd4fb13131f18fecccd` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `chore/filament-foundation-clean` | `07b3b442fd82eaebf2080c86d841d9971d24f0d2` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `chore/phase-01-laravel-bootstrap` | `b367b09d61295b6b4615c4845bfc5be162fdac50` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `docs/26-phase-05a-reconciliation` | `48b390b3689141cc79cddea3e535b21f351a81ff` | DELETED ancestor verified |
| local | `docs/30-phase-05b0-identity-gates` | `896e560a02c6c437012ebe4305dc47fb09bb4d79` | DELETED ancestor verified |
| local | `docs/71-final-catalog-handover` | `03c829fde71bbb11754ef657bf2edfe50253068e` | PRESERVED Main, active documentation or preserved associated worktree |
| local | `feat/11-tenant-schema-foundation` | `d70e339a9eb1c320dba8211fda2d91cc8a8acaa7` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/18-tenant-rbac-foundation` | `7b6213ae991f26e498910b4e613f8a7e969428a5` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/20-organizational-unit-hierarchy` | `06dba8844b80c906abe149c7ffcc6c82aeedba35` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/22-organization-management` | `5b0618d47833e9396f4ff0000e81d56207f20ed5` | DELETED ancestor verified |
| local | `feat/32-institution-identity-entry` | `5b7b3b038d1baaf2867dde4ea48f59d946b0ac36` | DELETED ancestor verified |
| local | `feat/38-approach-bank-core` | `d88a90622deba0e54cc4895a15b49c473158f1a8` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/4-arabic-filament-shell` | `e52996ba039986ac4404fdc7587197e690c36c06` | DELETED ancestor verified |
| local | `feat/40-approach-audit-ui` | `3f001b9520dcd579ab8e0266fc37fe0bca836bb5` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/42-documents-filament-foundation` | `4c9ac958095cf21ae899481eecb85593a47257c9` | PRESERVED Main, active documentation or preserved associated worktree |
| local | `feat/44-filament-operational-foundation` | `c1365f83ee1729d136cf4823d3f6e2e6c5bb86fd` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/46-filament-institution-vertical-slice` | `02a5acaf69923ca6887f293b5c88031cbd3b63bb` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/48-institution-identity-filament-ui` | `f2d38cca01d3d92b14b679f97bab2a82740ad260` | PRESERVED Tip not an ancestor; no force deletion authorized |
| local | `feat/mvp-completion-2026-11` | `4b81e88cbbd59a0280f58957a375eb2bdb2f5e8a` | PRESERVED Main, active documentation or preserved associated worktree |
| local | `fix/28-symfony-security-advisories` | `702da7328a7b11b32822728b2bb88d0209ca8659` | DELETED ancestor verified |
| local | `fix/34-filament-environment-regression` | `c7e4875d65c4d4e3b01cbad4af6441cc07451076` | PRESERVED Main, active documentation or preserved associated worktree |
| local | `integration/mvp-final-2026-11-12` | `fae7f17ee7e4fc3cbf387dcf1b537204355a6809` | DELETED ancestor verified |
| local | `main` | `3cfea15e908d19042254a0670ac8c83185d738df` | PRESERVED Main, active documentation or preserved associated worktree |
| local | `test/24-tenant-isolation-contract` | `8d8204cb39ba5933aefbf871611d9150bc2297ea` | DELETED ancestor verified |

كل حذف بعيد سبقه tip pin وGit ancestry وcompare مستقل، ثم إعادة فحص refs وعدم وجود PR مفتوح. الحذف المحلي استعمل branch -d فقط؛ أُبقيت worktrees والالتزامات غير المحتواة. لم يُحذف tag أو stash أو archive أو .env. سجل الفرع البعيد بديل التاريخ هو PR والـmerge ancestry، لا إعادة كتابة المصدر.

## الحدود والتسليم

- عامة: هذا catalog، runbooks، تقرير audit/cleanup/Issue71 ومصدر تقرير المالك المنقح؛ بلا credentials أو dumps أو مصدر مالك خاص.
- خاصة: Word والتصيير وQA والـcleanup IDs/source receipts والأرشيفات تحت .local-tools غير المتتبع؛ ليست مرفقات PR عامة.
- حُذفت15 حاوية عرض و15 volume حصرية وبياناتها التجريبية؛ الأدلة باقية. الخدمات طويلة العمر والمشروع الآخر وMySQL/Herd/phpMyAdmin محفوظة. تفاصيل أي مورد آخر في تقرير التنظيف النهائي، دون تعميم.
- روابط الصيانة: [الفهرس](00-INDEX-AR.md)، [تشغيل Windows](../operations/LARAVEL-HERD-WINDOWS-RUNBOOK-AR.md)، [Tinker](../operations/LOCAL-USERS-TINKER-AND-PERMISSIONS-AR.md)، [تقرير المالك](../engineering/delivery/PROJECT-OWNER-COMPLETE-HANDOVER-SOURCE-AR.md).

مراجعة main أدناه ليست ترخيصًا لترقية قاعدة التطوير أو لإطلاق المنتج؛ تطبيق52 ترحيلًا نجح في أهداف disposable فقط. وثائق checkpoints السابقة محفوظة وموسومة تاريخيًا، ولا تعني بقاء demo أو صلاحية أو PR Draft.
