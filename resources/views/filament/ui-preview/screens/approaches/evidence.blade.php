<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="روابط الأدلة المرتبطة بالمنهجية"
        description="مساحة عرض للوثائق وإصداراتها المرتبطة بمواضع الشواهد ضمن المنهجية."
    >
        <div class="mb-5 space-y-2">
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                يرتبط الشاهد بإصدار محدد وثابت من الوثيقة، حتى تبقى
                الإشارة إلى المحتوى المستخدم واضحة ولا تتغير عند إصدار
                نسخة أحدث من الوثيقة.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                لا تتضمن هذه المعاينة وثائق أو إصدارات أو روابط أدلة فعلية.
            </p>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'اسم الوثيقة',
                'الإصدار الثابت',
                'الغرض من الربط',
                'حالة القبول السياقية',
            ]"
            caption="روابط الأدلة — معاينة تصميمية"
        >
            <tr>
                <td colspan="4" class="px-4 py-5">
                    <x-quality-ui.empty-state
                        title="لا توجد أدلة مرتبطة للعرض"
                        description="لا تتضمن هذه المعاينة وثائق أو إصدارات مرتبطة بمنهجية أو حالات قبول فعلية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="معلومات ارتباط الشاهد"
        description="العناصر الوصفية التي تميز ارتباط إصدار الوثيقة بموضع شاهد محدد."
    >
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <x-quality-ui.detail-pair
                label="الوثيقة المرتبطة"
            />

            <x-quality-ui.detail-pair
                label="إصدار الوثيقة"
            />

            <x-quality-ui.detail-pair
                label="موضع الشاهد"
            />

            <x-quality-ui.detail-pair
                label="الغرض من الربط"
            />
        </dl>

        <p class="mt-4 border-t border-gray-200 pt-3 text-xs leading-6 text-gray-500 dark:border-gray-700 dark:text-gray-400">
            هذه مواضع عرض فارغة فقط، ولا تمثل ارتباطًا أو قبولًا مسجلًا.
        </p>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="التمييز بين اعتماد الوثيقة وقبول الشاهد"
        description="حالتان مستقلتان يجب عدم الخلط بينهما في عرض الأدلة."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

            <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    اعتماد الوثيقة
                </h3>

                <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    يتعلق بحالة اعتماد الوثيقة داخل المؤسسة وإدارتها
                    بوصفها كيانًا وثائقيًا له هوية وإصدارات مستقلة.
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    قبول إصدار الوثيقة كشاهد
                </h3>

                <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    يتعلق بمدى قبول إصدار محدد من الوثيقة كشاهد
                    في سياق منهجية أو موضع إثبات معين.
                </p>
            </div>

        </div>

        <x-quality-ui.info-alert
            variant="info"
            message="اعتماد الوثيقة لا يعني تلقائيًا قبولها كشاهد، وقبول إصدارها كشاهد لا يغيّر حالة اعتماد الوثيقة."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="warning"
        message="هذه الشاشة للعرض التصميمي فقط. لا توجد عمليات رفع أو تنزيل أو ربط أو قبول أدلة مفعّلة."
    />

</div>
