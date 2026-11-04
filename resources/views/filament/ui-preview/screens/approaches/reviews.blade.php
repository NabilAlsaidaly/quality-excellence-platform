<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="تاريخ مراجعات المنهجية"
        description="مساحة عرض تاريخية للمراجعات المرتبطة بأهداف وإصدارات محددة من محتوى المنهجية."
    >
        <div class="mb-5 space-y-2">
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                توضح هذه القائمة البنية المعلوماتية لسجل المراجعات،
                بما يساعد على التمييز بين هدف المراجعة والإصدار
                والملاحظات والقرار السابق.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                لا تتضمن هذه المعاينة مراجعات أو قرارات أو ملاحظات فعلية.
            </p>
        </div>

        <x-quality-ui.table-shell
            :columns="[
                'هدف المراجعة',
                'الإصدار',
                'الملاحظات',
                'القرار السابق',
            ]"
            caption="تاريخ مراجعات المنهجية — معاينة تصميمية"
        >
            <tr>
                <td colspan="4" class="px-4 py-5">
                    <x-quality-ui.empty-state
                        title="لا توجد مراجعات للعرض"
                        description="لا يتضمن هذا النموذج طلبات مراجعة أو إصدارات أو قرارات سابقة فعلية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="آلية إنشاء المراجعة"
        description="توضيح ارتباط المراجعة بحفظ المحتوى المؤهل."
    >
        <div class="space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <p>
                وفق متطلبات المنصة، يرتبط إنشاء المراجعة بالحفظ المؤهل
                للتغيير الذي يستلزم المراجعة، وليس بإجراء إرسال مستقل
                يفعّله المستخدم من زر منفصل.
            </p>

            <p>
                عند تنفيذ هذه الآلية مستقبلًا، يُحفظ سياق المراجعة
                والإصدار المرتبط بها، مع المحافظة على سجل القرارات السابقة.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                هذه الشاشة لا تنشئ مراجعات ولا ترسل إشعارات؛
                منطق العمل غير مفعّل ضمن مهمة الواجهات التصورية.
            </p>
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="المراجعة والاعتماد وطلب التصحيح ليست إجراءات مفعّلة هنا. تعرض هذه الشاشة سجلًا تصوريًا فارغًا فقط."
    />

</div>
