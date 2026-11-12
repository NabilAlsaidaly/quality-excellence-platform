# الوثائق والمراجعة والنضج

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.


## الوثيقة ونسختها

الحد 30,000,000 بايت عشري لكل نسخة؛ PDF/DOCX فقط، لا DOC. InspectDocumentFile يفحص الاسم والحجم وMIME وبنية PDF/OOXML والروابط/الأجزاء الخطرة وحدود الأرشيف؛ هذا screening محدود، **ليس محرك مكافحة برمجيات خبيثة**. التخزين خاص، بلا public URL. RegisterDocumentIntake يملك حد SQL/file compensation، يكتب document/version/intake وأحداثها مع idempotency وexpectedVersion، ويتخلص فقط من الملف المملوك لهذه المحاولة عند الفشل.

الإصدارات السابقة ثابتة؛ metadata revision منفصلة لا تعيد كتابة ملف أو لقطة معتمدة. الإسناد للرفع/الإضافة/التنزيل/الإتاحة منفصل ومحدد المورد أو النسخة. مجرد إنشاء وثيقة أو امتلاك جلسة لا يمنح عملية أخرى. [RegisterDocumentIntake](../../app/Actions/Documents/RegisterDocumentIntake.php)، [DocumentFileWorkspace](../../app/Actions/Documents/DocumentFileWorkspace.php)، [الاختبار](../../tests/Feature/Documents/RealDocumentLifecycleTest.php).

## الفحص والإتاحة

عقد المسار الحقيقي يفصل pending/quarantine وscanning وclean/failed/rejected وreleased؛ نجاح scanner وحده لا يطلق الملف. المكوّن الإنتاجي الحقيقي وربطه وتشغيله واعتماده غير مكتمل؛ لا نتيجة clean مصطنعة. الاستثناء `available_demo_unscanned` قرار منفصل في جدول مستقل لعينة معدة مسبقًا ومثبتة البصمة، لم يبدأ فحصها، بمراجع مستقل وصلاحية مؤقتة دقيقة. لا يقبل failed/rejected/suspicious أو نتيجة غير مؤكدة، ولا يصبح اعتماد وثيقة مؤسسيًا أو قبول شاهد.

DownloadDemoDocumentVersion يعيد فحص الهوية والإسناد والقرار الدقيق، ثم يفتح stream ببصمة/حجم متحققين وheaders خاصة/no-store/nosniff. حدث downloaded يسجل فتح stream مخولًا، ولا يثبت وصول كامل البايتات للعميل. أربع مقارنات SHA256 في المتصفح المعزول نجحت؛ Chrome/Edge العاديان أخفقا canceled بسبب IDM، ولم يثبت إصلاحهما. [قرار الإتاحة](../../app/Services/Documents/DemoDocumentAvailability.php)، [التنزيل](../../app/Actions/Documents/DownloadDemoDocumentVersion.php).

## المراجعة والشواهد

المحتوى snapshot ثابتة عند الإرسال. طلب التصحيح لا يستبدل اعتمادًا سابقًا؛ التصحيح ينشئ submission/revision جديدة ويعيد المراجعة. المراجع مستقل عن المؤلف والمساهمين. الشاهد يحدد DocumentVersion بالضبط ويُعاد التحقق من المؤسسة والمورد والصلاحية وإتاحته وقت الاستخدام؛ كفاية الشاهد قرار سياقي مستقل. ReviewMaturityNotifications تحفظ المستلمين المعينين صراحة مع القرار في المعاملة نفسها، وتفصل حالات القراءة، بلا استنتاج قائمة مستلمين من أسماء الأدوار.

## النضج الأولي وإعادة التقييم

المراحل بالترتيب: **التوجه، النظام، التنفيذ، القياس، التحسين والتطوير**. كل مرحلة تعتمد أسئلة وتعليمات وrequired/optional وتعريف شواهد وسلمًا مرتبًا محفوظًا، وإجابات وشواهد دقيقة ومراجعة مستقلة. لا متوسط أو نسبة مشتقة تلقائيًا. [قواعد Domain](../../app/Domain/Maturity)، [Actions](../../app/Actions/Maturity).

تبدأ إعادة التقييم بإذن منفصل على مرحلة واحدة، ومحاولة نشطة واحدة. تُعاد snapshot الإعداد الأصلية المرتبطة بأحدث تقييم فعال، لا أحدث إعداد قابل للتحرير. إعادة استعمال الشواهد اختيار صريح مع تحقق حالي وكفاية مستقلة. decisions ترتبط بالمحاولة approved والـsubmission والـreview المطابقتين؛ initial-stage review لا يعاد استعمالها كقرار إعادة تقييم.

- pending أو changes_requested لا يغير النتيجة السابقة أو المراحل اللاحقة.
- LOWER بعد اعتماد مستقل يحفظ التاريخ ويعلم المراحل اللاحقة REVALIDATION_REQUIRED، ويمنع أعمالها الجديدة حتى إعادة تحقق متسلسلة.
- EQUAL/HIGHER يحفظ صلاحية اللاحق، ولا يعدل فئاته أو شواهده أو يعيد استخدام قراراته تلقائيًا.
- optimistic revision وidempotency وأقفال المعاملة تمنع stale/duplicate decisions؛ التعارض يرفض بلا نجاح كاذب.

[EffectiveMaturityState](../../app/Actions/Maturity/EffectiveMaturityState.php) إسقاط حالي مشتق من تاريخ قرارات ثابت، و[DecideStageReassessment](../../app/Actions/Maturity/DecideStageReassessment.php) يربط القرار والأثر والتدقيق والإشعار ذريًا. لا تعديل تقييم قديم ولا حذف مراحل لإجبار إعادة الدورة. هذه دلالات TEST_ONLY معتمدة هندسيًا؛ لا قبول D014/D002 التشغيلي.
