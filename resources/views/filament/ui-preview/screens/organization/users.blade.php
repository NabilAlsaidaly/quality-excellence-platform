<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="المستخدمون والعضويات"
        description="مساحة عرض تصورية لهوية المستخدمين وارتباطاتهم المؤسسية ونطاقات مشاركتهم."
    >
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                    يوضح الجدول الحقول الأساسية اللازمة لاستعراض المستخدمين
                    والعضويات ضمن المؤسسة.
                </p>

                <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                    لا تتضمن هذه المعاينة حسابات أو عضويات أو بيانات أشخاص حقيقية.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:items-end">
                <button
                    type="button"
                    disabled
                    aria-disabled="true"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500 cursor-not-allowed dark:bg-gray-700 dark:text-gray-400"
                >
                    <x-filament::icon
                        icon="heroicon-o-user-plus"
                        class="size-4"
                    />
                    دعوة مستخدم
                </button>

                <span class="text-xs text-gray-500 dark:text-gray-400">
                    الدعوات وإضافة المستخدمين غير متاحة في المعاينة.
                </span>
            </div>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'المستخدم',
                'البريد الإلكتروني',
                'المؤسسة',
                'حالة العضوية',
                'نطاق المشاركة',
            ]"
            caption="جدول المستخدمين والعضويات — معاينة تصميمية"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="الحساب المؤسسي مستقل ببيانات دخوله، ويُحدد نطاق المؤسسة عند الدخول والاستعادة. تعرض المعاينة حقولًا وصفية فقط؛ لا تربط الحسابات تلقائيًا ولا تنشئ عضوية أو دعوة أو تمنح صلاحية. تفاصيل التفويض D-002 لم تعتمد بعد."
    />

</div>
