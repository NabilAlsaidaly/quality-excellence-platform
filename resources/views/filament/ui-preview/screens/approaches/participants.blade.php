<div class="space-y-6" dir="rtl">

    {{-- Primary approach owner --}}
    <x-quality-ui.section-card
        title="الوحدة المالكة الرئيسية"
        description="الوحدة التنظيمية التي تتحمل المسؤولية الرئيسية عن المنهجية."
    >
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">
            <div class="mb-4 flex items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-white text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <x-filament::icon
                        icon="heroicon-o-building-office"
                        class="size-5"
                    />
                </div>

                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        مسؤولية الملكية الرئيسية
                    </h3>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        مساحة تعريفية مستقلة عن مساهمات الوحدات الداعمة.
                    </p>
                </div>
            </div>

            <dl>
                <x-quality-ui.detail-pair
                    label="الوحدة التنظيمية المالكة"
                />
            </dl>

            <p class="mt-4 border-t border-gray-200 pt-3 text-xs leading-6 text-gray-500 dark:border-gray-700 dark:text-gray-400">
                لا تتضمن هذه المعاينة وحدة مالكة محددة أو إسنادًا فعليًا للمنهجية.
            </p>
        </div>
    </x-quality-ui.section-card>

    {{-- Supporting participants --}}
    <x-quality-ui.section-card
        title="الوحدات والمستخدمون الداعمون"
        description="مساحة مستقلة لعرض الجهات والأشخاص المشاركين في المساهمة ضمن المنهجية."
    >
        <div class="mb-5 flex items-center gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <x-filament::icon
                    icon="heroicon-o-user-group"
                    class="size-5"
                />
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    المساهمات الداعمة
                </h3>

                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    تعرض هذه المساحة المشاركين ونطاقات مساهمتهم بصورة وصفية.
                </p>
            </div>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'الوحدة أو المستخدم المشارك',
                'نطاق المشاركة',
                'وصف المساهمة',
            ]"
            caption="المشاركون الداعمون — معاينة تصميمية"
        />

        <x-quality-ui.empty-state
            title="لا توجد مشاركات للعرض"
            description="لا تتضمن هذه المعاينة وحدات أو مستخدمين مشاركين أو نطاقات مساهمة فعلية."
        />
    </x-quality-ui.section-card>

    {{-- Ownership and participation distinction --}}
    <x-quality-ui.info-alert
        variant="info"
        message="المشاركة الداعمة لا تنقل ملكية المنهجية من وحدتها المالكة الرئيسية، ولا تمنح حق اعتماد المحتوى تلقائيًا. تفاصيل المشاركة وصلاحياتها تخضع للقرارات المعتمدة."
    />

    <x-quality-ui.section-card
        title="حدود تصميم المشاركة"
        description="توضيح الفصل بين المسؤولية الرئيسية والمساهمة الداعمة."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

            <div class="rounded-lg border-r-4 border-gray-400 bg-gray-50 p-4 dark:border-gray-500 dark:bg-gray-800/50">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    الملكية الرئيسية
                </h3>

                <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    مسؤولية تنظيمية رئيسية مرتبطة بمنهجية محددة داخل المؤسسة.
                </p>
            </div>

            <div class="rounded-lg border-r-4 border-gray-300 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-800/50">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    المشاركة الداعمة
                </h3>

                <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    مساهمة محددة النطاق دون انتقال ملكية المنهجية أو اعتماد محتواها تلقائيًا.
                </p>
            </div>

        </div>

        <p class="mt-4 text-xs leading-6 text-gray-500 dark:text-gray-400">
            تفاصيل تنظيم المشاركة ونقل الملكية مرتبطة بالقرار D-018، ولا تقررها هذه المعاينة.
        </p>
    </x-quality-ui.section-card>

</div>
