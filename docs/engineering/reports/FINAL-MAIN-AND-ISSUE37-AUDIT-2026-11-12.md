# تدقيق main ومساهمة Issue37 في 12 نوفمبر 2026

## الحكم والنطاق

المصدر المراجع `03c829fde71bbb11754ef657bf2edfe50253068e`، tree `ce0b7aa53092691b6b34d83bea5fa6c0a5e70e3d`. main محمي، PR69 وPR70 مدمجان. [CI43289862828](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) SUCCESS على ذلك SHA بالضبط. لا تفعيل إنتاج ولا قبول L4 عام. هذه مراجعة مصدر واختبار موجه، وليست إثباتًا رياضيًا لكل مسار أو اختبار اختراق شاملًا.

Issue71 لتوثيق الحالة وتنظيف الموارد المملوكة فقط؛ فرع `docs/71-final-catalog-handover` من main المتحقق. لا تعديل منتج أو اختبارات أو migrations أو CI أو dependency أو Policy. المصادر الخاصة والعقود ونسخة Word والأدلة خارج Git. روجعت ADR001–003 المقبولة وdecision-ledger؛ ADR004 candidate لا يصبح مقبولًا بهذا التقرير. اعتماد هندسي لا يحسم D002/D007/D014 التشغيلية. SRS الخاص لا ينشر؛ تفاصيل القبول تستند إلى سجلات التتبع الموجودة ولا تُخترع متطلبات لم تُتحقق.

## المصدر الذي جرى فحصه

جرد جميع الملفات المتتبعة 602 ملفًا؛ 52 Action و27 ملف Domain و25 Model و4 Policies و9 Authorization و27 Filament و15 HTTP و6 Identity و3 Providers وNotification واحدة، ولا app/Jobs مخصصًا. جرد الاختبارات:49 Feature و37 MySQL و1 Redis. لا تعني الأعداد قراءة كل سطر أو أن كل مورد مسجل في runtime. خريطة المسارات/التواقيع والروابط في [خريطة المصدر](../../catalog/02-ARCHITECTURE-AND-SOURCE-MAP-AR.md).

قراءة معمقة لحدود الهوية وproviders وguards وsession/recovery، TenantContext/TenantAuthorization/RoleManagement/CapabilityRegistry، hierarchy Actions وadapter الزميل، Policies والمنح الحالية، Private Documents/intake/download/availability، DecideStageReassessment وEffectiveMaturityState، test-only panel والاختبارات ذات الصلة. فُحص route:list فعليًا في testing وproduction، دون اتصال بقاعدة التطوير، وقورن تسجيل providers العادي بإثبات fixture. تفاصيل الروابط في [المسارات](../../catalog/05-ROUTES-SCREENS-AND-JOURNEYS-AR.md).

## مراجعة مساهمة الزميل Issue37

المصدر الأصلي [5c86b4fdc8327975dab84faf9a4f39f82ad694fe](https://github.com/NabilAlsaidaly/quality-excellence-platform/commit/5c86b4fdc8327975dab84faf9a4f39f82ad694fe)،15 ملفًا و3138 سطر إضافة مقارنة بالأب077b881. احتفظ merge [01f2e53419a9a11030656ae023fc914f017fad52](https://github.com/NabilAlsaidaly/quality-excellence-platform/commit/01f2e53419a9a11030656ae023fc914f017fad52) بالتاريخ الأصلي. التكامل الأمني اللاحق [f2c13378198fddd79fc8f044dc77dab3423ff9e8](https://github.com/NabilAlsaidaly/quality-excellence-platform/commit/f2c13378198fddd79fc8f044dc77dab3423ff9e8) جزء من ancestry main. Issue37 ما زال OPEN إداريًا؛ لم نغلقه أو نعد كتابة عمل زميلنا.

بنى الزميل adapter للعمليات المفوضة وcontrollers و3 FormRequests وTreePresenter و4 Blade views وملف ترجمة واختباري Feature/MySQL وتقريره. بقيت13 من15 ملفًا مطابقة للأصل بايتًا. تغير adapter بحد35 إضافة/53 حذفًا واختبارMySQL57 إضافة/23 حذفًا في الدمج الأمني؛ هذه فروق أصل→main، وليست تغييرات هذه المهمة. [تقريره الأصلي](37.md) محفوظ.

| السطح | النتيجة المراجعة | الحد المتبقي |
|---|---|---|
| [AuthorizedUnitOperations](../../../app/Actions/OrganizationalUnits/AuthorizedUnitOperations.php) | يعيد تحميل actor الحالي بعد الأقفال، يثبت institution session/password/org، ثم Gate داخل المعاملة نفسها | ليس مسار منح صلاحية إدارة عام |
| FormRequests/controllers | طول255 وULID ورفض حقول إضافية؛ controllers تفوض Actions | authorize=true في FormRequest ليست وحدها تفويضًا؛ adapter هو الحد الفعلي |
| TreePresenter/Blade | معرّفات عامة محدودة، escaping/CSRF، عربية RTL | لا يسمح مالك الوحدة تلقائيًا بمحتوى المؤسسة |
| hierarchy foundation | create/rename/move/tree يعاد استخدامها؛ cycle/ancestry checks وتاريخ ذري | SQL خارجي قد يتجاوز فحص الدورة التطبيقي |
| Filament OrganizationWorkspace | مكونات أصلية تستدعي adapter الفعلي؛ hydrate/action authorization | test provider فقط؛ لا تسجيل جديد في normal providers |
| HTTP القديم | لم نفترض أن controllers تعني routes مفعلة | وظائف داخلية/TEST_ONLY؛ افحص route:list الفعلي |

## السباق والتزامن

[TenantAuthorization](../../../app/Authorization/TenantAuthorization.php) يثبت سياق المعاملة وPDO ويأخذ Organization X ثم Users X بترتيب تصاعدي ثم Memberships X تصاعديًا، وبعدها suffix الدور/المورد. فحص الدور/permission الحالي يستخدم JOIN locking FOR SHARE، فلا يورث snapshot قديمًا من EXISTS. withinMutation يحتفظ بالتفويض والكتابة والتدقيق حتى commit في نفس البروتوكول. RoleManagement يلتزم بترتيب compatible، والتعديل المدعوم ينتظر أو يُرفض وفق الفائز بالقفل.

[T17SerializationTest](../../../tests/Integration/MySql/T17SerializationTest.php) يثبت revocation/permission/membership/password قبل القفل يسبب رفضًا بلا domain/audit mutation؛ وفوز writer يجعل revoker ينتظر على المؤسسة. timeout1205 وdeadlock1213 يحتفظان بالسبب ويعيدان كامل outer transaction، لا savepoint فقط، ودون retry غير مدروس. الاختبارات تستخدم اتصالين مستقلين وInnoDB REPEATABLE READ وdata_lock_waits. لا يعمم هذا على direct SQL أو أي كاتب مستقبلي خارج البروتوكول. لا إعادة فتح remediation T17 في هذه المهمة.

## الملفات والتاريخ

حد30,000,000 byte وخيارPDF/DOCX فقط، quarantine خاص وفحص بنيوي ليس malware scan. available_demo_unscanned مشروط بعينة اصطناعية معروفة وبصمة/حجم/نوع وpending generation1 وعدم بدء scan، واستقلال المراجع ومنحة نسخة منفصلة ووقت صالح. لا قبول مرفوض/فاشل/مجهول. download خاص يعيد التحقق والبصمة؛ audit stream-open لا يثبت استلام العميل. لا روابط ملفات عامة.

إعادة التقييم تربط approved attempt/submission/review المحددة، original configuration/evidence، optimistic revision/idempotency، independent actor، والتدقيق/notices ذريًا. effective dependency مشتقة من قرارات محفوظة؛ LOWER يطلب revalidation لاحقة بالتسلسل، EQUAL/HIGHER لا يعيدان كتابة تقييمات لاحقة، والتصحيح/pending لا يغيران السابق. لا متوسطات مختلقة أو قبول إسناد expired بسبب session قائمة.

## البيانات والترحيلات

جرد actual disposable MySQL8.4.13:52 migration و50 جدولًا بما فيها journal، InnoDB/RR. استخرجت الأعمدة/الأنواع/default/generated/indexes/UNIQUE/FK/CHECK/triggers دون صفوف مستخدمين إلى دليل خاص. [القاموس](../../catalog/03-DATABASE-DICTIONARY-AR.md) يشتق منها ويصل إلى كل migration. اختبر Tinker جميع52 على SQLite memory جديدة. لا تطبيق DDL على قاعدة التطوير. preflight/compensation وpopulated rollback refusal لا يجعلان MySQL DDL ومعاملة journal ذرية في حالة انقطاع العملية.

## نتائج فعلية

| التحقق | المصدر والهدف | النتيجة |
|---|---|---|
| main CI SQLite |03c829f/isolated|563 tests،5378 assertions،0 errors/failures/skips|
| main CI MySQL8.4.13 |03c829f/disposable|318/4163،0 errors/failures/skips|
| main CI Redis8.2.11 |03c829f/disposable|4/19،0 errors/failures/skips|
| إجمالي CI | نفس run43289862828 |885 executions/9560 assertions؛ ليس885 business requirements|
| إعادة Contributor/Security |03c829f،MySQL8.4.13،اتصالان|19/215 PASS؛ filter T17SerializationTest\|InstitutionOrganizationOperationsTest\|InstitutionOrganizationNativeTest|
| Composer strict/platform/audit + Pint |نسخة source ذاتها|PASS|
| npm audit JSON |locked dependency tree|0 vulnerabilities|
| route:list testing/production |SQLite memory،no normal DB|PASS؛ قورن middleware/provider فعليًا|
| Tinker CLI/legacy creation |SQLite memory جديدة|PASS؛ hashed/hidden/no-membership/no-role/no-institution، حذف السجل الاصطناعي فقط|
| HTTP Herd/phpMyAdmin |قراءة البيئة القائمة فقط|UNAVAILABLE؛ لم نغير إعدادًا لتصنيع نجاح|

19 اختبارًا subset لا تُجمع مع885 لإنتاج رقم انطباعي جديد. لم يعاد browser acceptance بعد التنظيف. أدلة browser37/38 السابقة وما تثبته وحدود IDM في [تقرير الإغلاق](FINAL-MVP-CLOSURE-2026-11-12.md). CI الخاص بفرع توثيق71 يُقرأ من check على HEAD الحالي في PR ولا يستبدل بـrun main أقدم؛ وصلته وإيصاله الخاص جزء من التسليم النهائي.

## سجل النتائج والمخاطر

| الرمز | الأولوية | النتيجة والدليل | المعالجة |
|---|---|---|---|
| AUTH-OPS |P1 جاهزية معروفة|capabilities العادية فارغة؛ bootstrap غير مكتمل|GATED_OWNER_DECISION؛ لا اختراع grants|
| FILE-OPS |P1 جاهزية معروفة|لا acceptance scanner إنتاجي|لا ملفات عميل/إطلاق، الاستثناء TEST_ONLY فقط|
| T17-EXT |P2|supported-writer فقط كما تثبت الاختبارات|لا ادعاء عالمي؛ بروتوكول للكتّاب الجدد ومراجعة منفصلة|
| IDM |P2 تجربة|manual Chrome/Edge canceled؛ isolated hash4 PASS|OPEN؛ لا إعدادات IDM تغيرت|
| DDL |P2 تشغيل|حراس TEST_ONLY و1419/crash gap|operator منفصل وbackup/restore ومراجعة pending|
| HOST-READY |P2 تشغيل|Herd/phpMyAdmin endpoints غير متاحة|تشغيل وفحص بإجراء المشغّل؛ الروابط محفوظة|
| DOC-DRIFT |P2|واجهات رئيسية في main كانت موصوفة unimplemented/Draft/live|front matter جديد؛ حفظ التاريخ دون مسحه|
| CAPACITY |P3|Organization X يسلسل كتاب المؤسسة|لا benchmark سعة مُدّعى أو abstraction جديدة|

لم يُكتشف عيب P0/P1 جديد قابل للإعادة ضمن هذا النطاق؛ بوابات P1 أعلاه متطلبات تشغيل معروفة وليست ادعاء اكتشاف ثغرة. [ACCEPTANCE](../delivery/ACCEPTANCE.md) يبقى دون قبول L4 شامل للـ105. لا مراجعة تصادق على مصدر مالك خاص غير منشور أو تحول قرارات TEST_ONLY إلى تشغيلية.
