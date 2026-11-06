<div class="space-y-6" dir="rtl">

    <x-quality-ui.info-alert
        variant="warning"
        message="معلومات فقط؛ محرك النضج غير مفعّل. تعرض هذه الشاشة المراحل الخمس وتسلسلها دون تشغيل التقييم أو فتح المراحل للاستكمال."
    />

    <x-quality-ui.section-card
        title="مراحل دورة نضج المنهجية"
        description="تسلسل معلوماتي ثابت للمراحل الخمس التي تتكون منها دورة نضج المنهجية."
    >
        <x-quality-ui.stage-strip />

        <p class="mt-5 text-sm leading-7 text-gray-600 dark:text-gray-300">
            تتبع دورة نضج المنهجية خمس مراحل متسلسلة. تعرض هذه المعاينة
            أسماء المراحل وترتيبها فقط، ولا تمثل تقييمًا أو تقدمًا
            فعليًا في أي مرحلة.
        </p>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="حالة إتاحة المراحل"
        description="توضيح حدود التفاعل مع المراحل ضمن النواة التجريبية."
    >
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">

            <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <x-filament::icon
                        icon="heroicon-o-lock-closed"
                        class="size-5"
                    />
                </div>

                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            استكمال المراحل التالية
                        </h3>

                        <x-quality-ui.status-placeholder
                            label="غير مفعّل في المعاينة"
                            tone="neutral"
                        />
                    </div>

                    <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                        تظهر المراحل التالية ضمن التسلسل للتعريف بها،
                        دون إتاحة إدخال الإجابات أو الشواهد أو تنفيذ
                        تقييم نضج فعلي.
                    </p>
                </div>
            </div>

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="حدود عرض النضج في هذه المرحلة"
        description="المعلومات التي توفرها المعاينة دون تشغيل محرك النضج."
    >
        <ul class="space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <li class="flex items-start gap-2">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="mt-1 size-5 shrink-0 text-gray-400"
                />
                <span>
                    عرض أسماء المراحل الخمس وترتيبها الثابت.
                </span>
            </li>

            <li class="flex items-start gap-2">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="mt-1 size-5 shrink-0 text-gray-400"
                />
                <span>
                    توضيح أن التقييم وإدخال الشواهد غير مفعّلين.
                </span>
            </li>

            <li class="flex items-start gap-2">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="mt-1 size-5 shrink-0 text-gray-400"
                />
                <span>
                    إظهار المراحل التالية دون توفير إجراءات لفتحها أو استكمالها.
                </span>
            </li>
        </ul>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="لا تعرض المعاينة نتائج تقييم أو نسبًا أو معدل نضج إجماليًا. أي تقييم مستقبلي يتطلب محركًا معتمدًا وقرارًا بشريًا وفق المتطلبات."
    />

</div>
