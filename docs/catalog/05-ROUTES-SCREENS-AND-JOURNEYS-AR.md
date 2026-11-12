# المسارات والشاشات والرحلات

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.


جُرّب route:list فعليًا في عمليتين منفصلتين testing/production باستخدام SQLite بالذاكرة وAPP_KEY مؤقت وcache/session array، دون قراءة DB التطوير. قائمة المسارات لا تكفي لإثبات الوصول؛ لوحة institution تبقى محمية بوسطاء البيئة والهوية والتفويض حتى لو ظهر تعريفها في route:list للإنتاج.

| البيئة | المسارات المجمعة | الملاحظة |
|---|---:|---|
| testing | 30 | الدخول المرشح والمعاينات متاحان فقط بحدودهما |
| production | 22 | غياب مسارات دخول المؤسسة؛ تعريف لوحة institution يرفض عبر Environment middleware |

## المسارات الفعلية في التطبيق العادي testing

| Method | URI | الاسم | Middleware |
|---|---|---|---|
| GET|HEAD | `/` | `` | `web` |
| GET|HEAD | `admin` | `filament.admin.pages.dashboard` | `Filament\Http\Middleware\SetUpPanel:admin`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Filament\Http\Middleware\Authenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `Illuminate\Routing\Middleware\SubstituteBindings`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `admin/login` | `filament.admin.auth.login` | `Filament\Http\Middleware\SetUpPanel:admin`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `Illuminate\Routing\Middleware\SubstituteBindings`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| POST | `admin/logout` | `filament.admin.auth.logout` | `Filament\Http\Middleware\SetUpPanel:admin`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Filament\Http\Middleware\Authenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `Illuminate\Routing\Middleware\SubstituteBindings`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `admin/ui-preview` | `filament.admin.pages.ui-preview` | `Filament\Http\Middleware\SetUpPanel:admin`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Filament\Http\Middleware\Authenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `Illuminate\Routing\Middleware\SubstituteBindings`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `filament/exports/{export}/download` | `filament.exports.download` | `filament.actions` |
| GET|HEAD | `filament/imports/{import}/failed-rows/download` | `filament.imports.failed-rows.download` | `filament.actions` |
| GET|HEAD | `institution/login` | `institution.login` | `web`, `App\Http\Middleware\InstitutionSession:public` |
| POST | `institution/login` | `` | `web`, `App\Http\Middleware\InstitutionSession:public` |
| POST | `institution/recovery` | `institution.recovery` | `web`, `App\Http\Middleware\InstitutionSession:public` |
| GET|HEAD | `institution/workspace` | `filament.institution.pages.institution-dashboard` | `Filament\Http\Middleware\SetUpPanel:institution`, `App\Filament\Institution\InstitutionPanelEnvironment`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `App\Filament\Institution\InstitutionAuthenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Routing\Middleware\SubstituteBindings`, `App\Filament\Institution\InstitutionPanelSession`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `institution/workspace/approaches` | `filament.institution.resources.approaches.index` | `Filament\Http\Middleware\SetUpPanel:institution`, `App\Filament\Institution\InstitutionPanelEnvironment`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `App\Filament\Institution\InstitutionAuthenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Routing\Middleware\SubstituteBindings`, `App\Filament\Institution\InstitutionPanelSession`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `institution/workspace/approaches/{approach}` | `filament.institution.resources.approaches.workspace` | `Filament\Http\Middleware\SetUpPanel:institution`, `App\Filament\Institution\InstitutionPanelEnvironment`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `App\Filament\Institution\InstitutionAuthenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Routing\Middleware\SubstituteBindings`, `App\Filament\Institution\InstitutionPanelSession`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| POST | `institution/workspace/logout` | `filament.institution.auth.logout` | `Filament\Http\Middleware\SetUpPanel:institution`, `App\Filament\Institution\InstitutionPanelEnvironment`, `Illuminate\Cookie\Middleware\EncryptCookies`, `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`, `Illuminate\Session\Middleware\StartSession`, `Illuminate\View\Middleware\ShareErrorsFromSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`, `App\Filament\Institution\InstitutionAuthenticate`, `Filament\Http\Middleware\AuthenticateSession`, `Illuminate\Routing\Middleware\SubstituteBindings`, `App\Filament\Institution\InstitutionPanelSession`, `Filament\Http\Middleware\DisableBladeIconComponents`, `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| GET|HEAD | `institution/{organization}/account` | `institution.account` | `web`, `App\Http\Middleware\InstitutionSession` |
| POST | `institution/{organization}/logout` | `institution.logout` | `web`, `App\Http\Middleware\InstitutionSession` |
| GET|HEAD | `institution/{organization}/reset/{account}/{token}` | `institution.reset` | `web`, `App\Http\Middleware\InstitutionSession:public` |
| POST | `institution/{organization}/reset/{account}/{token}` | `` | `web`, `App\Http\Middleware\InstitutionSession:public` |
| GET|HEAD | `livewire-8b9134dd/css/{component}.css` | `` |  |
| GET|HEAD | `livewire-8b9134dd/css/{component}.global.css` | `` |  |
| GET|HEAD | `livewire-8b9134dd/js/{component}.js` | `` |  |
| GET|HEAD | `livewire-8b9134dd/livewire.csp.min.js.map` | `` |  |
| GET|HEAD | `livewire-8b9134dd/livewire.min.js` | `` |  |
| GET|HEAD | `livewire-8b9134dd/livewire.min.js.map` | `` |  |
| GET|HEAD | `livewire-8b9134dd/preview-file/{filename}` | `livewire.preview-file` | `web` |
| POST | `livewire-8b9134dd/update` | `default-livewire.update` | `web`, `Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders` |
| POST | `livewire-8b9134dd/upload-file` | `livewire.upload-file` | `web`, `Illuminate\Routing\Middleware\ThrottleRequests:60,1` |
| GET|HEAD | `storage/{path}` | `storage.local` |  |
| PUT | `storage/{path}` | `storage.local.upload` |  |
| GET|HEAD | `up` | `` |  |

## الشاشات الإضافية في harness فقط

`tests/Support/ReviewMaturityPanelProvider.php` يسجل mvp_workflow_fixture في `/institution/mvp-workspace` فقط بالتطبيق testing المملوك؛ Institution login الحقيقي وPolicy حقيقية، لا allow-all. يجمع OrganizationWorkspace وبنوك Approach/Document/ReviewMaturity وWorkflowNotifications. رابط التنزيل `/institution/local-document-versions/{document}/{version}/download` لا يُسجَّل عبر routes/web.php العادي. لا تنسخ provider الاختبار إلى bootstrap.

## الرحلات وحالة الأدلة

| الرحلة | الرحلة المثبتة أو الحد | النتيجة |
|---|---|---|
| J01 | حساب مؤسسي مستقل ودخول/استعادة/خروج وعزل الجلسة | PARTIAL؛ قبول bounded تقني، لا bootstrap كامل |
| J02 | مؤسسة/وحدات tree/create/rename/move ومنع دورة | IMPLEMENTED_TEST_ONLY؛ كود الزميل محفوظ |
| J03 | منهجية ومورد مسؤول وإسنادات صريحة وmetadata/draft/history | IMPLEMENTED_TEST_ONLY؛ انتقال/نشر عام غير مقبول |
| J04 | رفع/نسخ/إتاحة مستقلة/تنزيل عينة حقيقية | IMPLEMENTED_TEST_ONLY؛ لا scanner إنتاجي أو ملفات مجهولة |
| J05 | فصل قرار الوثيقة عن قبول الشاهد؛ exact version وكفاية مستقلة مثبتان | PARTIAL TEST_ONLY؛ الاعتماد المؤسسي العام غير منفذ |
| J06 | إرسال/تصحيح/اعتماد محتوى مستقل؛ النشر العام منفصل | PARTIAL TEST_ONLY؛ لا قبول لرحلة النشر التشغيلية |
| J07 | إشعارات داخل التطبيق وread/unread | IMPLEMENTED_TEST_ONLY؛ لا قنوات خارجية |
| J08 | خمس مراحل بأسئلة وسلم ولقطات فعلية | IMPLEMENTED_TEST_ONLY |
| J09 | إعادة تقييم تاريخية وانخفاض/تساو/ارتفاع وتتابع لاحق | IMPLEMENTED_TEST_ONLY |
| J10 | ربط مراجع مرخصة وعلاقات معتمدة | NOT_IMPLEMENTED / GATED_OWNER_DECISION |
| J11 | منع اعتماد الذات والعزل والمعرفات المصطنعة | ضمانات سالبة مثبتة، ليست إدارة صلاحيات تشغيلية |
| J12 | سحب/عضوية/كلمة مرور وتزامن current reads | مثبت للكتّاب المدعومين، لا ضمان SQL عام |
| J13 | دورة حياة المؤسسة وانتقالاتها المعتمدة | GATED_OWNER_DECISION؛ D023 |
| J14 | رحلة المالك الشاملة وبيئة إطلاق معتمدة | PARTIAL TEST_ONLY؛ قبول بشري كامل غير حاصل |

تسمية J13/J14 وتغطية كامل نطاقهما تُقرأ من [السجل الأصلي](../engineering/delivery/JOURNEYS.md)؛ الجدول يلخص الأدلة ولا يعيد تعريف متطلباتهما. السجل المعتمد للـ105 FR/AC هو [ACCEPTANCE](../engineering/delivery/ACCEPTANCE.md). L1 مصدر/بنية، L2 اختبارات، L3 رحلة حقيقية بالمتصفح والبيانات، L4 قبول مالك مستقل؛ لا يصعد نجاح جزئي إلى المستوى التالي تلقائيًا.

أخطاء متوقعة: غير المسجل يعاد للدخول، العضوية/الإسناد المسحوب أو مؤسسة أخرى يرفض، ULID غير صالح لا يكشف موردًا، stale write يعيد تعارضًا، والدورة تعطي رسالة عربية بلا تغيير أو success audit. بروفات Closure محفوظة في التقرير الهندسي؛ العروض القديمة تقاعدت ولا يُفترض بقاء روابطها عاملة.
