
<div class="space-y-6" dir="rtl">

    {{-- Organizational structure --}}
    <x-quality-ui.section-card
        title="البنية التنظيمية"
        description="تمثيل توضيحي لمستويات الشجرة التنظيمية داخل المؤسسة، دون عرض هيكل مؤسسي فعلي."
    >
        <div class="space-y-5">

            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                يوضح هذا النموذج كيفية تنظيم عرض الوحدات ومستويات ارتباطها
                ببعضها، دون افتراض أسماء وحدات أو علاقات تنظيمية حقيقية.
            </p>

            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">

                <div class="w-full max-w-sm rounded-lg border border-dashed border-gray-300 bg-white px-4 py-4 text-center dark:border-gray-600 dark:bg-gray-900">
                    <x-filament::icon
                        icon="heroicon-o-building-office"
                        class="mx-auto mb-2 size-6 text-gray-400"
                    />

                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        موضع الوحدة التنظيمية الرئيسية
                    </p>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        مساحة توضيحية فارغة
                    </p>
                </div>

                <div
                    class="h-7 border-r-2 border-dashed border-gray-300 dark:border-gray-600"
                    aria-hidden="true"
                ></div>

                <div class="grid w-full max-w-2xl grid-cols-1 gap-3 sm:grid-cols-2">

                    <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-4 text-center dark:border-gray-600 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            موضع وحدة تنظيمية فرعية
                        </p>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            مساحة توضيحية فارغة
                        </p>
                    </div>

                    <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-4 text-center dark:border-gray-600 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            موضع وحدة تنظيمية فرعية
                        </p>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            مساحة توضيحية فارغة
                        </p>
                    </div>

                </div>

                <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                    الرسم يوضح مستويات العرض فقط، ولا يمثل هيكل مؤسسة حقيقيًا.
                </p>

            </div>

            <x-quality-ui.empty-state
                title="لا توجد وحدات تنظيمية للعرض"
                description="لا تتضمن هذه المعاينة وحدات أو علاقات تنظيمية فعلية."
            />

        </div>
    </x-quality-ui.section-card>

    {{-- Organizational units table --}}
    <x-quality-ui.section-card
        title="قائمة الوحدات التنظيمية"
        description="عرض حقول الوحدات التنظيمية وعلاقاتها دون تحميل سجلات فعلية."
    >
        <x-quality-ui.table-shell
            :columns="[
                'اسم الوحدة',
                'الوحدة الأم',
                'الوصف',
            ]"
            caption="جدول الوحدات التنظيمية — معاينة تصميمية"
        />
    </x-quality-ui.section-card>

    {{-- Disabled structure form --}}
    <x-quality-ui.section-card
        title="نموذج البنية التنظيمية"
        description="نموذج توضيحي للحقول التي تظهر ضمن واجهة تنظيم الوحدات."
    >
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

            <x-quality-ui.form-field-shell
                label="اسم الوحدة"
                hint="اسم الوحدة التنظيمية"
                kind="input"
            />

            <x-quality-ui.form-field-shell
                label="الوحدة الأم"
                hint="موضع اختيار الوحدة الأم عند توفر الوظيفة المعتمدة"
                kind="select"
            />

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="وصف الوحدة"
                    hint="وصف موجز لطبيعة الوحدة ومسؤولياتها"
                    kind="textarea"
                />
            </div>

        </div>

        <p class="mt-4 text-xs leading-6 text-gray-500 dark:text-gray-400">
            جميع الحقول معطلة، ولا يمكن إنشاء وحدة أو حفظ أي تغيير من هذه المعاينة.
        </p>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="هذه الشاشة لا تنشئ هيكلًا تنظيميًا رسميًا، ولا تقرر توريث الصلاحيات بين الوحدات. تخضع صلاحيات المؤسسة والوحدات للقرارات المعتمدة لاحقًا."
    />

</div>
