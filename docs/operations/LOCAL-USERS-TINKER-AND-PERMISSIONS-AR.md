# دليل المستخدمين المحليين وTinker والصلاحيات

> مراجعة 12 نوفمبر 2026 · المصدر `main@03c829fde71bbb11754ef657bf2edfe50253068e` · Issue #71. أوامر الكتابة غير المنفذة موسومة صراحة. لا تغييرات على `.env` أو بيانات التطوير.


## ما الذي يمكن إنشاؤه فعلًا

راجع [User](../../app/Models/User.php)، [auth](../../config/auth.php)، [registry](../../config/authorization.php)، [TenantAuthorization](../../app/Authorization/TenantAuthorization.php)، [RoleManagement](../../app/Authorization/RoleManagement.php). مثال إنشاء legacy أدناه تحقق على SQLite memory جديدة؛ لا يمنح دخول مؤسسة ولا محتواها. لا توجد حاليًا Action تشغيلية آمنة مكتملة لإنشاء موظف مؤسسي وعضويته ومنحه الصلاحيات من Tinker. **OPERATIONAL ACCOUNT BOOTSTRAP NOT YET IMPLEMENTED / OWNER-GATED**. لا تعالج هذا النقص بـforceFill أو SQL أو تعطيل Policy.

## READ_ONLY — فحص آمن

ابدأ `php artisan tinker` في النسخة المقصودة فقط. لا تطبع config كاملًا أو نماذج الأسرار. لا تقبل organization_id من طلب متصفح باعتباره تفويضًا.

```php
app()->version();
app()->environment();
PHP_VERSION;
[config('database.default'), config('database.connections.'.config('database.default').'.host'), config('database.connections.'.config('database.default').'.port'), DB::connection()->getDatabaseName()];
[config('auth.defaults.guard'), config('auth.guards.web.provider'), config('auth.guards.institution.provider')];
config('authorization.capabilities');
App\Models\User::query()->select('principal_type')->selectRaw('COUNT(*) AS total')->groupBy('principal_type')->get()->toArray();
App\Models\Organization::query()->count();
App\Models\OrganizationMembership::query()->count();
DB::table('roles')->select('organization_id','name','guard_name')->limit(20)->get()->toArray();
DB::table('permissions')->select('name','guard_name')->limit(20)->get()->toArray();
getPermissionsTeamId();
```

هذه قراءة مقصودة لمن يملك صلاحية console وبيانات القاعدة؛ النتائج التفصيلية خاصة. اختبرنا فعليًا version/env/guard ومصفوفة capabilities في CLI؛ فحص الأعداد والأدوار راجعناه ضد المخطط دون قراءة قائمة مستخدمي التطوير. لا تنشر أسماء/ULIDs حقيقية؛ في fixture اصطناعية يمكن select public_id/name المطلوبين فقط. المصفوفة العادية حاليًا `[]`. Teams scope حالة process؛ تعيين team ID لا يثبت TenantContext ولا عضوية أو قدرة. لا تترك Tinker طويلًا مع scope مؤسسي متغير.

## ISOLATED_TEST_ONLY_MUTATION — legacy shell

المثبت آليًا: 52 migration إلى SQLite `:memory:` جديدة، مستخدم عشوائي واحد، تحقق hash/provider/legacy/admin-testing/no-institution/no-membership/no-role/no-capability/hidden-password، ثم حذف **ذلك السجل وحده**؛ PASS. الأمثلة التالية مطابقة لـFillable/hashed cast في المصدر. تنفيذها على قاعدة تطوير دائمة يحتاج موافقة منفصلة لصاحبها؛ ليس جزءًا من هذه المهمة.

في process اختبار جديد فقط اضبط APP_ENV=testing وDB_CONNECTION=sqlite وDB_DATABASE=:memory: وDB_URL فارغة وCACHE_STORE=array وSESSION_DRIVER=array وQUEUE_CONNECTION=sync؛ لا تغير `.env`. الذاكرة خاصة بprocess Tinker ولا يمكن استخدامها لتسجيل دخول عبر HTTP منفصل. ابدأ Tinker ثم:

```php
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') { throw new RuntimeException('Disposable memory target required'); }
if (Artisan::call('migrate', ['--force' => true]) !== 0) { throw new RuntimeException('Migration failed; do not create user'); }
$secret = \Laravel\Prompts\password('كلمة مرور اصطناعية محلية لا تكتبها في سجل الأوامر');
if (strlen($secret) < 16) { throw new RuntimeException('Use a long unique local secret'); }
$email = 'shell-'.bin2hex(random_bytes(8)).'@example.test';
$localUser = App\Models\User::create(['name' => 'Local shell sample', 'email' => $email, 'password' => $secret]);
$newId = $localUser->getKey();
[Hash::check($secret, $localUser->getAuthPassword()), $localUser->principal_type, $localUser->memberships()->count(), $localUser->roles()->count()];
unset($secret);
```

المتوقع `[true, 'legacy', 0, 0]`. لا تطبع password/hash ولا تستخدم كلمة حرفية في سجل Tinker. اخفِ نتيجة إنشاء النموذج باستخدام متغير؛ النموذج يخفي password/remember_token افتراضيًا، ولا تعتمد ذلك وحده لمنع تسجيل الأسرار. تحققت أداة Prompts من المصدر؛ إن كانت console لا تدعم الإخفاء فلا تدخل السر هناك. استخدم جلسة تفاعلية آمنة لا سجلًا مشتركًا.

المثال ومسار تحقق المهمة يرفضان فشل migration قبل إنشاء المستخدم. لا تستخدم نجاح إنشاء جدول واحد دليلًا على اكتمال schema.

في قاعدة محلية فارغة مخصصة ومأذونة فقط، الحساب legacy يمكنه دخول `/admin/login` عبر web/provider users عندما APP_ENV local/testing؛ الوصول إلى القشرة التطويرية لا يمنح بيانات مؤسسة. حساب الذاكرة أعلاه لا يتيح HTTP. لا يوجد اسم دور Admin سحري، ولا يُقبل حساب مؤسسة عبر legacy provider. الإنتاج يرفض القشرة حتى لو صحت كلمة المرور.

## NOT_SUPPORTED — الحساب والعضوية المؤسسيان

User::create لا يقبل mass-assignment لـprincipal_type/organization_id/public_id؛ الربط ثابت عند التحديث. تتطلب حسابات institution بريد ASCII مطبعًا وULID وربط مؤسسة وعضوية نشطة وفق schema. OrganizationMembership محمي. OrganizationProvisioning وRoleManagement عمليات داخلية؛ وجودهما ليس اعتمادًا لجهة إصدار منح. لا نعرض وصفة ترقية legacy أو كتابة pivot أو إسناد عضوية قسريًا.

مصدر [InstitutionEntry](../../app/Actions/Identity/InstitutionEntry.php) و[InstitutionRecovery](../../app/Actions/Identity/InstitutionRecovery.php) يدعم الدخول والاستعادة للحساب الموجود، لا onboarding كاملًا. السيناريوهات الاصطناعية المعتمدة تستعمل helpers في tests/Support ومنحًا محددة بالموارد و24 ساعة وعدم تفويض، مع إثبات fixture مرتبط بالطلب. لا تنسخها لـapp/providers أو runtime. بعد تقاعد العروض15 لا توجد حسابات عرض يجب الوعد باستمرارها؛ إعادة العرض تتطلب fixture جديدة مخوّلة.

## الأدوار والمنح والسحب

Spatie Teams يستخدم organization_id؛ الدور مرتبط بمؤسسة/guard. TenantAuthorization يفحص العضوية والقدرة المسجلة والنطاق. RoleManagement الداخلي يحفظ التدقيق ويستخدم OrganizationX ثم UsersX ثم MembershipsX ثم الأقفال التابعة، ولا يوفر واجهة إدارة عامة مخولة. Assignments الخاصة بالوثيقة/المنهجية/مرحلة التقييم لها شروطها المستقلة ووقت الصلاحية. لا `assignRole('Admin')` ولا `syncPermissions()` ولا تعديل `model_has_roles` الخام يعادل تفويضًا خلفيًا صحيحًا. لا نسخ grants test-only إلى مؤسسة حقيقية.

## الاستعادة والجلسات

للمؤسسة استخدم `/institution/forgot-password` ثم الرابط الموقّع وtoken الصحيحين ضمن المؤسسة، لا رابطًا منشورًا أو token مسجلًا. تغيير كلمة المرور في مسار الاستعادة يدور remember_token؛ الحراس يتحققون من بصمة كلمة المرور وربط المؤسسة، وسحب/انتهاء assignment يُراجع عند العملية الحالية. logout عبر POST وCSRF، وليس GET. لا توجد دعوى إبطال عالمي للكتّاب الخارجيين أو جميع الجلسات بكل guard من هذا المثال. لا يغيّر هذا الدليل كلمات المرور الفعلية.

## التحقق والاسترجاع

لـfixture جديدة مأذونة اختبر institution guard وعضوية نشطة، scope صحيح، رفض المورد غير المسند والمؤسسة الأخرى، سحب الصلاحية أثناء الجلسة وانتهاؤها، غياب اعتماد الذات، CSRF/logout والإنتاج المرفوض. المصدر: [اختبارات الهوية](../../tests/Feature/InstitutionEntryTest.php) و[اختبارات MySQL](../../tests/Integration/MySql). لا تعرض أسرار الدخول في PR.

استرجاع مثال SQLite أعلاه، داخل نفس process وفقط بعد إثبات أنه legacy عشوائي جديد بلا علاقات:

```php
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== ':memory:') { throw new RuntimeException('Wrong target'); }
if ($localUser->principal_type !== 'legacy' || $localUser->getKey() !== $newId || $localUser->memberships()->exists() || $localUser->roles()->exists()) { throw new RuntimeException('Preserve unexpected relationships'); }
$localUser->delete();
App\Models\User::find($newId) === null;
```

المتوقع true؛ الخروج ينهي قاعدة الذاكرة. لا تستخدم هذا لحذف مؤلف له تاريخ أو سجلات تدقيق. لا حذف جماعي أو مؤسسة لتجاوز FK. إذا لا توجد آلية تشغيلية للسحب المطلوب، وثّق النقص واعتمد إجراءً محددًا بدل اختراعها. [مصفوفة الحسابات](../catalog/04-IDENTITY-ACCOUNTS-AND-PERMISSIONS-AR.md).
