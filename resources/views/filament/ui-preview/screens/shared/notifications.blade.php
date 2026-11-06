<p class="mb-5 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">عرض تصوري للإشعارات المرتبطة بسياق المستخدم، مع توضيح الرسالة وسببها وسياقها وحالة قراءتها.</p>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="هذه الشاشة للمعاينة التصميمية فقط. لا توجد إشعارات فعلية أو عمليات إرسال أو قراءة أو تحديث لحالة الإشعار."
    />

    <x-quality-ui.section-card
        title="قائمة الإشعارات"
        description="موضع عرض الإشعارات التي تخص المستخدم ضمن نطاقه وصلاحياته."
    >
        <x-quality-ui.empty-state
            title="لا توجد إشعارات للعرض"
            description="ستظهر الإشعارات هنا عند توفر بيانات فعلية مصرح بعرضها. لا تحتوي المعاينة الحالية على رسائل أو أحداث مسجلة."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="مكونات الإشعار"
        description="توضيح الحقول التي تتكون منها رسالة الإشعار، دون إنشاء إشعار فعلي أو ربطه بمورد."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

            <x-quality-ui.detail-pair
                label="عنوان الإشعار"
                value="موضع عنوان الرسالة العربية"
            />

            <x-quality-ui.detail-pair
                label="سبب الإشعار"
                value="موضع توضيح سبب الإشعار"
            />

            <x-quality-ui.detail-pair
                label="السياق"
                value="موضع وصف سياق الإشعار"
            />

            <x-quality-ui.detail-pair
                label="المورد المرتبط"
                value="موضع مرجع مورد مصرح به — دون رابط فعّال"
            />

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="حالة القراءة"
        description="أمثلة توضيحية للمظهر البصري لحالتي القراءة، وليست حالات إشعارات فعلية."
    >
        <div class="flex flex-wrap items-center gap-3">

            <x-quality-ui.status-placeholder
                label="مثال توضيحي: مقروء"
                tone="neutral"
            />

            <x-quality-ui.status-placeholder
                label="مثال توضيحي: غير مقروء"
                tone="neutral"
            />

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="warning"
        message="لا توجد روابط فعالة لموارد خاصة، ولا يتم إرسال الإشعارات أو فتحها أو تعليمها كمقروء من قاعدة البيانات ضمن هذه المعاينة."
    />

</div>
