<div class="space-y-6" dir="rtl">

    <x-quality-ui.info-alert
        variant="warning"
        message="تعريف الأدوار والصلاحيات ينتظر اعتماد صاحب المشروع (D-002). هذه الشاشة معلوماتية فقط، ولا تعرض صلاحيات نافذة أو تفويضات معتمدة."
    />

    <x-quality-ui.section-card
        title="نطاقات الصلاحيات"
        description="توضيح مستويات النطاق التي يرتبط بها تصميم الصلاحيات، دون تحديد أدوار أو منح صلاحيات فعلية."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-white text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <x-filament::icon
                        icon="heroicon-o-building-office"
                        class="size-5"
                    />
                </div>

                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    نطاق المؤسسة
                </h3>

                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    يمثل المستوى المرتبط بحدود المؤسسة واستقلال بياناتها.
                </p>

                <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    تفاصيل التفويض لم تعتمد بعد.
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-white text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <x-filament::icon
                        icon="heroicon-o-rectangle-group"
                        class="size-5"
                    />
                </div>

                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    نطاق الوحدة التنظيمية
                </h3>

                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    يمثل المستوى المرتبط بالوحدات التنظيمية وعلاقاتها داخل المؤسسة.
                </p>

                <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    قواعد نطاق الوصول لم تعتمد بعد.
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-white text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <x-filament::icon
                        icon="heroicon-o-clipboard-document-list"
                        class="size-5"
                    />
                </div>

                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    نطاق المنهجية
                </h3>

                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    يمثل المستوى المرتبط بمنهجية محددة ونطاق المشاركة فيها.
                </p>

                <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    تفاصيل صلاحيات المالك والمشاركين لم تعتمد بعد.
                </p>
            </div>

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="حالة اعتماد تعريف الأدوار والصلاحيات"
        description="توضيح الوضع الحالي للقرار المرتبط بتصميم التفويض داخل المؤسسة."
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

            <div class="space-y-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    القرار D-002
                </h3>

                <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                    يتناول القرار مصفوفة الأدوار، وإسناد الأفعال،
                    ونطاق المؤسسة والوحدة التنظيمية والمنهجية،
                    والعلاقة بين إدارة المؤسسة وملكية المنهجية.
                </p>

                <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                    يجب اعتماد التفاصيل من أصحاب القرار قبل تحويلها
                    إلى تعريفات أو سياسات تشغيلية.
                </p>
            </div>

            <x-quality-ui.status-placeholder
                label="بانتظار الاعتماد"
                tone="neutral"
            />

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.empty-state
        title="لا توجد مصفوفة صلاحيات معتمدة للعرض"
        description="لن تظهر أدوار أو إجراءات أو خيارات تفويض قبل اعتماد القرار D-002."
    />

</div>
