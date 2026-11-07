# بنك المنهجيات — النواة وسجل التغييرات / Issue40

## السلطة والحالة

BR001..006 وFR-APP001..008/010 ومعايير AC هي حدود التتبع. راجع تنفيذ38 أساس هذه الإضافة، ADR001/002/003، ADR004 المقترح، سجل القرارات وIssue12/13/37/40. المصدر الخاص وSRS لا يُنسخان إلى المستودع العام؛ مصفوفة D002 التفصيلية لم تُصادق. الإذن التقني بسجل التاريخ ليس إذنًا بمنح صلاحيات.

**A منفذ داخليًا:** إنشاء المنهجية ومالك رئيسي واحد، قراءة وبحث مقيدان، تعديل البيانات ونقل المالك بسجل مستقل ومعاملة، وإصدارات نص المسودة كما كانت. **B محجوب تشغيليًا:** Controllers/FormRequests وستة قوالب عربية جاهزة للاختبار، لكن لا ApproachPolicy ولا routes ولا capabilities ولا roles/grants جديدة. المسارات وPolicy الاصطناعية موجودة فقط داخل PHPUnit. لا وصول مركزي افتراضي ولا محتوى منشور أو اعتماد أو مراجعة أو نضج أو Mapping أو إشعارات.

## مخطط البيانات

| الجدول | العقد |
|---|---|
| approaches | BIGINT داخلي، public ULID فريد، organization_id إلزامي وFK جذر RESTRICT، مالك واحد عبر FK مركب organization/owning_unit إلى units(organization_id,id)، created_by عالمي RESTRICT، UNIQUE(organization_id,id). |
| البيانات الوصفية | name حتى255، description/purpose/scope إلزامية، objectives/review_frequency nullable؛ النصوص حتى65535 محرفًا. الأسماء غير فريدة؛ لا قالب أعمال مفروض. |
| approach_content_revisions | unchanged: ULID مستقل، organization/approach FK مركب، author FK عالمي RESTRICT، version_number unsigned/check1..4294967295 وUNIQUE(approach_id,version_number)، body plain UTF-8 حتى1000000 محرف، created_at فقط. |
| approach_changes | ULID مستقل فريد، organization/approach مركب وroot/actor FKs، JSON object قبل/بعد، event محصور metadata_updated/owner_changed، created_at فقط. |
| previous_owner_id/new_owner_id | nullable للبيانات، إلزاميان ومختلفان لنقل الملكية؛ FKs مركبة إلى وحدتي المؤسسة نفسها، RESTRICT/RESTRICT. |
| الفهارس | السابقان organization/created_at/id وorganization/name/id؛ سجل التاريخ organization/approach/id لترتيب ثابت. |

سجل metadata يحفظ الحقول الستة كاملة قبل/بعد. سجل owner يحفظ ULID واسم كل وحدة وقت الحدث، إضافة إلى FK داخلي لكل منهما. لا إعادة تسمية لاحقة للوحدة تغير snapshot التاريخية. actor عالمي حتى تبقى نسبته التاريخية بعد سحب العضوية؛ Action يتحقق من الربط والعضوية الحاضرين، وFK لا يدعي صلاحية أعمال. أسماء/تفاصيل الاتصال للفاعل لا تخرج في read contract.

## المعاملة والتعديل المتقادم

CreateApproach::withinContext يعاد استخدامه دون تعديل: سياق مطابق، ثم current locking reads للمؤسسة → الحساب → العضوية. UpdateApproach/ChangeApproachOwner يقفلان المنهجية والمالك ضمن المؤسسة، ثم يفحصان edit_token تحت القفل. ordinary Approach save/update/delete تبقى مرفوضة؛ UPDATE الوحيد في Action مؤهل بـid وorganization_id داخل المعاملة، ثم reload للصف المحفوظ وINSERT الحدث. استثناء قبل INSERT أو بعده يلغي التغيير والحدث. لا retry صامت ولا أثر خارج DB.

edit_token بصمة SHA256 لحقول الحالة المحفوظة/هوية المنهجية والمؤسسة/مالك ULID/أحدث audit ULID بقراءة حالية. ليس توقيع صلاحية ولا سرًا. إدخال مفقود/مشوه422؛ نسخة صحيحة الشكل قديمة409 دون overwrite. لا يعتمد على دقة timestamp أو نموذج المستدعي. أحدث event يمنع ABA ولو عادت القيم إلى البداية. حفظ مطابق مع proof حاضر لا يكتب حدثًا ولا updated_at. تعديل حقيقي يحدّث updated_at، وقد تتساوى قيمته داخل الثانية؛ proof لا يعتمد عليه.

البيانات تقبل فقط name/description/purpose/scope/objectives/review_frequency؛ scope هنا وصف نصي، لا نطاق تخويل. مالك جديد يمر في Action مستقل بULID؛ لا tenant/actor/internal-ID/status/approval/body ضمن metadata. المحتوى والنسخ وcreator وهوية المنهجية لا تتغير بنقل المالك. SaveApproachContentRevision نفسه لم يتغير: آخر نص مطابق no-op، مختلف ينشئ n+1 immutable revision.

قفل المؤسسة يسلسل الأعمال المتعاونة داخلها ويشارك خدمات الوحدات؛ هذا تبسيط اتساق له كلفة أداء، وليس ادعاء deadlock-free أمام SQL مستقل. اختبارات MySQL المستقلة ترصد انتظار InnoDB فعليًا ثم409 للكاتب الثاني بنفس proof، لكل من metadata وowner. SQLite لا يثبت هذا السلوك. القائمة MVCC عادية بعد تحقق عضوية حالي، وقد ترى snapshot سابقة في transaction خارجية قديمة؛ find/token والقرار الأمني يستخدمون current reads.

## القراءة وحدود العرض

ListApproaches يعيد arrays محصورة وpaginators بترتيب ثابت، لا Eloquent graph ولا BIGINT/contact/draft في metadata. search≤255 وpage1..1000000، list perPage≤100 وhistory≤20 حدود تقنية. البحث يهرب !/%/_ بمعاملات SQL، والمالك eager scoped بلا N+1. show يضيف edit_token؛ list لا يقرأ proof لكل صف. changeHistory يتأكد من المورد ضمن المؤسسة، whitelist للحدث والحقول والقيم ولا يطبع raw JSON مجهولًا. ownerOptions scoped بصفحات100.

HTTP adapters غير مسجلة: تتطلب web + InstitutionSession، institution guard الحقيقي وTenantContext الحالي، وGate/Policy لكل عملية ومورد ULID. الأعمال: viewAny/view/create/updateMetadata/changeOwner/editDraft/viewDraftHistory/viewAuditHistory؛ هذه أسماء لعقد Policy مقترح، **ليست registry entries أو grants أو مصفوفة معتمدة**. غياب Policy يرفض403. readDraft/writeDraft/audit منفصلة. controller يمرر نتائج Gate للروابط؛ إخفاؤها ليس الحاجز الأمني.

FormRequests تتحقق من المفاتيح والصيغة وproof وتأكيد النقل، وActions تعيد تحقق الحقيقة داخل المعاملة. CSRF من middleware الموجود؛ لا إعفاءات. validation redirect ثابت نسبي داخل المسار الموثق، لا Referer/return URL. conflict409 صريح. المعرّفات الأجنبية/المفقودة لا تظهر بياناتها؛ scoped lookup404 عند السماح الاصطناعي بالعملية و403 عند رفض Policy. ناتج قوالب Blade escaped وRTL، labels/حالات فارغة/رسائل عربية، بلا HTML خام للمحتوى. الافتراضي operational=false بلا forms أو روابط لعمليات غائبة؛ render في اختبارات الوحدة فقط يمكنه استعمال المسارات المؤقتة.

لا يُفرض دور جودة أو محرر/داعم أو unit assignment غير معتمد. synthetic Policy في ملف الاختبار فقط لإثبات تقييد العملية/المورد والرفض بعد السحب، وليس منطقًا سيُرحل إلى الإنتاج.

## الترحيل والنشر والاسترجاع

22 ملف migration فعليًا، منها الثالث الإضافي approach_changes. لا تعديل لترحيلات38 أو أقدم. MySQL ينشئ audit في CREATE واحد بكل FKs/checks/index؛ SQLite table+index داخل transaction. لا trigger جديد ولا SET GLOBAL أو FK disabling. فشل FK لا يترك جدولًا جزئيًا؛ وجود جدول سابق يسبب رفضًا دون تبنيه أو حذفه.

down الجديد يرفض قبل FIRST DROP إن وجد أي Approach أو Revision أو Audit، حتى بنك به هوية فقط. empty rollback/reapply آمن ومختبر، أما populated فخطة forward recovery مستقلة مع backup ومراجعة. يلزم maintenance حصرية توقف جميع الكتابات قبل rollback؛ existence check ثم DROP ليس قفلًا لحركة مستخدمين حية. تبقى نافذة crash بعد DDL وقبل journal: يفحص المشغل الجدول والـjournal وخطة استرجاع، ولا يحذف أو يعتمد الجدول آليًا.

المتطلبات: MySQL8.4/InnoDB وJSON/CHECK enforced، CREATE/REFERENCES/INDEX وإذن migration operator المناسب للترحيلات السابقة ذات triggers/binlog (ومنها احتمال1419). يُفصل المشغل عن حساب runtime المحدود؛ لا تغيير global config لمعالجة ذلك. وصول performance_schema للاختبار فقط لرصد الانتظار؛ ليس حاجة HTTP. لا migrate على dev/prod ضمن التنفيذ الحالي.

Append-only في Actions وأحداث Eloquent، **ليس WORM ولا إثباتًا تشفيريًا**: raw SQL/bulk/quiet قادر على تغيير أو حذف سجلات متوافقة إذا سمحت امتيازاته. FK/CHECK يحمي البنية والعزل ولا يتحقق من صدق JSON أو عضوية تاريخية. بيانات سجل metadata خاصة بالمؤسسة؛ حسابه readAudit مستقل ولا استثناء منصة.

## بوابات التكامل اللاحقة

G2: مصادقة مالك موثقة على D002 لكل عملية/موارد/assignment/scope/revocation، ثم reconciliation SRS/decision ledger/ADR وقبول قائد التعديل التقني ومساراته. G3/D013: وحدة review حقيقية وسياسة أهلية التعديل قبل المراجعة؛ لا قبول/نشر ضمن هذه المهمة. Issue13 إذن إنتاج مستقل، وIssue37 تكامل الوحدات اللاحق دون لمس ملفاته. يمكن مراجعة A وحده للدمج بإذن منفصل؛ لا يعني اكتمال B أو جاهزية pilot.

نتائج AC/الأوامر والقيود الفعلية في [تقرير40](../engineering/reports/40.md)، والخطة المسبقة في [خطة07](../engineering/PHASE-07-APPROACH-AUDIT-AND-UI-PLAN.md).

تحقق G2 المستقبلي يجب أن يربط قرار grants/assignments الحالي بحد معاملة التعديل وأقفالها، لا أن يكتفي بقرار Gate سابق يمكن أن يتقادم قبل الكتابة. synthetic revocation tests متتابعة؛ تزامن سحب صلاحية أعمال فعلية ما زال تابعًا لمخطط owner-approved وتكامل تقني مستقل، ولا يُدعى اكتماله في adapters غير المسجلة.