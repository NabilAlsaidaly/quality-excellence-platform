
<x-filament-panels::page>
    @php
        /*
        |--------------------------------------------------------------------------
        | Preview catalog presentation
        |--------------------------------------------------------------------------
        | All screen metadata and view names come from the static ScreenCatalog.
        | No view path is generated from request input.
        */

        $selectedGroupLabel = null;
        $screenTabs = [];

        if ($selectedScreen !== null) {
            foreach ($groups as $groupKey => $group) {
                if ($groupKey === $selectedScreen['group']) {
                    $selectedGroupLabel = $group['label'];

                    $screenTabs = array_map(
                        static fn (array $screen): array => [
                            'key' => $screen['slug'],
                            'label' => $screen['title'],
                        ],
                        $group['screens']
                    );

                    break;
                }
            }
        }

        // shared.states is a preview entry, not an independent screen.
        $screenCount = count(array_filter(
            $screens,
            static fn (array $screen): bool =>
                $screen['slug'] !== 'shared.states'
        ));

        $groupCount = count($groups);

        $pageTitle = $selectedScreen !== null
            ? $selectedScreen['title']
            : 'معرض الواجهات الإدارية';

        $pageDescription = $selectedScreen !== null
            ? 'نموذج تصميمي للشاشة ' . $selectedScreen['title']
                . ' ضمن مجموعة ' . ($selectedGroupLabel ?? 'المعاينة')
                . '، دون اتصال ببيانات أو عمليات فعلية.'
            : 'استعراض الواجهات الإدارية المقترحة للمنصة والتنقل بين مجموعاتها وشاشاتها التصميمية.';

        $breadcrumbs = ['الرئيسية', 'معرض الواجهات'];

        if ($selectedScreen !== null) {
            if ($selectedGroupLabel !== null) {
                $breadcrumbs[] = $selectedGroupLabel;
            }

            $breadcrumbs[] = $selectedScreen['title'];
        }
    @endphp

    <div dir="rtl" class="space-y-6">

        {{-- ==========================================================
             Platform identity
        =========================================================== --}}
        <header class="flex flex-col gap-2 border-b border-gray-200 pb-5 dark:border-gray-700">
            <p class="text-lg font-bold tracking-tight text-gray-950 dark:text-white sm:text-xl">
                منصة الجودة والتميّز المؤسسي
            </p>

            <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">
                مساحة استعراض التصاميم الأولية للواجهات الإدارية وتجربة الاستخدام.
            </p>
        </header>

        {{-- ==========================================================
             Permanent preview notice
             Must remain visible and cannot be dismissed.
        =========================================================== --}}
        <div
            role="status"
            class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <x-filament::icon
                icon="heroicon-o-information-circle"
                class="mt-0.5 size-5 shrink-0"
                aria-hidden="true"
            />

            <div class="min-w-0 space-y-1">
                <p class="font-semibold">
                    معاينة تصميمية — لا توجد بيانات أو وظائف مفعّلة
                </p>

                <p class="text-sm leading-6 opacity-80">
                    جميع العناصر المعروضة هنا نماذج بصرية لأغراض مراجعة التصميم
                    وتقييم تجربة الاستخدام فقط، ولا تمثل عمليات تشغيلية فعلية.
                </p>
            </div>
        </div>

        {{-- ==========================================================
             Main preview layout
             In RTL, the first column is the right-hand navigation.
        =========================================================== --}}
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start">

            {{-- ======================================================
                 Right sidebar: preview group navigation
            ======================================================= --}}
            <aside
                class="w-full shrink-0 space-y-4 lg:sticky lg:top-6 lg:w-64 xl:w-72"
                aria-label="التنقل في معرض المعاينة"
            >
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">

                    <div class="mb-4 space-y-1">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                            مجموعات الواجهات
                        </h2>

                        <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                            اختر المجموعة لاستعراض الشاشات التابعة لها.
                        </p>
                    </div>

                    <nav aria-label="مجموعات المعاينة">
                        <ul class="space-y-1.5">
                            @foreach ($groups as $groupKey => $group)
                                @php
                                    $isActiveGroup = $selectedScreen !== null
                                        && $selectedScreen['group'] === $groupKey;

                                    $firstScreenSlug = $group['screens'][0]['slug'] ?? null;
                                @endphp

                                <li>
                                    @if ($selectedScreen !== null && $firstScreenSlug !== null)
                                        <a
                                            href="{{ request()->fullUrlWithQuery([
                                                'screen' => $firstScreenSlug,
                                            ]) }}"
                                            @if ($isActiveGroup)
                                                aria-current="location"
                                            @endif
                                            @class([
                                                'flex min-h-11 items-center justify-between gap-3 rounded-lg border px-3 py-2.5 transition-colors',
                                                'border-primary-200 bg-primary-50 text-primary-800 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-200' => $isActiveGroup,
                                                'border-transparent text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800' => !$isActiveGroup,
                                            ])
                                        >
                                    @else
                                        <a
                                            href="#group-{{ $groupKey }}"
                                            class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-transparent px-3 py-2.5 text-gray-700 transition-colors hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-gray-300 dark:hover:bg-gray-800"
                                        >
                                    @endif

                                        <span class="min-w-0 text-sm font-medium">
                                            {{ $group['label'] }}
                                        </span>

                                        <span
                                            class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs tabular-nums text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                            aria-label="{{ count($group['screens']) }} مدخلات معاينة"
                                        >
                                            {{ count($group['screens']) }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                </div>

                {{-- Preview scope information --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/60">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-eye"
                            class="size-4 text-gray-500 dark:text-gray-400"
                            aria-hidden="true"
                        />

                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                            نطاق المعاينة
                        </h3>
                    </div>

                    <p class="mt-2 text-xs leading-6 text-gray-600 dark:text-gray-400">
                        هذه الصفحة مخصصة لاستعراض التصاميم فقط. لا توجد بيانات مؤسسية
                        أو صلاحيات أو عمليات حفظ أو رفع أو اعتماد مفعّلة.
                    </p>
                </div>
            </aside>

            {{-- ======================================================
                 Main preview content
            ======================================================= --}}
            <main class="min-w-0 flex-1 space-y-6">

                {{-- Page heading and breadcrumbs --}}
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <x-quality-ui.page-header
                            :title="$pageTitle"
                            :description="$pageDescription"
                            :crumbs="$breadcrumbs"
                        />
                    </div>

                    @if ($selectedScreen === null)
                        <div class="grid grid-cols-2 gap-3 sm:w-auto">
                            <div class="min-w-28 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                                <p class="text-2xl font-bold tabular-nums text-primary-700 dark:text-primary-400">
                                    {{ $screenCount }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    شاشة تصميمية
                                </p>
                            </div>

                            <div class="min-w-28 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                                <p class="text-2xl font-bold tabular-nums text-primary-700 dark:text-primary-400">
                                    {{ $groupCount }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    مجموعات
                                </p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ==================================================
                     Selected screen
                =================================================== --}}
                @if ($selectedScreen !== null)

                    <x-quality-ui.tabs
                        :tabs="$screenTabs"
                        :active="$selectedScreen['slug']"
                    >
                        @if ($selectedScreen['slug'] === 'shared.states')

                            {{-- ==================================================
                                 Shared states showcase
                                 Internal section of this gallery only.
                            =================================================== --}}
                            <div class="space-y-6">

                                <x-quality-ui.section-card
                                    title="معرض الحالات المشتركة"
                                    description="نماذج موحدة للحالات البصرية التي يمكن إعادة استخدامها في شاشات المنصة. جميع الأمثلة التالية توضيحية فقط."
                                >
                                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 2xl:grid-cols-3">

                                        {{-- ======================================
                                             1. Empty state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="01 — الحالة الفارغة"
                                            description="عندما لا توجد عناصر أو بيانات لعرضها."
                                        >
                                            <div class="space-y-4">
                                                <x-quality-ui.empty-state
                                                    title="لا توجد بيانات للعرض"
                                                    description="هذه حالة توضيحية ضمن معاينة التصميم، ولا توجد بيانات فعلية أو وظائف مفعّلة."
                                                />

                                                <x-quality-ui.table-shell
                                                    :columns="[
                                                        'العنصر',
                                                        'الوصف',
                                                        'الحالة',
                                                    ]"
                                                    caption="نموذج جدول فارغ"
                                                />
                                            </div>
                                        </x-quality-ui.section-card>

                                        {{-- ======================================
                                             2. Loading state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="02 — حالة التحميل"
                                            description="تمثيل بصري لمحتوى قيد التحميل دون تنفيذ طلب بيانات فعلي."
                                        >
                                            <div
                                                role="status"
                                                aria-label="نموذج حالة تحميل توضيحي"
                                                class="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50"
                                            >
                                                <span class="sr-only">
                                                    نموذج تحميل توضيحي فقط، لا يجري تحميل بيانات فعلية.
                                                </span>

                                                <div
                                                    class="space-y-3 motion-reduce:animate-none"
                                                    aria-hidden="true"
                                                >
                                                    <div class="h-4 w-2/3 animate-pulse rounded-md bg-gray-200 dark:bg-gray-700"></div>

                                                    <div class="h-3 w-full animate-pulse rounded-md bg-gray-200 dark:bg-gray-700"></div>

                                                    <div class="h-3 w-5/6 animate-pulse rounded-md bg-gray-200 dark:bg-gray-700"></div>

                                                    <div class="h-20 w-full animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>
                                                </div>

                                                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                    نموذج هيكلي للتحميل. لا توجد عملية تحميل أو معالجة بيانات جارية.
                                                </p>
                                            </div>
                                        </x-quality-ui.section-card>

                                        {{-- ======================================
                                             3. Error state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="03 — حالة الخطأ"
                                            description="رسالة عربية آمنة لا تكشف تفاصيل تقنية أو بيانات داخلية."
                                        >
                                            <div
                                                role="alert"
                                                class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
                                            >
                                                <x-filament::icon
                                                    icon="heroicon-o-exclamation-triangle"
                                                    class="mt-0.5 size-5 shrink-0"
                                                    aria-hidden="true"
                                                />

                                                <div class="min-w-0 space-y-2">
                                                    <p class="text-sm font-semibold">
                                                        تعذر عرض المحتوى
                                                    </p>

                                                    <p class="text-sm leading-6">
                                                        حصل خطأ، يرجى المحاولة لاحقًا.
                                                    </p>

                                                    <p class="text-xs leading-5 opacity-80">
                                                        هذه رسالة عرض توضيحية وليست نتيجة خطأ فعلي في النظام.
                                                    </p>
                                                </div>
                                            </div>
                                        </x-quality-ui.section-card>

                                        {{-- ======================================
                                             4. Disabled state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="04 — حالة التعطيل"
                                            description="الحقول وعناصر الإجراءات ظاهرة للمراجعة البصرية لكنها غير مفعّلة."
                                        >
                                            <div class="space-y-4">
                                                <x-quality-ui.filter-toolbar
                                                    :filters="[
                                                        [
                                                            'label' => 'البحث',
                                                            'placeholder' => 'البحث غير متاح في المعاينة',
                                                        ],
                                                        [
                                                            'label' => 'حالة العرض',
                                                            'placeholder' => 'عامل تصفية توضيحي معطل',
                                                        ],
                                                    ]"
                                                />

                                                <x-quality-ui.form-field-shell
                                                    label="اسم العنصر"
                                                    hint="حقل توضيحي معطل، لا يستقبل إدخالًا أو يحفظ بيانات."
                                                    kind="input"
                                                />

                                                <x-quality-ui.form-field-shell
                                                    label="الوصف"
                                                    hint="مساحة نصية توضيحية."
                                                    kind="textarea"
                                                />

                                                <x-quality-ui.form-field-shell
                                                    label="نوع العنصر"
                                                    hint="قائمة اختيار توضيحية غير مفعّلة."
                                                    kind="select"
                                                />

                                                <button
                                                    type="button"
                                                    disabled
                                                    aria-disabled="true"
                                                    class="inline-flex min-h-11 cursor-not-allowed items-center justify-center rounded-lg border border-gray-200 bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500"
                                                >
                                                    إجراء غير متاح في المعاينة
                                                </button>
                                            </div>
                                        </x-quality-ui.section-card>

                                        {{-- ======================================
                                             5. Locked state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="05 — حالة القفل"
                                            description="توضيح بصري لعنصر غير متاح في هذه المرحلة، دون تمثيل قرار صلاحيات فعلي."
                                        >
                                            <div class="space-y-4">
                                                <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                        <x-filament::icon
                                                            icon="heroicon-o-lock-closed"
                                                            class="size-5"
                                                            aria-hidden="true"
                                                        />
                                                    </div>

                                                    <div class="min-w-0 space-y-1">
                                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                                                            محتوى غير متاح
                                                        </p>

                                                        <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                            نموذج مقفل لأغراض عرض حالة الواجهة.
                                                        </p>
                                                    </div>

                                                    <div class="mr-auto">
                                                        <x-quality-ui.status-placeholder
                                                            label="مقفل للمعاينة"
                                                            tone="neutral"
                                                        />
                                                    </div>
                                                </div>

                                                <x-quality-ui.info-alert
                                                    variant="warning"
                                                    message="العناصر المقفلة هنا أمثلة تصميمية، ولا تمثل صلاحيات مستخدم أو قرار وصول حقيقي."
                                                />
                                            </div>
                                        </x-quality-ui.section-card>

                                        {{-- ======================================
                                             6. RTL state
                                        ======================================= --}}
                                        <x-quality-ui.section-card
                                            title="06 — اتجاه RTL"
                                            description="التحقق من المحاذاة العربية وظهور المصطلحات التقنية المختلطة بصورة مقروءة."
                                        >
                                            <div class="space-y-4">
                                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                                        واجهة عربية من اليمين إلى اليسار
                                                    </p>

                                                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">
                                                        يجب أن تبقى العناوين والحقول والقوائم متناسقة عند الجمع بين النص العربي والمصطلحات التقنية.
                                                    </p>

                                                    <dl class="mt-4 space-y-4">
                                                        <x-quality-ui.detail-pair
                                                            label="اتجاه المحتوى"
                                                            value="من اليمين إلى اليسار"
                                                        />

                                                        <x-quality-ui.detail-pair
                                                            label="مصطلح تقني"
                                                            value="Laravel 13 / Filament 5"
                                                        />
                                                    </dl>
                                                </div>

                                                <x-quality-ui.stage-strip />
                                            </div>
                                        </x-quality-ui.section-card>
                                    </div>
                                </x-quality-ui.section-card>

                                {{-- ==================================================
                                     Shared component previews:
                                     document version and review history
                                =================================================== --}}
                                <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">

                                    <x-quality-ui.section-card
                                        title="معاينة سجل إصدارات الوثائق"
                                        description="هيكل زمني يعرض حالة عدم توفر إصدارات في هذه المعاينة."
                                    >
                                        <x-quality-ui.version-timeline
                                            title="سجل الإصدارات"
                                            :items="[]"
                                        />
                                    </x-quality-ui.section-card>

                                    <x-quality-ui.section-card
                                        title="معاينة سجل المراجعات"
                                        description="هيكل سجل المراجعة بعناوينه الأساسية دون قرارات أو سجلات فعلية."
                                    >
                                        <x-quality-ui.review-history
                                            :items="[]"
                                        />
                                    </x-quality-ui.section-card>

                                </div>
                            </div>

                        @elseif (view()->exists($selectedScreen['view']))

                            {{-- View names are resolved exclusively through ScreenCatalog. --}}
                            @include($selectedScreen['view'])

                        @else

                            <x-quality-ui.empty-state
                                title="قالب الشاشة غير متوفر"
                                description="لم يُعثر على ملف القالب المحدد في كتالوج المعاينة. يجب استكمال ملف الشاشة قبل اعتماد المعرض."
                            />

                        @endif
                    </x-quality-ui.tabs>

                    {{-- Return to the main catalog --}}
                    <div class="border-t border-gray-200 pt-5 dark:border-gray-700">
                        <a
                            href="{{ request()->url() }}"
                            class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            <x-filament::icon
                                icon="heroicon-o-arrow-right"
                                class="size-4"
                                aria-hidden="true"
                            />

                            العودة إلى معرض الشاشات
                        </a>
                    </div>

                {{-- ==================================================
                     Main screen catalog
                =================================================== --}}
                @else

                    <div class="space-y-8">
                        @foreach ($groups as $groupKey => $group)

                            <x-quality-ui.section-card
                                :title="$group['label']"
                                description="الشاشات والنماذج التصورية التابعة لهذه المجموعة."
                                id="group-{{ $groupKey }}"
                            >
                                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                        اختر أحد العناصر لفتح معاينته.
                                    </p>

                                    <x-quality-ui.status-placeholder
                                        :label="count($group['screens']) . ' مدخلات معاينة'"
                                        tone="neutral"
                                    />
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                                    @foreach ($group['screens'] as $screen)
                                        @php
                                            $isSharedStates = $screen['slug'] === 'shared.states';
                                        @endphp

                                        <a
                                            href="{{ request()->fullUrlWithQuery([
                                                'screen' => $screen['slug'],
                                            ]) }}"
                                            aria-label="{{ $isSharedStates
                                                ? 'فتح معرض الحالات المشتركة'
                                                : 'فتح معاينة شاشة ' . $screen['title'] }}"
                                            class="group flex min-w-0 flex-col justify-between rounded-xl border border-gray-200 bg-white p-5 transition-colors hover:border-primary-400 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-gray-800"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0 space-y-2">
                                                    <h3 class="font-semibold leading-6 text-gray-900 group-hover:text-primary-700 dark:text-white dark:group-hover:text-primary-400">
                                                        {{ $screen['title'] }}
                                                    </h3>

                                                    <p class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">
                                                        {{ $screen['slug'] }}
                                                    </p>
                                                </div>

                                                <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ $isSharedStates ? 'حالات مشتركة' : 'تصميم' }}
                                                </span>
                                            </div>

                                            <div class="mt-5 flex items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">
                                                    {{ $isSharedStates ? 'استعراض الحالات' : 'فتح المعاينة' }}
                                                </span>

                                                <x-filament::icon
                                                    icon="heroicon-o-arrow-left"
                                                    class="size-4 shrink-0 text-gray-400 transition-transform group-hover:-translate-x-1"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </x-quality-ui.section-card>

                        @endforeach
                    </div>

                @endif
            </main>
        </div>
    </div>
</x-filament-panels::page>
