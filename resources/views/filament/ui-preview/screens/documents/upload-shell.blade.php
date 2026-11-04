<nav aria-label="مسار التنقل" dir="rtl" class="mb-4">
    <ol class="flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <li>الوثائق</li>
        <li aria-hidden="true">/</li>
        <li class="font-medium text-gray-900 dark:text-white">رفع وثيقة</li>
    </ol>
</nav>

<x-quality-ui.page-header
    title="رفع وثيقة"
    description="نموذج تصوري لبيانات الوثيقة وملفها ومالكها والسياق المرتبط بها."
/>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="معاينة تصميمية فقط. منطقة اختيار الملفات والحقول معطّلة، ولا توجد عملية رفع أو حفظ أو تخزين."
    />

    <x-quality-ui.section-card
        title="ملف الوثيقة"
        description="منطقة إسقاط ملفات توضيحية، غير متصلة بأي خدمة رفع أو تخزين."
    >
        <div
            role="group"
            aria-disabled="true"
            class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center dark:border-gray-700 dark:bg-gray-800/40"
        >
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                <x-heroicon-o-cloud-arrow-up class="h-7 w-7" />
            </div>

            <h3 class="font-semibold text-gray-900 dark:text-white">
                منطقة اختيار ملف الوثيقة
            </h3>

            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                لم يتم اختيار أي ملف
            </p>

            <button
                type="button"
                disabled
                aria-disabled="true"
                class="mt-5 cursor-not-allowed rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400"
            >
                اختيار ملف — غير مفعّل
            </button>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                اختيار الملفات والإسقاط غير متاحين في هذه المعاينة.
            </p>
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="بيانات الوثيقة"
        description="الحقول التالية توضح البيانات التعريفية المطلوبة في تصميم واجهة الوثيقة، دون استقبال أو حفظ قيم."
    >
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

            <x-quality-ui.form-field-shell
                label="اسم الوثيقة"
                hint="الاسم التعريفي للوثيقة."
                kind="input"
            />

            <x-quality-ui.form-field-shell
                label="المالك"
                hint="موضع تحديد مالك الوثيقة."
                kind="select"
            />

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="الوصف"
                    hint="وصف تعريفي لمحتوى الوثيقة."
                    kind="textarea"
                />
            </div>

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="السياق"
                    hint="موضع تحديد السياق المرتبط بالوثيقة."
                    kind="select"
                />
            </div>

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="ملاحظات حول خصوصية الملفات"
        description="إيضاح تصميمي مرتبط بالسياسة المستقبلية للملفات الخاصة."
    >
        <div class="space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <p>
                سياسة تخزين الملفات الخاصة والوصول إليها تحتاج إلى اعتماد القرار
                والضوابط ذات الصلة قبل تنفيذ أي عمليات فعلية.
            </p>

            <p>
                لا تحدد هذه الشاشة سياسة تخزين نهائية، ولا تنشئ مسارات ملفات أو
                روابط عامة أو آليات تنزيل.
            </p>
        </div>

        <div class="mt-4">
            <x-quality-ui.status-placeholder
                label="سياسة الملفات الخاصة — بانتظار الاعتماد"
                tone="neutral"
            />
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="warning"
        message="لا يوجد JavaScript Upload أو Storage أو رابط Public أو تحقق شكلي يوحي بنجاح عملية رفع. جميع عناصر النموذج معطّلة."
    />

</div>
