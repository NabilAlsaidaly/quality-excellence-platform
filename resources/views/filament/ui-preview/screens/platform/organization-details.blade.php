
<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="الهوية الإدارية للمؤسسة"
        description="مساحة تعريفية لعرض المعلومات الأساسية للمؤسسة ضمن نطاق إدارة المنصة."
    >
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <x-quality-ui.detail-pair
                label="اسم المؤسسة"
            />

            <x-quality-ui.detail-pair
                label="المعرّف العام"
            />

            <x-quality-ui.detail-pair
                label="الحالة التشغيلية"
            />

            <x-quality-ui.detail-pair
                label="جهة التواصل"
            />
        </dl>

        <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                الحقول أعلاه توضيحية فقط، ولا تعرض بيانات مؤسسة فعلية.
            </p>
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="ملخص العضويات"
        description="مساحة عامة لعرض ملخص العضويات المرتبطة بالمؤسسة دون كشف محتوى المستندات أو التفاصيل الحساسة."
    >
        <x-quality-ui.empty-state
            title="لا توجد بيانات عضوية للعرض"
            description="لا تتضمن هذه المعاينة سجلات مستخدمين أو عضويات فعلية."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="تقتصر هذه الشاشة على الهوية الإدارية والملخص العام. لا تشمل عرض منهجيات المؤسسة أو وثائقها أو أدلتها أو مراجعاتها."
    />

</div>
