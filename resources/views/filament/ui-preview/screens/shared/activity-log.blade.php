
<div class="space-y-6" dir="rtl">

    <p class="mb-5 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">عرض توضيحي لسجل الأنشطة والأحداث المرتبطة بالمنصة.</p>

    <x-quality-ui.info-alert
        variant="info"
        message="معاينة تصميمية. هذه الشاشة مخصصة لمعاينة تصميم سجل النشاط فقط. لا توجد أحداث أو سجلات فعلية معروضة، ولا تتضمن هذه المعاينة وظائف لتعديل السجل أو حذف أحداث منه."
    />

    <x-quality-ui.section-card
        title="سجل النشاط"
        description="يعرض الجدول البنية المخصصة لتوثيق الفاعل والإجراء ووقت التنفيذ والسياق المرتبط بالحدث."
    >
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm text-right">

                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                        <th scope="col" class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">
                            الفاعل
                        </th>

                        <th scope="col" class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">
                            الإجراء
                        </th>

                        <th scope="col" class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">
                            وقت التنفيذ
                        </th>

                        <th scope="col" class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">
                            المؤسسة/المورد/الإصدار
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center">
                            <div class="mx-auto max-w-md space-y-2">
                                <div class="text-base font-semibold text-gray-700 dark:text-gray-200">
                                    لا توجد سجلات نشاط للعرض
                                </div>

                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    ستظهر سجلات النشاط هنا عند توفر بيانات فعلية.
                                    لا تعرض هذه المعاينة أي أحداث تجريبية أو بيانات مختلقة.
                                </p>
                            </div>
                        </td>
                    </tr>
                </tbody>

            </table>
        </div>
    </x-quality-ui.section-card>

</div>
