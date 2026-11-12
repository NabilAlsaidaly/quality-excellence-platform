# الحالة النهائية للتنظيف الآمن وحفظ البيئات

12 نوفمبر2026 · Issue71 · source main03c829f. الجرد والأوامر والإيصالات التفصيلية خاصة تحت `.local-tools/reports/final-housekeeping-20261112` ولا تنشر معها أسرار. هذه الصفحة تلخص ما تم فعليًا، لا تعليمات حذف عامة.

## التفويض وحدود الحذف

اعتمد المستخدم صراحة انتهاء الحاجة إلى browser21–26 و31–38 و90،15 fixture بالضبط. المراجعة الآلية رفضت التنظيف قبل هذا الاعتماد؛ لم نتجاوزها. بعد الاعتماد تحققنا من UUID والـcontainerID/labels والهدف quality_platform_ci وloopback وهوية PHP/router/مساره، وexclusive anonymous volume وعدم إعادة توجيه المسارات، ثم stop وdelete على مرحلتين. كل مجلد runtime محدد تحت fixture أصلها الخاص؛ لا wildcard/global prune أو FK disable أو SQL حذف عام.

## المنفذ

| الفئة | النتيجة الفعلية |
|---|---|
| حاويات العروض15 |DELETED15/15،بلا إخفاق|
| وحدات MySQL الحصرية |DELETED15/15؛ لا shared volume|
| قواعد الاختبار |15 schema داخل الحاويات المحذوفة؛ لا SQL على قاعدة عادية|
| PHP loopback servers |STOPPED8؛ البقية لم تكن لها عملية حية مطابقة|
| runtime trees |90 مسارًا محددًا: sessions/cache/locks/views/tmp/files لكل fixture|
| manifests والأدلة |connection/fixture secrets والتشخيصات/screenshots/reports/archives محفوظة؛ hashes متحققة|
| شبكات وصور Docker |محفوظة؛ لا prune أو network/global cleanup|

لا URL عرض عاملة بعد ذلك، ولا تجديد حساب أو grant أو expiry. اللقاء القادم يحتاج fixture جديدة منفصلة. [OWNER-DEMO-RUNBOOK](../delivery/OWNER-DEMO-RUNBOOK.md) يتصدره تنبيه التقاعُد؛ وصفه السابق تاريخي.

## المستثنى من الحذف

حاويتا التطوير المعتادتان MySQL وRedis محفوظتان دون تغيير حالتهما الأصلية المتوقفة، وخمس حاويات مشروع آخر محفوظة. خدمة Windows MySQL وHerd/phpMyAdmin وملفات sites و.env وAPP_KEY وassets محفوظة؛ توقف HTTP أثناء الفحص كان موجودًا ولم نصلحه بتغيير غير مصرح. لا تغيير IDM.

ست runtimes اختبار إضافية مثبتة في جرد مستقل بقيت دون stop/delete لأن المراجعة الآلية رفضت توسيع الحذف إليها: رفضت اعتبار «تصرف بما تراه مناسبًا» إذنًا صريحًا للحذف النهائي. بقيت محفوظة مع volumes والبيانات والأدلة. الحاوية القديمة `qep-issue38-mysql-ci` محفوظة كذلك لغياب ربط ملكية حصري موثق؛ الاسم وحده لا يكفي. لا نعتبر هذا الجرد إذنًا لتجاوز الرفض أو تنظيفًا منجزًا لهذه السبع.

الست: `qep-mvp-closure-310746e2` و`qep-closure-redis-38763888` و`qep-final-mvp-mysql-7cfb635a` و`qep-final-binlog-92028520` و`qep-final-redis-2f6489aa` و`qep-issue68-cf1de380b879442ea850b14a0b941d9d`. تضم أربع MySQL واثنتين Redis، ولا نطلب حذفها اعتمادًا على اسم فقط. الجرد الخاص يحوي receipt SHA/labels/session/UUID والـexclusive volume checks؛ لم يُنفّذ script الحذف عند رفض المراجعة.

أي browser helper daemon باقٍ لا يملك اعتماد إيقاف مستقلًا مثبتًا يُحفظ؛ لا نقتل Chrome/Edge/Node عامة أو Word المفتوح للمستخدم. صُيّر Word في instance مخفية خاصة ثم أغلقت هي وحدها. لا حذف للـworktrees التي قد تحتوي ignored files.

## الفروع والتاريخ

main البعيد03c829f محمي. البداية4 فروع بعيدة؛ قورن كل tip وميرج ancestry مستقلًا مع remote compare،behind0،ثم تحقق ls-remote قبل push --delete عادي. لم يوجد PR مفتوح على الثلاثة. أزيلت3 فروع بعيدة مدمجة، و8 محلية مثبتة ancestry وغير مستخدمة. تحفظ الأنواع الأخرى وworktrees وtags وstash والأرشيف.

| النوع | الفرع الأولي | SHA | النتيجة والسبب |
|---|---|---|---|
| remote | `feat/mvp-completion-2026-11` | `b3db07e8afe4cc6f637946865f0d0e51fd9d07ca` | DELETED: ancestry verified; normal deletion |
| remote | `integration/mvp-final-2026-11-12` | `fae7f17ee7e4fc3cbf387dcf1b537204355a6809` | DELETED: ancestry verified; normal deletion |
| remote | `feat/37-institution-org-units` | `5c86b4fdc8327975dab84faf9a4f39f82ad694fe` | DELETED: ancestry verified; normal deletion |
| local | `chore/16-herd-local-readiness` | `514778f813078087df30a04ae7567c9a85707af4` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `chore/filament-foundation` | `90ee99d101c5fc696cd2bdd4fb13131f18fecccd` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `chore/filament-foundation-clean` | `07b3b442fd82eaebf2080c86d841d9971d24f0d2` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `chore/phase-01-laravel-bootstrap` | `b367b09d61295b6b4615c4845bfc5be162fdac50` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `docs/26-phase-05a-reconciliation` | `48b390b3689141cc79cddea3e535b21f351a81ff` | DELETED: ancestry verified; normal deletion |
| local | `docs/30-phase-05b0-identity-gates` | `896e560a02c6c437012ebe4305dc47fb09bb4d79` | DELETED: ancestry verified; normal deletion |
| local | `docs/71-final-catalog-handover` | `03c829fde71bbb11754ef657bf2edfe50253068e` | PRESERVED: Main, active documentation or preserved associated worktree |
| local | `feat/11-tenant-schema-foundation` | `d70e339a9eb1c320dba8211fda2d91cc8a8acaa7` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/18-tenant-rbac-foundation` | `7b6213ae991f26e498910b4e613f8a7e969428a5` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/20-organizational-unit-hierarchy` | `06dba8844b80c906abe149c7ffcc6c82aeedba35` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/22-organization-management` | `5b0618d47833e9396f4ff0000e81d56207f20ed5` | DELETED: ancestry verified; normal deletion |
| local | `feat/32-institution-identity-entry` | `5b7b3b038d1baaf2867dde4ea48f59d946b0ac36` | DELETED: ancestry verified; normal deletion |
| local | `feat/38-approach-bank-core` | `d88a90622deba0e54cc4895a15b49c473158f1a8` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/4-arabic-filament-shell` | `e52996ba039986ac4404fdc7587197e690c36c06` | DELETED: ancestry verified; normal deletion |
| local | `feat/40-approach-audit-ui` | `3f001b9520dcd579ab8e0266fc37fe0bca836bb5` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/42-documents-filament-foundation` | `4c9ac958095cf21ae899481eecb85593a47257c9` | PRESERVED: Main, active documentation or preserved associated worktree |
| local | `feat/44-filament-operational-foundation` | `c1365f83ee1729d136cf4823d3f6e2e6c5bb86fd` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/46-filament-institution-vertical-slice` | `02a5acaf69923ca6887f293b5c88031cbd3b63bb` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/48-institution-identity-filament-ui` | `f2d38cca01d3d92b14b679f97bab2a82740ad260` | PRESERVED: Tip not an ancestor; no force deletion authorized |
| local | `feat/mvp-completion-2026-11` | `4b81e88cbbd59a0280f58957a375eb2bdb2f5e8a` | PRESERVED: Main, active documentation or preserved associated worktree |
| local | `fix/28-symfony-security-advisories` | `702da7328a7b11b32822728b2bb88d0209ca8659` | DELETED: ancestry verified; normal deletion |
| local | `fix/34-filament-environment-regression` | `c7e4875d65c4d4e3b01cbad4af6441cc07451076` | PRESERVED: Main, active documentation or preserved associated worktree |
| local | `integration/mvp-final-2026-11-12` | `fae7f17ee7e4fc3cbf387dcf1b537204355a6809` | DELETED: ancestry verified; normal deletion |
| local | `main` | `3cfea15e908d19042254a0670ac8c83185d738df` | PRESERVED: Main, active documentation or preserved associated worktree |
| local | `test/24-tenant-isolation-contract` | `8d8204cb39ba5933aefbf871611d9150bc2297ea` | DELETED: ancestry verified; normal deletion |

بعد تقاعد الثلاثة بقي main وحده بعيدًا في تلك اللحظة. يضيف نشر فرع توثيق71 فرعًا مؤقتًا ثانيًا؛ لا يوصف الوضع النهائي بأنه main فقط حتى مراجعة PR ودمجه بشكل مشروع وتقاعد الفرع. لم نعِد main المحلي القديم أو checkout الأصلي إلى مصدر آخر دون إذن، ولا force-push/reset/-D. [البيان الكامل](../../catalog/10-REPOSITORY-HANDOVER-MANIFEST-AR.md) يميز الفروع البعيدة والمحلية ولا يخفي غير المحتوى.

## أدلة الحفظ والحكم

INITIAL-INVENTORY/VALIDATE/STOP/DELETE-BROWSER-RESULTS وBRANCH-RETIREMENT والجرد النهائي وإيصال حفظ hashes موجودة خاصة. env/assets/archive/browser90 manifest محفوظة؛ retained stashes لم تطبق أو تسقط. بيانات العروض المحذوفة وحدها انتهت، بينما المصادر والأدلة التاريخية باقية.

الحكم **PARTIAL_WITH_EXPLICIT_BLOCKERS** بالنسبة لشروط main-only والتشغيل الحي والتنظيف الموسع؛ التنظيف15 المعتمد منجز. المستثنيات الدقيقة أعلاه، وPR التوثيق يحتاج المراجعة المستقلة المعتادة. لا إسقاط حماية أو اختراع موافقة لتقديم COMPLETE شكلي.
