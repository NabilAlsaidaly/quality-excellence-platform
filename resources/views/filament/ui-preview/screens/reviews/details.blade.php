<p class="mb-5 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">عرض تصوري لمقارنة الإصدارات وملاحظات المراجع وسجل قرارات المراجعة السابقة.</p>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="هذه الشاشة للمعاينة التصميمية فقط. لا توجد مراجعة فعلية أو إصدارات أو قرارات مسجلة."
    />

    <x-quality-ui.section-card
        title="مقارنة الإصدارات"
        description="موضع عرض الفرق بين الإصدار السابق والإصدار الحالي محل المراجعة."
    >
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900 dark:text-white">
                        الإصدار السابق
                    </h3>

                    <x-quality-ui.status-placeholder
                        label="غير متوفر"
                        tone="neutral"
                    />
                </div>

                <x-quality-ui.empty-state
                    title="لا يوجد إصدار سابق للعرض"
                    description="ستظهر هنا معلومات الإصدار السابق ومحتواه عند توفر بيانات مراجعة فعلية."
                />
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900 dark:text-white">
                        الإصدار الحالي محل المراجعة
                    </h3>

                    <x-quality-ui.status-placeholder
                        label="غير متوفر"
                        tone="neutral"
                    />
                </div>

                <x-quality-ui.empty-state
                    title="لا يوجد إصدار محل مراجعة"
                    description="ستظهر هنا معلومات الإصدار الحالي ومحتواه عند توفر بيانات مراجعة فعلية."
                />
            </div>

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="ملاحظات المراجع"
        description="موضع عرض الملاحظات المرتبطة بقرار المراجعة."
    >
        <x-quality-ui.empty-state
            title="لا توجد ملاحظات للعرض"
            description="لا توجد ملاحظات مراجعة مسجلة ضمن المعاينة الحالية."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="سجل المراجعات السابقة"
        description="يعرض هذا القسم موضع سجل المراجعات والقرارات السابقة دون إنشاء سجلات افتراضية."
    >
        <x-quality-ui.review-history
            :items="[]"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="إجراءات المراجعة"
        description="أزرار توضيحية للقرارات المحتملة، وهي معطّلة بالكامل في هذه المعاينة."
    >
        <div class="flex flex-wrap items-center gap-3">

            <button
                type="button"
                disabled
                aria-disabled="true"
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-gray-200 px-5 py-2.5 text-sm font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400"
            >
                <x-heroicon-o-check-circle class="h-5 w-5" />
                اعتماد
            </button>

            <button
                type="button"
                disabled
                aria-disabled="true"
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-gray-300 bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400"
            >
                <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" />
                طلب تعديل
            </button>

        </div>

        <p class="mt-4 text-xs leading-6 text-gray-500 dark:text-gray-400">
            لا تنفذ هذه الأزرار أي قرار أو تغيير. تفعيل قرارات المراجعة خارج نطاق هذه المهمة.
        </p>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="warning"
        message="سياسة فصل الواجبات ومنع مراجعة الذات مرتبطة بالقرار D-022، ولا تفترض هذه المعاينة أي قاعدة غير معتمدة."
    />

</div>
