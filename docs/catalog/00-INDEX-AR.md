# دليل الفهرس والتسليم

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.

تعني `IMPLEMENTED_TEST_ONLY` وجود تنفيذ مثبت داخل سيناريو معزول مؤقت، و`PARTIAL` وجود جزء عامل، و`GATED_OWNER_DECISION` اعتمادًا تشغيليًا غير محسوم، و`NOT_IMPLEMENTED` غياب الرحلة. لا تُستخدم `OPERATIONAL_ACCEPTED` دون قبول مالك مستقل L4. نجاح الاختبارات لا يمنح مستخدمًا صلاحيات ولا يقبل المتطلبات الـ105 مجتمعة.

هذا الفهرس للمهندس والمشغل. يبدأ صاحب المشروع من [مصدر تقريره](../engineering/delivery/PROJECT-OWNER-COMPLETE-HANDOVER-SOURCE-AR.md). نسخة Word النهائية خاصة، خارج Git.

1. [دليل الفهرس والتسليم](00-INDEX-AR.md)
2. [المنتج والقدرات](01-PRODUCT-AND-CAPABILITIES-AR.md)
3. [المعمارية وخريطة المصدر](02-ARCHITECTURE-AND-SOURCE-MAP-AR.md)
4. [قاموس قاعدة البيانات](03-DATABASE-DICTIONARY-AR.md)
5. [الهوية والحسابات والصلاحيات](04-IDENTITY-ACCOUNTS-AND-PERMISSIONS-AR.md)
6. [المسارات والشاشات والرحلات](05-ROUTES-SCREENS-AND-JOURNEYS-AR.md)
7. [الوثائق والمراجعة والنضج](06-DOCUMENT-REVIEW-AND-MATURITY-AR.md)
8. [الأمان والاختبارات والمخاطر](07-SECURITY-TESTS-AND-RISKS-AR.md)
9. [التشغيل والبيئات والاستعادة](08-OPERATIONS-ENVIRONMENTS-AND-RECOVERY-AR.md)
10. [التغييرات والقرارات وخريطة الطريق](09-CHANGELOG-DECISIONS-AND-ROADMAP-AR.md)
11. [بيان المستودع والتسليم](10-REPOSITORY-HANDOVER-MANIFEST-AR.md)

## دليل التشغيل والتحقق

- [Windows وHerd وMySQL وphpMyAdmin](../operations/LARAVEL-HERD-WINDOWS-RUNBOOK-AR.md).
- [الحسابات وTinker والصلاحيات](../operations/LOCAL-USERS-TINKER-AND-PERMISSIONS-AR.md).
- [مراجعة main وIssue37](../engineering/reports/FINAL-MAIN-AND-ISSUE37-AUDIT-2026-11-12.md)، [التنظيف](../engineering/reports/FINAL-HOUSEKEEPING-STATUS-2026-11-12.md)، [تقرير المهمة](../engineering/reports/71.md).
- [السجل الكامل للقبول](../engineering/delivery/ACCEPTANCE.md)، [مصفوفة الشاشات](../engineering/delivery/OWNER-DEMO-COMPLETION-MATRIX.md).

أُغلقت العروض القديمة بطلب المالك بعد التحقق من ملكيتها. لا توجد دعوة إلى استعمال روابطها أو بياناتها المنتهية، ولا تبدأ هذه المهمة عرضًا جديدًا. إعداد عرض مستقبلي يتطلب fixture جديدة محددة ومعزولة؛ تشغيل Herd وحده لا يفعّل منح TEST_ONLY.
