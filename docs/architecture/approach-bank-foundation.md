# نواة بنك المنهجيات — Issue38 / Phase06B

## نطاق التنفيذ والاعتماد

نواة داخلية قابلة للاختبار، لا CRUD لمستخدم نهائي. BR-001..006 وFR-APP-001..008/010 ومعايير AC المقابلة تحدد حدود الشريحة. لا تعني البنية المعتمدة وجود مصفوفة D002 تشغيلية. ADR001/003 وحدود المنع في ADR002 محفوظة؛ ADR004 يبقى PROPOSED، ولا يُستنتج اعتماد بديل أو نشر من هذه المهمة. تفاصيل التوفيق مع المصادر الخاصة محفوظة خارج Git.

تعمل: إنشاء هوية منهجية وبياناتها ومالك واحد، قراءة/قائمة/بحث/فرز مقيد بالمؤسسة، وحفظ نصوص مسودة بإصدارات متتابعة. تعديل البيانات ونقل الملكية **مرفوضان** إلى حين اعتماد أثر تاريخي منفصل. لا وثائق أو أدلة أو مراجعات أو اعتماد أو نشر أو درجة نضج أو إشعارات أو Mapping.

## المخطط الفعلي

| الجدول/العمود | النوع/الضمان |
|---|---|
| approaches.id | BIGINT UNSIGNED داخلي في MySQL؛ INTEGER PRIMARY KEY AUTOINCREMENT في SQLite |
| public_id | CHAR(26)، UNIQUE، HasPublicUlid؛ هوية عامة مستقرة في المسار المعتمد |
| organization_id | إلزامي، FK RESTRICT إلى organizations.id |
| owning_unit_id | إلزامي؛ FK مركب (organization_id,owning_unit_id) إلى المفتاح الفريد الموجود organizational_units(organization_id,id) |
| created_by | FK RESTRICT إلى users.id؛ إسناد الفاعل وقت الإنشاء وليس صلاحية دائمة |
| name | VARCHAR(255)؛ لا تفرد للاسم داخل المؤسسة أو خارجها |
| description/purpose/scope | LONGTEXT إلزامية؛ لا قالب تفصيلي ثابت |
| objectives/review_frequency | LONGTEXT nullable؛ لا تُفرضان عند الإنشاء ولا يدعي هذا التنفيذ اكتمالهما قبل اعتماد مستقبلي |
| created_at/updated_at | TIMESTAMP إلزامية؛ بيانات الهوية لا تُعدل في هذه الشريحة |
| approaches(organization_id,id) | مفتاح UNIQUE حقيقي يدعم علاقات التاريخ المركبة |
| approach_content_revisions.id/public_id | BIGINT داخلي وULID فريد مستقل للإصدار |
| organization_id/approach_id | FK مركب RESTRICT إلى approaches(organization_id,id)، وFK مباشر للمؤسسة |
| actor_id | FK RESTRICT إلى users.id يحفظ المؤلف حتى بعد سحب عضويته |
| version_number | INTEGER UNSIGNED، CHECK بين1 و4294967295، UNIQUE(approach_id,version_number) |
| body | LONGTEXT إلزامي، نص UTF-8 عادي محفوظ دون إعادة كتابة/تنظيف للنسخ السابقة |
| created_at | TIMESTAMP إلزامي؛ لا updated_at ولا حالة Approved/Published/Score |

ليس FK المؤلف مركبًا إلى users(id,organization_id)، لأن مخطط الهوية الحالي يسمح بفاعل legacy ذي عضوية، ولأن سحب عضوية لاحق لا يجب أن يمسح المؤلف التاريخي. Action يتحقق من الحساب والعضوية الحاليين عند الكتابة؛ DB يثبت وجود المؤلف. لا يسمح هذا بالوصول المركزي للمحتوى.

اختير نص عادي ذو إصدارات بدل JSON للأقسام: يفي بالمرونة دون اختراع مخطط أعمال أو محرك نماذج. ترقية التنسيق مستقبلًا تحتاج إصدارًا صريحًا مع إبقاء bytes القديمة وقواعد عرض آمنة. لا يُفسر HTML داخل body؛ Blade يهربه.

## حدود Actions

`CreateApproach::execute(actor,data)` يقبل فقط name/description/purpose/scope/objectives/review_frequency/owning_unit_public_id. يرفض مفاتيح organization/actor/owner الداخلية والهوية والحالات والحقول غير المعروفة. tenant من TenantContext فقط، والمالك يُبحث عنه بULID في المؤسسة نفسها. المعرفات الأجنبية والمفقودة والمشوهة للمورد تنتج نفس ModelNotFoundException دون عرض الاسم أو المعرف الأجنبي.

`CreateApproach::withinContext` حد معاملات خاص بهذه الشريحة، يعاد استخدامه من Actions القراءة والحفظ لتجنب نسخ منطق أمني أو إدخال helper خارج النطاق. يتحقق من تطابق actorId مع السياق، ثم يعيد قراءة المؤسسة والحساب والعضوية بقفل حالي، ويرفض platform وربط institution الأجنبي حتى لو تغير نموذج المستدعي في الذاكرة. هذا حد داخلي موثوق مثل الخدمات الموجودة، **وليس Policy أو قدرة مستخدم**. لا يؤخذ actor من payload. لا يوجد مسار HTTP يصل إليه.

`ListApproaches::execute` يرجع LengthAwarePaginator من arrays محدودة، بترتيب name أو created_at ثم id كفاصل داخلي. page1..1000000 وperPage1..100 وsearch حتى255 محرفًا قيود تقنية قابلة للمراجعة. WHERE organization_id إلزامي، البحث LIKE بمعاملات وescape صريح للمحارف !/%/_. لا تُنسخ query/URL من طلب عميل إلى روابط pagination. تحميل المالك eager ومقيد بالمؤسسة؛ لا N+1. `show` يعيد تحميل ULID داخل المؤسسة بدل قبول نموذج mutable. لا يتضمن الناتج BIGINT أو محتوى مسودات أو درجة/اعتمادًا. الـid الداخلي في ترتيب SQL لا يظهر في عقد العرض.

`SaveApproachContentRevision::execute` يقبل ULID المنهجية ونصًا فقط؛ يعيد تحميل المنهجية ضمن السياق ويقفلها، ثم أحدث إصدار بقراءة حالية. يخصص n+1، ويسند actor/organization/time داخليًا. النص المطابق تمامًا لآخر إصدار no-op يعيد الإصدار نفسه؛ لا يغير مؤلفه/وقته ولا يسجل حدثًا مضللًا. النص المختلف ولو عاد إلى محتوى إصدار أقدم يُنشئ إصدارًا جديدًا. قراءة التاريخ متاحة للعقد الداخلي من علاقة revisions المقيدة بالمنهجية والمؤسسة؛ لا يوجد endpoint قراءة مسودة أو افتراض وصول للقارئ العام.

`UpdateApproach` و`ChangeApproachOwner` يرفضان صراحة حتى ضمن سياق صالح. لا نستعمل سجل المؤسسة أو سجل الصلاحيات لأحداث المنهجية. طلب التكامل المحدد: `app/Models/ApproachChange.php` وترحيل ثالث مستقل للأحداث السابقة/اللاحقة، actor/time، FK تاريخية وtransaction/no-op/rollback، ثم تفعيل Actions الموجودة بعد اعتماد النطاق. يلزم قرار لاحق لعقد البيانات المتغيرة قبل الاعتماد؛ لا يفتح اعتماد الملفات الثمانية هذه البوابة.

الاسم حتى255 والنصوص الوصفية حتى65535 والمحتوى حتى1000000 محرف، مع رفض الفراغ/UTF-8 غير الصحيح. هذه حدود تنفيذ تقنية وليست سياسة مالك. لا قص أو تطبيع أو تفرد أو تصنيف مخترع. الحقول الصحيحة تحفظ كما وردت. ضوابط النموذج تمنع mass assignment لحقول الربط والتاريخ؛ forceFill مخصص للـAction بعد التحقق أو fixture داخلية.

## المعاملات والتنافس

بداية الأقفال مطابقة للبروتوكول الحالي: organization ثم actor ثم membership، جميعها FOR UPDATE. بعد ذلك Create يقفل الوحدة، وSave يقفل المنهجية ثم آخر إصدار. لا تُقفل وحدة/منهجية قبل المؤسسة. لا عمل عبر مؤسستين داخل transaction ولا I/O خارجي ولا retry صامت. الخطأ أو rollback الخارجي يلغي الكتابات التابعة. الـUNIQUE دفاع DB إضافي وليس بديلًا للقفل.

قفل المؤسسة واسع وقد يسلسل قراءات/كتابات المؤسسة، بل يتشارك مع خدمات الوحدات. هذا تبسيط اتساق قابل للمراجعة، لا ادعاء أداء أو deadlock-free لكتّاب غير متعاونين. القوائم تستخدم قراءة MVCC عادية بعد التحقق الحالي، وقد ترى snapshot أقدم إذا استدعاها caller داخل transaction قديمة؛ لا تدعي newest-state read لمحتوى القائمة. قرارات الفاعل والنسخة تستخدم current reads، فتمنع snapshot قديمة من تجديد عضوية مسحوبة أو تخصيص رقم قديم.

اختبار MySQL ذو عمليتين مستقلتين يؤسس snapshot قديمة للإصدارات، ويرصد انتظار InnoDB على صف المؤسسة في performance_schema ثم يحرر القفل ويطلب رقمًا جديدًا. لا تُستنتج سلامة التنافس من SQLite. نتائج التنفيذ الفعلية، ومنها أي إخفاق، في report38.md.

## ما يفرضه كل حد

| الحد | الضمان/القيد |
|---|---|
| DB MySQL | InnoDB، tenant FKs مركبة بمفاتيح فريدة قياسية، RESTRICT، ULID/version uniqueness وCHECK رقم النسخة |
| SQLite | FKs/UNIQUE/CHECK الأساسية للاختبارات؛ لا يحاكي صفوف FOR UPDATE أو InnoDB أو صلاحيات المشغل |
| Actions | سياق وعضوية حاليان، بحث مقيد، فاعل حقيقي، payload محصور، معاملات، تخصيص نسخ/no-op |
| أحداث Eloquent | تمنع تعديل/حذف Approach وRevision في ordinary model operations؛ HasPublicUlid يحفظ الهوية |
| raw SQL/bulk/quiet event bypass | ليس تخزينًا محصنًا؛ مشغل DML قادر على تغيير قيم متوافقة أو حذف leaf revisions. لا تُمنح هذه مسارات تطبيق معتمدة. حماية تشفير/امتيازات/تخزين أقوى تحتاج مهمة مستقلة |
| الواجهة | ثلاثة قوالب عربية RTL index/show/content غير مسجلة، escaped، بلا forms أو عمليات أو بيانات وهمية |

النماذج الداخلية وعلاقاتها ليست serializing API عامة؛ العرض يستخدم arrays المصرح بها فقط. لا تمرر User/Eloquent relations كاملة إلى HTTP مستقبلًا. قائمة metadata لا تكشف draft body، ولا يمثل وجود UI template تخويلًا. لا Controller/FormRequest/Policy/Route/Filament Resource/Panel جديد، ولا registry entries/permissions/grants.

## الترحيل والتراجع والنشر

ترحيلان إضافيان فقط. MySQL ينفذ CREATE واحدًا inline لكل جدول مع فهارس (organization_id,created_at,id) و(organization_id,name,id) للقائمة وفرزها. SQLite ينفذ CREATE وفهارسه داخل transaction DDL واحدة. البحث المحتوي على wildcard بادئ ليس ادعاءً لاستعمال فهرس نصي أو benchmark. لا TRIGGER جديد ولا SET GLOBAL أو تعطيل FK؛ تبقى متطلبات الترحيلات التاريخية للـtrigger/binlog منفصلة. CREATE يفشل ذريًا عند FK غير صالح؛ الجدول السابق الوجود لا يُتبنى ولا يُحذف. الانقطاع بعد DDL ناجح وقبل journal يظل نافذة تحتاج فحص مشغل، لا retry يمسح بيانات.

down للترحيل الأحدث يفحص وجود أي Approach أو Revision قبل إسقاطه؛ القديم يحمي صفوف الهوية. rollback الفارغ يُختبر، والممتلئ يرفض حتى لو لم يوجد محتوى. يجب توقف جميع الكتابات ضمن نافذة صيانة حصرية قبل DDL؛ الفحص ثم DROP ليس بروتوكولًا لتزامن تراجع أثناء حركة المستخدمين. لا حذف لفرض نجاح rollback، ولا عودة لبرنامج قديم يمحو تاريخًا؛ تصحيح forward مع نسخة احتياطية وخطة مراجعة هو المسار اللاحق.

## تكامل لاحق

الصلاحيات والتفعيل تحتاج قرارًا مستقلًا لملفات CapabilityRegistry/config/Policies/Provider/routes ومصفوفة actor/action/org/resource/assignment/state. لا ينتقل حق التحرير/الاعتماد مع membership أو اسم Role أو دعم وحدة. الواجهات الخاصة بالمستخدم يجب أن تبني actor/context من الحارس المؤسسي الموثوق لكل طلب، مع إعادة فحص سحب الوصول. لا صلاحية platform ضمنية.

الوثائق والشواهد والمراجعات والنضج والربط ستحتاج مرجع Approach ULID وRevision ULID مع tenant FK، وحفظ دليل/قرار/تنسيق النسخة، وعقد draft-vs-published منفصلًا. أهداف ما قبل الاعتماد وتعديل البيانات والملكية تحتاج أولًا أثر تغييرات معتمدًا؛ لا تربط ما ورد هنا تلقائيًا بمحرك إشعارات أو اعتماد.

ملفات Issue37، auth/guards، الترحيلات السابقة، CI والحزم لم تتغير. أُجيزت فقط توسعة الاختبارات/عقد المخطط ذات الملفات الثمانية في خطة Issue38 وسجله، مع بقاء جميع سيناريوهات regression وضماناتها.

مراجع تقنية فقط: [MySQL8.4 FK](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)، [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)، [Laravel13 transactions](https://laravel.com/docs/13.x/database)، [migrations](https://laravel.com/docs/13.x/migrations). ليست هذه المراجع سلطة لتحديد الصلاحيات أو الأعمال.

قراءة التاريخ: ListApproaches::draftHistory حد داخلي صريح منفصل عن show، يعيد صفحات حتى20 إصدارًا من arrays بلا BIGINT وبتحميل مؤلف eager. author_public_id قد يكون NULL لحساب legacy الذي لا يملك ULID؛ يبقى actor_id الحقيقي محفوظًا داخليًا ولا يولد هذا العمل هوية للمستخدم. لا يُسلّم هذا العقد إلى قارئ عام أو HTTP قبل اعتماد صلاحية قراءة المسودة.
