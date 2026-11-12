# دليل تشغيل المنصة على Windows وLaravel Herd

> مراجعة 12 نوفمبر 2026 · المصدر `main@03c829fde71bbb11754ef657bf2edfe50253068e` · Issue #71. أوامر الكتابة غير المنفذة موسومة صراحة. لا تغييرات على `.env` أو بيانات التطوير.


## A — المتطلبات والفحص الأول

هذا دليل لمهندس التشغيل، وليس إذنًا لترقية قاعدة موجودة. ابدأ بجرد الهدف وبنسخة احتياطية خاصة. أُجريت الفحوص الآمنة على نسخة عمل توثيقية مستقلة وقواعد اختبار مملوكة؛ لم تُجرَ ترحيلات على `quality_platform`.

| العنصر | المصدر/المشاهد | النتيجة الحالية |
|---|---|---|
| PHP | 8.4.27؛ `composer.json` | CLI ناجح؛ تحقق من PHP الذي يستخدمه Herd أيضًا |
| Laravel / Filament / Livewire | 13.39.0 / 5.11.0 / 4.4.8 من lock | لا تستخدم إصدارًا من الذاكرة |
| Composer | 2؛ strict/platform/audit | PASS، دون تحديث lock |
| Node | 24، `.nvmrc` وCI | استخدم `npm ci` وليس تحديث الحزم |
| MySQL | 8.4.13 في الاختبار | خدمة Windows باسم MySQL97 موجودة؛ لا تفترض توافقها من المنفذ |
| Redis | 8.2.11 في Compose/CI | لا يلزم تشغيله لمجرد قراءة الكتالوج |
| Herd | 2.1.1 مثبت | Desktop غير جاهز أثناء الفحص؛ الروابط محفوظة |
| phpMyAdmin | رابط Herd منفصل محفوظ | HTTP غير متاح أثناء الفحص؛ ليس دليل فقد قاعدة البيانات |

READ_ONLY، نفّذ داخل نسخة المشروع المقصودة:

```powershell
Get-Command php,composer,node,npm,git,herd | Select-Object Name,Source
php --version
php --ini
php -m
composer --version
node --version
npm --version
git status --short --branch
git remote get-url origin
git worktree list
Get-NetTCPConnection -State Listen | Where-Object LocalPort -in 80,443,3306,13306,6379,5173 | Select-Object LocalAddress,LocalPort,OwningProcess
```

المتوقع PHP8.4 وامتدادات PDO/mysql/sqlite وmbstring/intl/fileinfo/zip/redis، وComposer2 وNode24. المرجع النهائي `composer check-platform-reqs`؛ لا تعدل php.ini عالميًا لتجاوز نقص بلا مراجعة. قد يتطلب جرد العمليات صلاحية Windows أعلى للقراءة فقط. لا تقتل عملية تشغل منفذًا قبل تحديد مالكها.

## B — نسخة جديدة ونسخة موجودة

NOT_EXECUTED، مثال نسخة جديدة في مجلد فارغ يختاره المهندس:

```powershell
git clone https://github.com/NabilAlsaidaly/quality-excellence-platform.git quality-excellence-platform
Set-Location -LiteralPath '.\quality-excellence-platform'
git switch main
git pull --ff-only origin main
```

لنسخة موجودة: تحقق من remote الصحيح، و`git status` وworktrees أولًا. إذا كانت نظيفة وعلى main يمكن fast-forward فقط. إذا كانت غير نظيفة، احفظ تغييراتها واتفق مع صاحبها؛ لا reset ولا استبدال فرع أو `.env` أو APP_KEY. لا تبدّل فرعًا مستخدمًا في worktree آخر. في هذه المهمة بقي checkout الأصلي كما كان؛ مصدر المراجعة main البعيد، وليس ترقية صامتة لنسخة التشغيل.

## C — موقع Herd

READ_ONLY عند تشغيل Herd Desktop بواسطة المستخدم:

```powershell
herd links --json
herd which-php
herd php --version
```

أُجريت المحاولة محليًا؛ أعادت CLI أن Herd Desktop غير مشغّل، فلم يثبت نجاح هذه الأوامر في حالة التشغيل. روابط `quality-excellence-platform` و`phpmyadmin` موجودة ومحفوظة. فشل `/up` و`/admin/login` و`phpmyadmin.test` في الاستجابة أثناء التسليم. شغّل Herd من واجهته المعتادة ثم أعد الفحص؛ لا تعِد ربط موقع موجود ولا تغيّر hosts أو nginx أو شهاداته.

NOT_EXECUTED، فقط إذا كان alias غير موجود و`herd link --help` يؤكد دعم الخيارات في النسخة المشغلة:

```powershell
herd link quality-excellence-platform --isolate=8.4 --no-interaction
```

Herd يخدم مجلد `public`؛ لا يحتاج `artisan serve`. بديل اختياري منفصل، بعد فحص المنفذ وبيئة local، وليس معارضًا لموقع Herd:

```powershell
php artisan serve --host=127.0.0.1 --port=8017
```

هذا البديل NOT_EXECUTED هنا ولا يشغّل provider العرض الاختباري. [Herd Windows](https://herd.laravel.com/docs/windows/getting-started/installation).

## D — إعدادات نسخة جديدة فقط

NOT_EXECUTED، لا تنفّذ على تثبيت قائم:

```powershell
if (Test-Path -LiteralPath '.env') { throw 'Preserve existing environment' }
Copy-Item -LiteralPath '.env.example' -Destination '.env'
php artisan key:generate
```

حرر `.env` الخاص محليًا: APP_ENV=local، APP_URL=http://quality-excellence-platform.test، debug وفق بيئة التطوير دون إتاحتها للشبكة؛ SESSION_DRIVER=database، QUEUE_CONNECTION=redis، CACHE_STORE=redis، إعدادات خادم/منفذ/اسم قاعدة مخصصة. تأكد من locale/timezone في [config/app.php](../../config/app.php) وواجهة RTL؛ لا تفترض تغيير المنطقة الزمنية التاريخية للبيانات. حدّد REDIS_PREFIX فريدًا وSESSION_COOKIE فريدة لنسخة منفصلة. حافظ على APP_KEY الحالي في أي تحديث أو استعادة. لا تعرض `.env` أو `config:show database` الكامل في تقرير أو جلسة مسجلة.

## E — اختيار MySQL وphpMyAdmin

phpMyAdmin واجهة عميل؛ افتح خانة الخادم والمنفذ ثم تحقق عبر SQL القراءة:

```sql
SELECT VERSION(), DATABASE(), @@hostname, @@port, @@server_uuid;
SHOW VARIABLES WHERE Variable_name IN ('log_bin','binlog_format','log_bin_trust_function_creators');
SHOW GRANTS FOR CURRENT_USER;
```

READ_ONLY؛ نتائج الهوية خاصة لا تنشرها. 3306 كان لخدمة Windows، بينما 13306 مثال fallback مع [compose.mysql-local.yml](../../compose.mysql-local.yml)، وليس قاعدة مؤكدة في هذه الجلسة. لا توجّه التطبيق إلى خادم بمجرد تطابق المنفذ. لا تبدّل إعدادات phpMyAdmin الموجودة؛ اختر الخادم الصحيح وفق إعداداته المعتمدة.

NOT_EXECUTED — SQL لمشغّل قاعدة مخوّل على هدف جديد منفصل فقط، يستبدل القيم بنفسه خارج سجل عام:

```sql
CREATE DATABASE quality_platform_local_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qep_runtime'@'127.0.0.1' IDENTIFIED BY '<PRIVATE_RUNTIME_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON quality_platform_local_new.* TO 'qep_runtime'@'127.0.0.1';
CREATE USER 'qep_migrator'@'127.0.0.1' IDENTIFIED BY '<PRIVATE_OPERATOR_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER ON quality_platform_local_new.* TO 'qep_migrator'@'127.0.0.1';
```

هذه صياغة لمراجعة المشغّل، وليست ضمانًا بأن منحًا على مستوى schema تحل شرط binary logging أو DEFINER. لا تنفذ كلمات placeholder. اختر host الذي يطابق الاتصال الفعلي وفق حساب MySQL، ولا توسّعه إلى `%`. افصل سر المشغّل عن runtime، ولا تضع root في `.env`. من الطبيعي أن ترفض runtime DDL. [قواعد MySQL للـtriggers](https://dev.mysql.com/doc/refman/8.4/en/triggers.html).

## F — الترحيلات وDDL

READ_ONLY بعد التحقق من الهدف وباستخدام بيئة مخوّلة:

```powershell
php artisan migrate:status
php artisan migrate --help
```

المهاجرات ليست بيانات مستخدمين. جرد 12 نوفمبر في قاعدة اختبار جديدة: **52 migration و50 جدولًا بما فيها migrations**. هذا checkpoint فقط؛ لا تتوقعه قسرًا في قاعدة تطوير قديمة. راجع كل pending migration وموانعها قبل التنفيذ. بعض الجداول اختبارية وتتطلب حارس testing/disposable واضحًا؛ لا تحول APP_ENV للقاعدة المعتادة كي تتخطى الحارس.

خيار `--pretend` موجود في Laravel، لكن لا يمكن اعتباره dry-run كاملًا هنا: الترحيلات تقرأ metadata وتختبر الحراس وتعتمد على تسلسل DDL فعلي. لم يُنفَّذ pretend على قاعدة التطوير. استخدم قراءة المصدر ونسخة اختبار جديدة للمعاينة، ولا تعتمد إخراج pretend بوصفه ضمانًا لخلو التنفيذ من أثر أو اكتمال SQL.

NOT_EXECUTED — أمر التنفيذ فقط بعد backup مستعاد إلى هدف آخر، وschema/journal صحيحين، وموافقة المشغّل، ومطابقة شروط كل migration:

```powershell
php artisan migrate --force
```

شغّله في process ببيانات اعتماد migrator المؤقتة ثم أنهِه؛ لا تغير `.env` العادي. لا يُنفَّذ الأمر أعلاه تلقائيًا على `quality_platform`. امتيازات CREATE/ALTER/INDEX/REFERENCES/TRIGGER لازمة حسب المصدر. خطأ 1419 قد يبقى مع log_bin: يحتاج قرار DBA محدودًا وفق المنصة وصلاحيات إنشاء الكائنات؛ **لا SET GLOBAL** ولا تغيير `log_bin_trust_function_creators` ولا إزالة triggers. [Binary logging](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html)، [Laravel migrations](https://laravel.com/docs/13.x/migrations).

MySQL DDL ليس معاملة DML قابلة للرجوع كليًا. توجد preflight/compensation وموانع إسقاط تاريخ ممتلئ؛ لا تلغي نافذة crash بين DDL وسجل migrations. عند فشل: احتفظ بالخطأ وSHOW CREATE وjournal والقيود/الصفوف، وقارنها بالمصدر قبل أي إعادة محاولة. لا تضف سجل migration يدويًا ولا تتبنى جدولًا مجهولًا ولا تسقطه لتجاوز الخطأ. لا تستخدم fresh/refresh/db:wipe/rollback الأعمى لترقية قاعدة موجودة. راجع [عقد العزل](../architecture/tenant-isolation-schema-contract.md) و[قاموس القاعدة](../catalog/03-DATABASE-DICTIONARY-AR.md).

## G — النسخ والاستعادة

backup ينقل بيانات قائمة؛ migration يبني/يطوّر البنية. لا تتعامل مع fixture أو migration كنسخة من بيانات مؤسسة. أمثلة NOT_EXECUTED على قاعدة التطوير؛ المخوّل يثبت مصدرًا وهدفًا منفصلين، ويختار ملفًا جديدًا خاصًا غير موجود:

```powershell
$dumpExe = Read-Host 'Full path to matching mysqldump.exe'
$backupFile = Read-Host 'New private absolute backup path'
if (Test-Path -LiteralPath $backupFile) { throw 'Do not overwrite backup' }
& $dumpExe --host=127.0.0.1 --port=13306 --user=qep_backup --password --single-transaction --triggers --routines --events --no-tablespaces --set-gtid-purged=OFF '--default-character-set=utf8mb4' "--result-file=$backupFile" quality_platform_local_new
if ($LASTEXITCODE -ne 0) { throw 'Backup failed; preserve diagnostic file' }
Get-Item -LiteralPath $backupFile | Select-Object Length
Get-FileHash -LiteralPath $backupFile -Algorithm SHA256
```

المنفذ والقاعدة والحساب أمثلة؛ يجب تعديلها وفق الهوية المتحققة. `--password` يسأل تفاعليًا؛ لا تلحق السر به. لا تستخدم `>` في Windows PowerShell لملف SQL بسبب التحويل إلى UTF-16. privileges الخاصة backup وSHOW VIEW/TRIGGER وأحداث/routines يراجعها DBA؛ لا تفترض أن runtime يملكها. لا تفتح نافذة DDL أثناء `--single-transaction`. نجاح dump لا يثبت نجاح restore. [mysqldump 8.4](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

للاستعادة، أنشئ هدفًا جديدًا حصريًا بإذن DBA، افحص SQL خاصًا لوجود CREATE DATABASE/USE/schema-qualified/DEFINER، ولا تجعل `--database` يوهمك بأنه يمنع dump من استهداف غيره. بعد مراجعة الملف، افتح mysql تفاعليًا بالهدف الجديد ثم أمر `SOURCE` بمسار forward slash مقتبس ملائم؛ أو استخدم Import في phpMyAdmin بعد التأكد من اختيار الهدف وحدود PHP وحجم الملف. هذه كتابة **NOT_EXECUTED** هنا. تحقق من exit/errors وSHOW CREATE وجرد journal والصفوف والملفات الخاصة والبصمات؛ أعد `migrate:status` وراجع pending. احتفظ بالأصل والنسخة ولا تستبدل APP_KEY أو private storage بملفات عرض اصطناعي.

## H — البناء وفتح الصفحات

الأوامر التالية validated في CI للمصدر الحالي؛ تشغيل install/build محليًا يكتب vendor/assets، لذا يقتصر على نسخة المقصودة وليس نسخة عرض مجمدة:

```powershell
composer install --no-interaction --prefer-dist
composer validate --strict
composer check-platform-reqs
composer audit
npm ci
npm run build
```

لا composer update/npm update. نفذ optimize:clear فقط في نافذة local مفهومة الأثر؛ قد يمس cache المشترك إن أخطأت الهدف، لذا NOT_EXECUTED على البيئة العادية. `npm run dev` اختياري لتعديل الواجهة وله منفذ مستقل، لا يلزم مع build manifest. لا تنشر Vite أو PHP على كل العناوين.

| المسار | المعنى المتوقع بعد تشغيل البيئة |
|---|---|
| `/up` | 200 صحة HTTP، لا يثبت قبول جميع الأعمال |
| `/` | الصفحة الحالية حسب routes |
| `/admin/login` | قشرة local/testing للحساب legacy؛ ليست إدارة منصة إنتاجية |
| `/institution/login` | دخول المؤسسة مع المؤسسة والبريد وكلمة المرور؛ local/testing فقط |
| `/institution/account` | يحتاج institution session سليمة؛ رابط workspace مشروط فعليًا |
| `/institution/workspace` | Policy حقيقية؛ حساب بلا منح يُرفض |
| `/institution/mvp-workspace` | TEST_ONLY provider فقط؛ غير مسجل في التشغيل العادي |

راجع [قائمة المسارات الفعلية](../catalog/05-ROUTES-SCREENS-AND-JOURNEYS-AR.md). لا تنسخ test provider إلى bootstrap. لا توجد حاليًا URL عرض صالحة بعد التنظيف؛ العرض القادم يحتاج fixture جديدة صريحة.

## I — Redis والطوابير والتخزين

Compose اختياري ويملك مشاريع مسماة مختلفة. READ_ONLY: `docker compose -f compose.redis.yml ps` و`docker compose -f compose.mysql-local.yml ps`. NOT_EXECUTED في هذه المهمة: `docker compose -f compose.redis.yml up -d`؛ وMySQL يحتاج ملف أسرار محليًا معتمدًا و`--env-file` قبل up. لا تنشئ ملف أسرار نموذجي بقيمة ثابتة. `stop` يوقف الخدمة ويحفظ البيانات؛ لا `down -v` ولا prune.

PDO/session/cache/queue اختيار مستقل. Redis queue مضبوطة after_commit=true؛ in-app notifications محفوظة في معاملة MySQL ولا تحتاج worker خارجيًا. لا app/Jobs مخصص حاليًا؛ شغّل `queue:work redis` فقط لمسار طابور مطلوب فعليًا وبعد اختيار prefix/queue صحيحة. لا FLUSHALL/FLUSHDB أو queue:clear عامة. الملفات في storage خاص ونسخها غير قابلة للاستبدال؛ لا storage:link لتقديم الوثائق. logs خاصة تحت storage/logs وقد تحمل سياقًا حساسًا؛ لا تنشرها ولا تمسحها جماعيًا.

## J — تشخيص الأعطال

| العرض | الدليل المطلوب | الإجراء الآمن |
|---|---|---|
| 500/صفحة فارغة | log الخاص، PHP version/extensions، manifest | أصلح السبب في فرع؛ لا تكشف stack trace للعميل |
| connection refused | listener/host/port + هوية MySQL | شغّل الخدمة المقصودة بإذنها؛ لا تبدّل منفذًا عشوائيًا |
| 1142 | SHOW GRANTS للمشغّل | اعتمد أقل امتياز للـDDL المحدد؛ لا root runtime |
| 1419 | log_bin وTRIGGER ونسخة الخادم | DBA يراجع؛ لا إسقاط triggers أو تغيير عالمي |
| SQL CHECK/FK | SQLSTATE+القيد+الـjournal | تحقق من التعارض الحقيقي؛ لا FOREIGN_KEY_CHECKS=0 |
| 403/redirect | guard/session/tenant/assignment/expiry | الرفض قد يكون صحيحًا؛ لا منح Admin عامة |
| 419/Livewire upload | CSRF/session cookie/body limits/current grant | افصل خطأ upload عن session؛ حد التطبيق 30,000,000 byte لا يزيد |
| phpMyAdmin.test خاطئ | Herd site/link والخادم المختار | لا تخلط العميل والخادم؛ لا تغيّر إعداداته بلا ضرورة |
| PDF في Chrome/Edge canceled | IDM interception وhash isolated | المسار اليدوي غير مثبت؛ لا تعطل IDM أو الحماية |
| demo503/expired | proof/hash/وقت المنح | fail-closed متوقع؛ لا rebaseline أو تجديد تلقائي |

## K — الصيانة

تحقق من main وCI وworktrees، احفظ backup مثبتة، اقرأ changelog/pending migrations، حدّث ff-only على نسخة نظيفة، ثبت lock وابنِ، ثم اختبر SQLite/الهوية وMySQL8.4 وRedis في أهداف جديدة مملوكة. تتطلب MySQL opt-in `MYSQL_TESTS_ENABLED=1` والتحقق من guard في [tests/Integration/MySql](../../tests/Integration/MySql)؛ اسم quality_platform_ci وحده لا يثبت الملكية. لا توجه PHPUnit إلى DB عادية. مثال `php vendor/bin/phpunit --configuration phpunit.xml` ينفذ SQLite memory وفق config؛ MySQL يحتاج harness مخوّل، لا نقدم سرًا عامًا له.

الرجوع إلى نسخة كود سابقة لا يساوي rollback آمنًا للبيانات. أصلح roll-forward بتغيير مراجَع، ولا تعدّل migration مطبقة. العمل في Issue/branch/PR؛ main محمي ولا self-merge.

## L — قائمة قبول التشغيل

| الترتيب | نسخة جديدة | نسخة قائمة | جهة التنفيذ والنتيجة المطلوبة |
|---|---|---|---|
| 1 | تحقق origin/clean/PHP/Node | جرد العمل/APP_KEY/الخدمات دون كتابة | مهندس: PASS هوية صحيحة |
| 2 | هدف قاعدة جديد + runtime/migrator | backup وrestore تجريبي منفصل | DBA: PASS بصمة واستعادة مثبتة |
| 3 | راجع حراس كل migration | journal/pending/SHOW CREATE | DBA/Lead: لا unknown/partial |
| 4 | migrate فقط مع موافقة الهدف | لا ترحيل شامل مفترض | DBA: نتيجة موثقة أو BLOCKED |
| 5 | locked install/build | راجع أثر caches/منافذ | مهندس: Composer/Pint/npm PASS |
| 6 | Herd وروابطه ثم `/up` | الحفاظ على phpMyAdmin/sites | المشغّل: HTTP200 فعلي |
| 7 | legacy shell أو fixture مستقلة | لا bootstrap مؤسسي خام | مهندس مخوّل: grant ضيقة، cross-tenant denied |
| 8 | سجل الاختبار والقيود | لا حذف أدلة/بيانات | Lead: PR + CI + مراجعة مستقلة |

الوضع المثبت هنا: CLI/lock/Pint/schema/Tinker والاختبار الأمني PASS؛ HTTP Herd/phpMyAdmin **UNAVAILABLE**، وكتابات setup/backup/restore للبيئة العادية **NOT_EXECUTED**. يستطيع المطور اتباع الدليل، لكن لا يُوصف الجهاز بأنه جاهز للعرض الآن.
