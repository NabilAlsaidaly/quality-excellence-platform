<nav aria-label="مسار التنقل" dir="rtl" class="mb-4">
    <ol class="flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <li>إدارة الجودة</li>
        <li aria-hidden="true">/</li>
        <li class="font-medium text-gray-900 dark:text-white">صندوق المراجعات</li>
    </ol>
</nav>

<x-quality-ui.page-header
    title="صندوق مراجعات الجودة"
    description="عرض تصوري للمراجعات الواقعة ضمن نطاق المؤسسة والصلاحيات المخولة."
/>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="معاينة تصميمية فقط. لا توجد مراجعات فعلية أو بيانات تشغيلية معروضة."
    />

    <x-quality-ui.section-card
        title="تصفية المراجعات"
        description="مرشحات شكلية لتوضيح طرق تصنيف المراجعات. جميع عناصر التصفية معطّلة."
    >
        <x-quality-ui.filter-toolbar
            :filters="[
                [
                    'label' => 'الهدف',
                    'placeholder' => 'البحث عن هدف المراجعة',
                ],
                [
                    'label' => 'نطاق المؤسسة',
                    'placeholder' => 'نطاق المؤسسة المخولة فقط',
                ],
                [
                    'label' => 'الحالة الوصفية',
                    'placeholder' => 'اختيار الحالة الوصفية',
                ],
            ]"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="قائمة المراجعات"
        description="الأهداف والإصدارات وأسباب المراجعة ونطاق المؤسسة والحالة الوصفية."
    >
        <x-quality-ui.table-shell
            :columns="[
                'الهدف',
                'الإصدار قيد الفحص',
                'سبب المراجعة',
                'نطاق المؤسسة',
                'الحالة الوصفية',
            ]"
        >
            <tr>
                <td colspan="5" class="px-4 py-8">
                    <x-quality-ui.empty-state
                        title="لا توجد مراجعات للعرض"
                        description="سيظهر هنا ما يقع ضمن نطاق المؤسسة وصلاحيات المستخدم عند توفر بيانات فعلية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="تنشأ المراجعة وإشعار إدارة الجودة تلقائيًا عند حفظ تغيير مؤهل. لا يوجد إجراء مستقل لإرسال المحتوى إلى المراجعة."
    />

</div>
