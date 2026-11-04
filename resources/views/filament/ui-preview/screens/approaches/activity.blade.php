<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="سجل نشاط المنهجية"
        description="مساحة زمنية لعرض التغييرات المرتبطة بالمنهجية عند توفر سجلات فعلية ومعتمدة."
    >
        <div class="mb-5 space-y-2">
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                يوضح سجل النشاط هوية الفاعل ونوع التغيير ووقته،
                إضافة إلى المورد والإصدار المرتبطين بالحدث.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                لا تتضمن هذه المعاينة أحداثًا أو أسماء مستخدمين أو تواريخ فعلية.
            </p>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'الفاعل',
                'نوع التغيير',
                'الوقت',
                'المورد / الإصدار',
            ]"
            caption="سجل نشاط المنهجية — معاينة تصميمية"
        >
            <tr>
                <td colspan="4" class="px-4 py-5">
                    <x-quality-ui.empty-state
                        title="لا توجد أحداث نشاط للعرض"
                        description="لا تتضمن هذه المعاينة تغييرات أو أحداثًا تاريخية فعلية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="التسلسل الزمني"
        description="هيكل توضيحي لمساحة الأحداث التاريخية دون إنشاء أحداث افتراضية."
    >
        <div class="relative space-y-5">

            <div class="absolute right-4 top-5 bottom-5 border-r border-dashed border-gray-300 dark:border-gray-700" aria-hidden="true"></div>

            <div class="relative flex items-start gap-4">
                <div class="z-10 flex size-9 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                    <x-filament::icon
                        icon="heroicon-o-clock"
                        class="size-4"
                    />
                </div>

                <div class="min-w-0 flex-1 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-700">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        موضع حدث في السجل الزمني
                    </p>

                    <p class="mt-1 text-xs leading-6 text-gray-500 dark:text-gray-400">
                        مساحة توضيحية فارغة، دون فاعل أو تاريخ أو تغيير مسجل.
                    </p>
                </div>
            </div>

            <div class="relative flex items-start gap-4">
                <div class="z-10 flex size-9 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="size-4"
                    />
                </div>

                <div class="min-w-0 flex-1 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-700">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        موضع حدث آخر في السجل
                    </p>

                    <p class="mt-1 text-xs leading-6 text-gray-500 dark:text-gray-400">
                        مساحة توضيحية فارغة، دون مورد أو إصدار فعلي.
                    </p>
                </div>
            </div>

        </div>

        <x-quality-ui.empty-state
            title="السجل الزمني فارغ"
            description="لا توجد أحداث تاريخية فعلية ضمن هذه المعاينة."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="السجلات الفعلية جزء من وظائف المنصة الخلفية وليست ضمن تنفيذ الواجهات التصورية في هذه المهمة."
    />

</div>
