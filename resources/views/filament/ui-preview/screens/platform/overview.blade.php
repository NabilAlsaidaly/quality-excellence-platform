<div class="space-y-6" dir="rtl">

    <x-quality-ui.section-card
        title="نطاق مسؤولية المنصة"
        description="توضح هذه المساحة الدور العام لإدارة المنصة وحدود مسؤوليتها."
    >
        <div class="space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <p>
                تتولى إدارة المنصة تأسيس حسابات المؤسسات وتهيئة البيئة العامة،
                مع الحفاظ على استقلال كل مؤسسة وخصوصية بياناتها.
            </p>

            <p>
                لا تمنح إدارة المنصة صلاحية تلقائية لإدارة منهجيات المؤسسات
                أو تحديد مستوى نضجها أو الاطلاع على محتواها ومستنداتها الحساسة.
            </p>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                حدود الوصول الاستثنائي إلى محتوى المؤسسات ما زالت مرتبطة
                بالقرار المفتوح D-001.
            </p>
        </div>
    </x-quality-ui.section-card>

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">

        <x-quality-ui.section-card
            title="المؤسسات"
            description="مساحة تعريف المؤسسات على مستوى المنصة."
        >
            <x-quality-ui.empty-state
                title="لا توجد مؤسسات للعرض"
                description="لا تتضمن هذه المعاينة سجلات مؤسسات أو بيانات عملاء."
            />
        </x-quality-ui.section-card>

        <x-quality-ui.section-card
            title="التهيئة العامة"
            description="مساحة التهيئة العامة لبيئة المنصة."
        >
            <x-quality-ui.empty-state
                title="لا توجد إعدادات معروضة"
                description="لا تتوفر إعدادات فعلية أو خيارات قابلة للتغيير في هذه المعاينة."
            />
        </x-quality-ui.section-card>

        <x-quality-ui.section-card
            title="المتابعة التشغيلية"
            description="مساحة معلوماتية لمتابعة الجوانب التشغيلية العامة."
        >
            <x-quality-ui.empty-state
                title="لا توجد بيانات متابعة"
                description="لا تتوفر سجلات أو مؤشرات تشغيلية في هذه المعاينة."
            />
        </x-quality-ui.section-card>

    </div>

</div>
