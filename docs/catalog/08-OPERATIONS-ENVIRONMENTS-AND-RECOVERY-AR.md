# التشغيل والبيئات والاستعادة

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.


| البيئة | المصدر والهدف | المسموح/المحظور |
|---|---|---|
| Herd local المعتادة | checkout الأصلي و.env/APP_KEY/قاعدة التطوير محفوظة | لا نقل schema52 التجريبية إليها بلا اعتماد منفصل |
| testing عابرة | SQLite memory أو MySQL8.4 quality_platform_ci مملوكة حصريًا | explicit opt-in وهوية UUID؛ لا تبنِّ قاعدة من اسمها فقط |
| demo معزولة | host loopback + sessions/private root وfixture proof | العروض15 متقاعدة؛ إعداد مستقبلي منفصل، لا تجديد منح قديمة |
| staging/production | لا بيئة منشورة من هذه المهمة | identity candidate والوصول التشغيلي fail-closed؛ owner gates |

لا worker خاص بالتطبيق في app/Jobs. Redis cache/queue مهيأ، وredis queue after_commit=true، بينما in-app notices تثبت داخل معاملة MySQL. لا حاجة لتشغيل worker كي تتظاهر بوجود إرسال خارجي. sessions العادية database؛ fixture sessions ملفات منفصلة. [queue](../../config/queue.php)، [session](../../config/session.php)، [storage](../../config/filesystems.php).

## الاستعادة والصيانة

1. حدد الخادم/المنفذ/المخطط والنسخة والـjournal بصلاحية قراءة، وأوقف الكتابة فقط ضمن نافذة صيانة معتمدة.
2. خذ backup خاصًا مشفرًا/مقيد الوصول واختبر استعادته إلى هدف منفصل. لا تحذف النسخة الأصلية أو تستبدل APP_KEY.
3. ميّز restore للبيانات عن migrate للبنية. راجع pending SQL وTRIGGER/REFERENCES/binlogging قبل المشغل المنفصل.
4. أبقِ runtime بصلاحيات DML محدودة، ولا تضع root/operator في .env العادي. لا SET GLOBAL أو ثقة scanner لتجاوز خطأ.
5. عند DDL ناجح دون journal، قارن SHOW CREATE والقيود والصفوف والسجل، ثم قرار إصلاح محدد؛ لا إسقاط/اعتماد تلقائي.
6. عند حادث، احتفظ بالأدلة المصونة والبصمات، اسحب الوصول المحدد وفق المسار المعتمد، ولا تمسح logs أو sessions للجميع.

لا توجد سياسة تشغيلية معتمدة لـRPO/RTO أو موقع النسخ والاحتفاظ أو مراقبة إنتاجية أو فحص ملفات أو استضافة. لا تقديرات مخترعة. [دليل المشغل](../operations/LARAVEL-HERD-WINDOWS-RUNBOOK-AR.md) يعطي أوامر آمنة وموسومة TESTED/NOT_EXECUTED. phpMyAdmin عميل مستقل، وليس MySQL server؛ حفظ رابطه وإعداداته لا يثبت عمله حين Herd غير جاهز.
