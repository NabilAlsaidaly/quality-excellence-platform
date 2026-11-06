
<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="البحث عن المؤسسات"
        description="يمكن استعراض شكل البحث ضمن هذه المعاينة، دون تنفيذ أي استعلام أو تصفية فعلية."
    >
        <x-quality-ui.filter-toolbar
            :filters="[
                [
                    'label' => 'اسم المؤسسة',
                    'placeholder' => 'البحث عن مؤسسة غير متاح في المعاينة',
                ],
            ]"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="قائمة المؤسسات"
        description="عرض تصوري لسجل المؤسسات التي تقع إدارتها ضمن نطاق المنصة."
    >
        <x-slot name="default">
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">
                    لا تتضمن هذه الصفحة سجلات مؤسسات حقيقية أو معلومات تشغيلية.
                </p>

                <div class="flex flex-col gap-2 sm:items-end">
                    <button
                        type="button"
                        disabled
                        aria-disabled="true"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500 cursor-not-allowed dark:bg-gray-700 dark:text-gray-400"
                    >
                        <x-filament::icon
                            icon="heroicon-o-plus"
                            class="size-4"
                        />
                        إضافة مؤسسة
                    </button>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        الإضافة غير متاحة في المعاينة التصميمية.
                    </span>
                </div>
            </div>

            <x-quality-ui.table-shell
                :columns="[
                    'اسم المؤسسة',
                    'الحالة التشغيلية',
                    'تاريخ الإنشاء',
                    'الإجراء',
                ]"
                caption="جدول المؤسسات — معاينة تصميمية"
            />
        </x-slot>
    </x-quality-ui.section-card>

</div>
