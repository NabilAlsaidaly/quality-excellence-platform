
<div class="space-y-6" dir="rtl">

    {{-- Approach identity --}}
    <x-quality-ui.section-card
        title="هوية المنهجية"
        description="عرض معلومات تعريفية للمنهجية دون بيانات أو إصدارات فعلية."
    >
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <x-quality-ui.detail-pair
                label="اسم المنهجية"
            />

            <x-quality-ui.detail-pair
                label="الوصف"
            />

            <x-quality-ui.detail-pair
                label="الغاية"
            />

            <x-quality-ui.detail-pair
                label="النطاق"
            />
        </dl>

        <p class="mt-5 border-t border-gray-200 pt-4 text-xs leading-6 text-gray-500 dark:border-gray-700 dark:text-gray-400">
            هذه البيانات حقول تعريفية توضيحية، ولا تمثل محتوى منهجية محفوظة.
        </p>
    </x-quality-ui.section-card>

    {{-- Primary organizational owner --}}
    <x-quality-ui.section-card
        title="الوحدة المالكة الرئيسية"
        description="موضع عرض الوحدة التنظيمية التي تتحمل المسؤولية الرئيسية عن المنهجية."
    >
        <dl>
            <x-quality-ui.detail-pair
                label="الوحدة المالكة الرئيسية"
            />
        </dl>

        <p class="mt-3 text-xs leading-6 text-gray-500 dark:text-gray-400">
            لا تتضمن المعاينة وحدة مالكة فعلية أو إسنادًا تنظيميًا.
        </p>
    </x-quality-ui.section-card>

    {{-- Approach sections navigation --}}
    <x-quality-ui.section-card
        title="أقسام المنهجية"
        description="روابط معاينة للتنقل بين أقسام المنهجية المسجلة في كتالوج الواجهات."
    >
        <x-quality-ui.tabs
            :tabs="[
                [
                    'key' => 'approaches.content',
                    'label' => 'المحتوى',
                ],
                [
                    'key' => 'approaches.participants',
                    'label' => 'المشاركون',
                ],
                [
                    'key' => 'approaches.evidence',
                    'label' => 'الأدلة',
                ],
                [
                    'key' => 'approaches.reviews',
                    'label' => 'المراجعات',
                ],
                [
                    'key' => 'approaches.maturity',
                    'label' => 'النضج',
                ],
                [
                    'key' => 'approaches.activity',
                    'label' => 'النشاط',
                ],
            ]"
        />

        <p class="mt-4 text-xs leading-6 text-gray-500 dark:text-gray-400">
            تفتح هذه الروابط قوالب المعاينة فقط، ولا تمثل وصولًا إلى موارد
            أو سجلات منهجيات فعلية.
        </p>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="هذه معاينة تصميمية لصفحة المنهجية. لا توجد بيانات أو إصدارات أو قرارات اعتماد فعلية."
    />

</div>
