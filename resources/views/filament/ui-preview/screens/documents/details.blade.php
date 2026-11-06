<p class="mb-5 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">عرض تصوري للمعلومات التعريفية للوثيقة ومالكها وحالة اعتمادها المؤسسي وسجل إصداراتها.</p>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="هذه شاشة معاينة تصميمية. لا توجد وثيقة فعلية محددة أو بيانات محفوظة أو عمليات تعديل واعتماد مفعّلة."
    />

    <x-quality-ui.section-card
        title="المعلومات التعريفية"
        description="الوثيقة كعنصر مستقل، بصرف النظر عن الإصدارات التابعة لها."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-quality-ui.detail-pair
                label="اسم الوثيقة"
                value="لا توجد بيانات للعرض"
            />

            <x-quality-ui.detail-pair
                label="معرّف الوثيقة"
                value="لا يوجد معرّف مسجل"
            />

            <x-quality-ui.detail-pair
                label="المالك"
                value="لا توجد بيانات للمالك"
            />

            <x-quality-ui.detail-pair
                label="حالة الاعتماد المؤسسي"
                value="غير متاحة في المعاينة"
            />
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="الإصدار الحالي"
        description="يعرض هذا القسم موضع معلومات الإصدار المرتبط بهوية الوثيقة."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-quality-ui.detail-pair
                label="آخر إصدار"
                value="لا يوجد إصدار مسجل"
            />

            <x-quality-ui.detail-pair
                label="تاريخ الإصدار"
                value="لا توجد بيانات"
            />
        </div>

        <div class="mt-4">
            <x-quality-ui.empty-state
                title="لا يوجد إصدار للعرض"
                description="ستظهر معلومات الإصدار عند توفر بيانات فعلية. لا تعرض المعاينة ملفات أو نسخًا افتراضية."
            />
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="سجل إصدارات الوثيقة"
        description="الإصدارات سجل مستقل مرتبط بهوية الوثيقة، ويُحافظ على تاريخ كل إصدار."
    >
        <x-quality-ui.version-timeline
            title="تسلسل إصدارات الوثيقة"
            :items="[]"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="روابط الأدلة"
        description="موضع عرض ارتباط إصدارات الوثيقة بالأدلة المستخدمة ضمن المناهج."
    >
        <x-quality-ui.empty-state
            title="لا توجد روابط أدلة"
            description="لا توجد ارتباطات مسجلة ضمن المعاينة الحالية."
        />
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="اعتماد الوثيقة على المستوى المؤسسي مستقل عن قبول إصدار منها بوصفه دليلًا في منهج أو مشاركة. لا تنفذ هذه الشاشة أي اعتماد أو قبول."
    />

</div>
