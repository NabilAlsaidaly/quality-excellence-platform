<div class="space-y-6" dir="rtl">

    {{-- Search and filters --}}
    <x-quality-ui.section-card
        title="البحث والتصفية"
        description="عناصر شكلية لتوضيح طريقة استعراض المنهجيات، دون تنفيذ عمليات بحث أو تصفية."
    >
        <x-quality-ui.filter-toolbar
            :filters="[
                [
                    'label' => 'اسم المنهجية',
                    'placeholder' => 'البحث عن منهجية غير متاح في المعاينة',
                ],
                [
                    'label' => 'المالك التنظيمي',
                    'placeholder' => 'اختيار المالك غير متاح في المعاينة',
                ],
                [
                    'label' => 'حالة العرض',
                    'placeholder' => 'التصفية غير متاحة في المعاينة',
                ],
            ]"
        />

        <p class="mt-3 text-xs leading-6 text-gray-500 dark:text-gray-400">
            لا تنفذ هذه الحقول أي استعلام أو فرز أو تصفية فعلية.
        </p>
    </x-quality-ui.section-card>

    {{-- Approaches listing --}}
    <x-quality-ui.section-card
        title="قائمة المنهجيات"
        description="مساحة عرض لمنهجيات المؤسسة ومالكيها التنظيميين ومعلوماتها الوصفية."
    >
        <div class="mb-5 space-y-2">
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                تمثل المنهجية طريقة عمل مؤسسية منظمة، ويكون لها مالك تنظيمي
                رئيسي مسؤول عنها، مع إمكانية وجود مساهمين داعمين ضمن نطاقات
                مشاركة محددة.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                تعرض هذه القائمة بنية معلوماتية فقط، ولا تتضمن منهجيات أو
                إصدارات أو سجلات فعلية.
            </p>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'اسم المنهجية',
                'المالك التنظيمي',
                'الملخص',
                'آخر إصدار / تحديث',
                'حالة العرض',
            ]"
            caption="قائمة المنهجيات — معاينة تصميمية"
        >
            <tr>
                <td colspan="5" class="px-4 py-5">
                    <x-quality-ui.empty-state
                        title="لا توجد منهجيات للعرض"
                        description="لا تتضمن هذه المعاينة منهجيات فعلية أو ملاكًا أو إصدارات أو حالات تشغيلية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="تُعرض حالة المنهجية بصورة وصفية ومحايدة عند توفر بيانات معتمدة. لا تتضمن هذه المعاينة درجات نضج أو نسبًا أو نتائج اعتماد."
    />

</div>
