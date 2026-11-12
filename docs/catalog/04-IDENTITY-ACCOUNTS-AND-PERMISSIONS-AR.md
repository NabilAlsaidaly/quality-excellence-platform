# الهوية والحسابات والصلاحيات

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.

تعني `IMPLEMENTED_TEST_ONLY` وجود تنفيذ مثبت داخل سيناريو معزول مؤقت، و`PARTIAL` وجود جزء عامل، و`GATED_OWNER_DECISION` اعتمادًا تشغيليًا غير محسوم، و`NOT_IMPLEMENTED` غياب الرحلة. لا تُستخدم `OPERATIONAL_ACCEPTED` دون قبول مالك مستقل L4. نجاح الاختبارات لا يمنح مستخدمًا صلاحيات ولا يقبل المتطلبات الـ105 مجتمعة.

## مصفوفة الحسابات والتفويض

| نوع الممثل | guard/provider | الارتباط والنطاق | نقطة الدخول | المسموح المثبت | المنع والمُصدر/الساحب |
|---|---|---|---|---|---|
| legacy محلي | web/users، driver legacy | لا ربط institution في سجل المستخدم؛ العضوية مستقلة | /admin/login | غلاف تطوير محلي/testing | لا صلاحيات مؤسسة؛ لا Super Admin |
| institution | institution/institution | public_id ومنظمة ثابتان، بريد normalized، عضوية حالية | /institution/login ثم حساب المنظمة | الدخول والاستعادة ضمن المرشح | العضوية وحدها لا تمنح لوحة أو بيانات؛ bootstrap التشغيلي محجوب |
| platform كسجل | لا guard platform مسجل في config/auth.php | binding منفصل في users | لا لوحة مركزية تشغيلية مفعلة | foundation فقط | لا دخول تلقائي أو قراءة محتوى المؤسسات |
| مؤلف/جودة/مراجع/قارئ fixture | institution والـPolicy الحقيقية | مؤسسة ومورد/نسخة/مرحلة وإسناد 24 ساعة | لوحة harness الاختبارية | عمليات مسماة صريحة، بلا تفويض متسلسل | المُصدر المسجل في السيناريو فقط؛ السحب/الانتهاء يرفضان الحالة الحالية |

المرجع [User](../../app/Models/User.php)، [auth](../../config/auth.php)، [InstitutionUserProvider](../../app/Identity/InstitutionUserProvider.php)، [TenantContext](../../app/Authorization/TenantContext.php)، [RoleManagement](../../app/Authorization/RoleManagement.php). يدعم نموذج العضويات الأساس التقني العالمي، بينما مرشح الدخول الحالي يربط حساب institution بمؤسسة ثابتة وبريد قابل للتكرار بين المؤسسات؛ توفيق ADR004 والمصدر ما زال منفصلًا عن تنفيذ العرض.

لا تُطبع قوائم أشخاص فعلية أو hashes أو reset tokens. الحقول الآمنة للتشخيص المحلي: principal_type، public_id، organization_id، عدد العضويات/الإسنادات وحالتها، بعد التحقق من صلاحية المشغّل؛ لا تنشر مخرجاتها.

## ما يمنح الوصول فعلًا

`config/authorization.php` يحوي capabilities تشغيلية **فارغة**. المفتاح الاختباري approaches.access ليس منحة ولا يُفعّله إدخال config وحده. CapabilityRegistry لا يعترف إلا بخريطة محددة، وTenantAuthorization يتطلب سياقًا مثبتًا وموردًا صحيحًا ودورًا مؤسسيًا بguard web وصلاحية معروفة. Spatie Teams يستخدم organization_id؛ لا تُفسَّر direct user permissions أو اسم Admin بوصفها تفويضًا للتطبيق.

ApproachAssignmentAccess وDocumentWorkspaceAccess وReviewMaturityAccess تتطلب proof محدودًا في التطبيق/الطلب/المعاملة الاختبارية. المنح resource/version/stage scoped، finite، قابلة للسحب، ولا تورثها العضوية أو المنشئ. تعريفات اختبارية خارج bootstrap المعتاد لا تنشئ مسارات للعميل العادي.

## كلمات المرور والجلسات

كلمة المرور hashed cast، والهوية binding ثابت. المؤسسة مطلوبة في الدخول والاستعادة لأن البريد قد يتكرر بين المؤسسات. BrowserAccountSession يمنع الرجوع تلقائيًا إلى هوية متصفح سابقة؛ InstitutionSession يتحقق من عضوية وربط وhash كلمة المرور الحالي. الاستعادة محددة الحساب والمنظمة، signed URL، token مستهلك، مع throttling ورسالة غير كاشفة. لا تطبع رابطًا موقعًا في دليل عام. التغيير يرفض الجلسات القديمة عند التحقق الحالي، وليس ترخيصًا لمسح جلسات كل المستخدمين.

لا Action إنتاجية معتمدة لإنشاء موظف وعضوية ودعوة وأدوار تشغيلية كرحلة واحدة. OrganizationProvisioning وRoleManagement داخليان، ووجود actor للتدقيق ليس صلاحية إدارية. [دليل Tinker](../operations/LOCAL-USERS-TINKER-AND-PERMISSIONS-AR.md) يقدم غلاف legacy واختبارًا معزولًا، ولا يختلق bootstrap مؤسسيًا.
