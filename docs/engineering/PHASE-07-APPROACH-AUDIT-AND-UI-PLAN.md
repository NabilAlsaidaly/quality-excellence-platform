# خطة Issue40 — سجل تغييرات المنهجيات وتهيئة الواجهة المؤسسية

2026-11-08، الفرع الموجود `feat/40-approach-audit-ui` في worktree مستقل، الأساس `dc29d163085d0143355fc35552cbea9c1f5ba375`. main محمي وCI42414316597 ناجح عليه، PR39 مدمج. تطابق tree بين أساس المهمة وHEAD السابق d88a906 يؤكد أن مراجعة ملفات38 الحالية لا تُستبدل بنسخة قديمة؛ أعيدت قراءة المواضع المتأثرة والهوية/التفويض. فرع37 وملفاته read-only، ولا PR مفتوح منافس عند البدء.

## القرار وحدود التفويض

A قابل للتنفيذ تقنيًا داخل الحد الموثوق، وB إعداد/test-only بينما G2 غير مصادق. مصادر التتبع العامة BR001..006 وFR-APP001..008/010 ومعاييرها؛ المصدر الخاص وSRS لا يُعدلان ولا ينقل نصهما إلى Git. ADR004 يبقى PROPOSED. إعادة التحقق من بصمات المصادر تطابق مراجعة38، وSRS revision نفسه؛ D002 لا يزال غير مصادق. لا إعادة أسئلة محسومة ولا منح عضوية أو Role حقًا تلقائيًا.

التباس العقد AT-A03 حول scope يُحل بقائمة A1 الصريحة وعقد البيانات الحالي: scope النص الوصفي قابل للتعديل، أما organization/actor/security-scope/identity/owner داخل metadata فمرفوضة. آخر سطر يذكر فرعًا جديدًا لا يلغي تعليمات إعادة استعمال الفرع الموجود؛ لا فرع/Issue بديل.

## التصميم قبل الكود

- ترحيل إضافي واحد `2026_11_08_000001_create_approach_changes_table.php`، بعد جرد21 ترحيلًا حاليًا. approach_changes مباشر مؤسسي، BIGINT داخلي وULID، actor وapproach وorganization، نوع metadata_updated/owner_changed، JSON before/after، previous/new owner FKs عند نقل الملكية، created_at فقط. لا جدول generic audit ولا content snapshot مصطنع.
- metadata يسجل كامل الحقول الستة قبل/بعد من DB؛ النقل يسجل ULID واسم الوحدة وقت الحدث، مع FK داخلي للوحدتين. لا request JSON أو بيانات حساب. التاريخ لا يعدل؛ أسماء الوحدات التاريخية لا تتبدل عند rename لاحق.
- FK مركب إلى approaches(org,id) والوحدتين(org,id)، وكل المراجع RESTRICT. CHECK لنوع الحدث وشكل مراجع المالك وJSON object. فهرس(org,approach,id) للقراءة. MySQL CREATE واحد inline؛ SQLite CREATE/index داخل transaction DDL. لا trigger جديد أو إزالة guard للنموذج.
- down يرفض عند وجود Approach أو Revision أو حدث، قبل DROP، محافظًا على ضمان rollback السابق حتى عند عدم وجود أحداث بعد. نافذة صيانة حصرية مطلوبة؛ انقطاع DDL/journal يحتاج فحص مشغل.
- UpdateApproach يملك تنسيق mutation/audit ضيقًا يعاد استخدامه من ChangeApproachOwner. يستعمل CreateApproach::withinContext كما هو. الأقفال organization->actor->membership->Approach->الوحدة الحالية/الهدف؛ current reads، محاولة transaction واحدة، لا شبكة/إيميل/queue.
- يُحتفظ بحراسة Approach ordinary update/delete. الكتابة الوحيدة المعتمدة هنا qualified query-builder داخل transaction ومع حدث ذري؛ raw SQL ليس immutable storage. ApproachContentRevision وSaveApproachContentRevision لا يتغيران.
- stale-edit token: SHA256 لتمثيل حتمي من persisted metadata/owner/resource وULID آخر حدث. يُقارن تحت القفل؛ آخر حدث يمنع ABA حتى لو رجعت القيم إلى ما كانت عليه. لا counter/migration إضافية، ولا اعتماد على timestamp محدود الدقة. الرمز concurrency proof وليس تخويلًا أو سرًا. missing/malformed proof422، stale proof409، والـno-op فقط برمز حالي ولا timestamp/audit write.
- ListApproaches يضيف edit_token للتفاصيل وchangeHistory مستقلة bounded، مع projection whitelist وقيم field/before/after واضحة، actor ULID nullable للـlegacy، بلا email/BIGINT/raw JSON. metadata القائمة تبقى بلا drafts، وdraftHistory مستقلة. ownerOptions تحمّل الوحدات المقيدة للسياق داخل الحد الداخلي، وإعادة التحقق عند mutation.

## جرد الملفات المزمع تنفيذها

### A

1. app/Models/ApproachChange.php — جديد، mass-assignment guard/append-only/relations.
2. database/migrations/2026_11_08_000001_create_approach_changes_table.php — جديد فقط.
3. app/Actions/Approaches/UpdateApproach.php — عملية فعلية وhelpers ضيقة للأقفال/التدقيق/الرمز.
4. app/Actions/Approaches/ChangeApproachOwner.php — نقل مستقل مدقق.
5. app/Actions/Approaches/ListApproaches.php — projection/edit token/history/options؛ لا توسيع grants.
6. tests/Feature/Approaches/ApproachManagementTest.php — إثبات update/no-op/stale/ABA/history/rollback/revocation مع إبقاء قيود38.
7. tests/Integration/MySql/ApproachSchemaIsolationTest.php — FK/JSON/type/index/DDL/rollback، تنافس فعلي للبيانات/الملكية، وتنظيف fixtures الجديدة فقط.
8. tests/Support/TenantIsolationSchemaContract.php — تصنيف وFK/UNIQUE/type/checks للتاريخ؛ لا استثناءات عزل أوسع.
9. docs/architecture/tenant-isolation-schema-contract.md وdocs/architecture/approach-bank-foundation.md — التصميم الفعلي وحدود DB/Action/G2/G3.

### تحديثات fixtures المشروطة المسموحة في العقد

تُنفذ بعد إثبات أثر journal22/dependency من الاختبارات: TenantIntegrityTest (21->22)، HierarchyHistoryIntegrityTest وOrganizationHistoryIntegrityTest (20/21->21/22)، InstitutionIdentityMigrationTest (اختيار الخمسة نفسها،17/22 بدل16/21)، PermissionIntegrityTest (زيادة خطوة لإبقاء الهدف الأصلي، دون حذف assertions)، TriggerMigrationSafetyTest (إزالة اعتماد approach_changes الفارغ قبل approach/unit). كلها تحت tests/Integration/MySql. اختبارات38 نفسها تحتاج التعامل مع ثالث ترحيل واعتماده قبل parent DDL، مع الإبقاء على رفض أول DROP الممتلئ. لا تعديل ملف اختبار إضافي بلا موافقة إذا ظهر احتياج آخر.

### B غير المفعّل

- app/Http/Controllers/Institution/ApproachController.php وApproachContentController.php: orchestration، institution principal/context فقط، Gate قبل الوصول، redirects ثابتة، existing Actions.
- app/Http/Requests/Institution/{StoreApproachRequest,UpdateApproachRequest,ChangeApproachOwnerRequest,StoreApproachContentRevisionRequest}.php: syntax/allowlist/Arabic feedback مع تحقق Action مستقل.
- resources/views/institution/approaches/{index,show,content,create,edit,owner}.blade.php: RTL، labels/CSRF/error/conflict/pagination/history، عناصر التشغيل لا تظهر دون operational flag وGate. العرض الافتراضي غير مسجل ويحافظ على اختبارات38.
- lang/ar/institution_approaches.php: نصوص واجهة عربية فقط.
- tests/Feature/InstitutionApproaches/ApproachHttpAuthorizationTest.php: routes وPolicy مصطنعة داخل PHPunit فقط؛ default Gate denial، resource/operation scopes، CSRF419/input422/stale409، session/guard/foreign/revocation. fixtures ليست سياسة مالك ولا assignment schema دائمة.
- لا ApproachPolicy permissive، لا routes/institution_approaches.php أو routes/web registration، ولا config/registry/Provider/middleware/guards/Panel أو assignment migration. غياب G2 وG3 يُعلن BLOCKED حتى مع نجاح synthetic flow.

### التسليم

هذه الخطة أول commit، ثم A ثم B، ثم docs/engineering/reports/40.md. أربعة تقارير خاصة في .local-tools/reports/phase-07/40/ بعد git check-ignore. التقرير العام يتتبع IDs فقط، الخاص يحتفظ بالأدلة/القرارات الدقيقة. كل ملفات Issue37 وCI والحزم والترحيلات السابقة read-only. إذا لزم مسار آخر فطلب تعديل محدد قبل الكتابة.

## التحقق

AT-A01..15 وAT-B01..12 مع named tests ومصفوفة PASS/FAIL/BLOCKED/NOT RUN. اختبارات إيجابية داخل trusted fixture فقط. فشل بعد UPDATE وقبل/بعد INSERT، null/Unicode/unknown inputs، foreign/malformed IDs، snapshot/current membership، no-op وABA، عرض escaped بلا BIGINT/contacts، قيود/rollback/retry وعمليتان MySQL مستقلتان مع إثبات انتظار InnoDB. حفظ كل assertions السابقة عدا استبدال قصد placeholder المعطل باختبار غياب proof/حدود العملية الجديدة الموثقة، لا إضعاف guard.

مركزة ثم كاملة SQLite/MySQL8.4/Redis، Composer strict/install/platform/audit بلا suppression، Pint، npm ci/build، cache/route/view وHTTP في CI غير المعدل، diff/scope/confidentiality. رندر Blade ثم فحص RTL/labels/375/768/1280 بواسطة HTML fixtures مهملة إن أمكن؛ لا ادعاء browser product activation. Draft PR فقط وCI على report-containing final HEAD، مع إيصال خارجي في PR/private لتجنب إحالة SHA ذاتية. لا merge/deploy أو G2/G3 activation.

## سجل التنفيذ الفعلي

خطة1482359 سبقت الكود؛ جُمعت A وB في implementation29cf968 بدل commitين منفصلين، تنظيم تسليم فقط دون توسعة. correctionfe72452 جعل assertion كامل عبارة تأكيد النقل مقاومًا لـwhitespace تنسيق Blade بعد فشل محلي وCI42418860351. CI42419255903 ناجح علىfe72452: SQLite232/2656، MySQL167/1410، Redis4/19، كل Composer/Pint/npm/cache/HTTP ناجح مع head_sha متحقق. التقرير النهائي سيُثبت ويُعاد CI على HEAD المحتوي له؛ الإيصال خارج commit لتفادي دورية SHA. A منفذ داخليًا وB BLOCKED بلا routes/Policy/grants. لا موافقات أو ملفات جديدة خارج العقد.