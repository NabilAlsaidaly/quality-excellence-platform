<div class="space-y-6" dir="rtl">

    <x-quality-ui.info-alert
        variant="info"
        message="تختلف طريقة العمل ومحتويات المنهجية بحسب طبيعتها. تعرض هذه الشاشة هيكلًا مرنًا للأقسام دون فرض قالب موحد أو تفعيل الحفظ."
    />

    <x-quality-ui.section-card
        title="أقسام محتوى المنهجية"
        description="مساحات توضيحية لعناصر المحتوى التي يمكن أن تختلف في عددها وترتيبها وطبيعتها بين المنهجيات."
    >
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            <section
                class="min-w-0 rounded-xl border border-dashed border-gray-300 p-5 dark:border-gray-700"
                aria-labelledby="content-section-one"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3
                        id="content-section-one"
                        class="text-sm font-semibold text-gray-900 dark:text-white"
                    >
                        قسم محتوى
                    </h3>

                    <x-quality-ui.status-placeholder
                        label="مساحة توضيحية"
                        tone="neutral"
                    />
                </div>

                <div class="space-y-3">
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800/60">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            موضع عنوان القسم
                        </p>
                        <div
                            class="mt-3 h-3 w-3/4 rounded bg-gray-200 dark:bg-gray-700"
                            aria-hidden="true"
                        ></div>
                    </div>

                    <div class="min-h-28 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            مساحة نصية فارغة
                        </p>
                        <div class="mt-4 space-y-2" aria-hidden="true">
                            <div class="h-2 w-full rounded bg-gray-100 dark:bg-gray-800"></div>
                            <div class="h-2 w-5/6 rounded bg-gray-100 dark:bg-gray-800"></div>
                            <div class="h-2 w-2/3 rounded bg-gray-100 dark:bg-gray-800"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="min-w-0 rounded-xl border border-dashed border-gray-300 p-5 dark:border-gray-700"
                aria-labelledby="content-section-two"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3
                        id="content-section-two"
                        class="text-sm font-semibold text-gray-900 dark:text-white"
                    >
                        قسم محتوى
                    </h3>

                    <x-quality-ui.status-placeholder
                        label="مساحة توضيحية"
                        tone="neutral"
                    />
                </div>

                <div class="space-y-3">
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800/60">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            موضع عنوان القسم
                        </p>
                        <div
                            class="mt-3 h-3 w-3/4 rounded bg-gray-200 dark:bg-gray-700"
                            aria-hidden="true"
                        ></div>
                    </div>

                    <div class="min-h-28 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            مساحة نصية فارغة
                        </p>
                        <div class="mt-4 space-y-2" aria-hidden="true">
                            <div class="h-2 w-full rounded bg-gray-100 dark:bg-gray-800"></div>
                            <div class="h-2 w-5/6 rounded bg-gray-100 dark:bg-gray-800"></div>
                            <div class="h-2 w-2/3 rounded bg-gray-100 dark:bg-gray-800"></div>
                        </div>
                    </div>
                </div>
            </section>

        </div>

        <x-quality-ui.empty-state
            title="لا يوجد محتوى للمنهجية"
            description="المساحات السابقة نماذج شكلية لبنية المحتوى، ولا تتضمن نصوصًا أو معلومات فعلية."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="مرونة بنية المحتوى"
        description="توضيح مبدأ تصميم محتوى المنهجيات."
    >
        <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
            لا يُفترض أن تتبع جميع المنهجيات عددًا ثابتًا من الأقسام
            أو ترتيبًا موحدًا أو مجموعة حقول تفصيلية واحدة.
            تتحدد بنية المحتوى بحسب طبيعة طريقة العمل التي تصفها المنهجية.
        </p>

        <p class="mt-3 text-xs leading-6 text-gray-500 dark:text-gray-400">
            تفاصيل الحقول والقوالب المرنة مرتبطة بالقرار D-005،
            ولا تُعتمد هذه المعاينة بوصفها نموذجًا نهائيًا للمحتوى.
        </p>
    </x-quality-ui.section-card>

</div>
