<p class="mb-5 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">عرض تصوري للتسلسل الزمني لإصدارات الوثيقة، مع توضيح معلومات كل إصدار والاحتفاظ بالسجل التاريخي.</p>

<div class="space-y-6">

    <x-quality-ui.info-alert
        variant="info"
        message="هذه الشاشة للمعاينة التصميمية فقط. لا توجد إصدارات فعلية أو تواريخ أو أسماء ملاك مسجلة."
    />

    <x-quality-ui.section-card
        title="السجل الزمني للإصدارات"
        description="يعرض هذا القسم التسلسل التاريخي لإصدارات الوثيقة، دون استبدال هوية الوثيقة نفسها."
    >
        <x-quality-ui.version-timeline
            title="تاريخ إصدارات الوثيقة"
            :items="[]"
        />

        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">

            <x-quality-ui.detail-pair
                label="رقم الإصدار"
                value="موضع عرض رقم الإصدار — لا توجد قيمة فعلية"
            />

            <x-quality-ui.detail-pair
                label="وقت الإضافة"
                value="موضع عرض الوقت والتاريخ — لا توجد بيانات فعلية"
            />

            <x-quality-ui.detail-pair
                label="مالك الإصدار"
                value="موضع عرض المالك — لا توجد بيانات فعلية"
            />

            <x-quality-ui.detail-pair
                label="حالة الإصدار"
                value="موضع عرض الحالة — لا توجد بيانات مسجلة"
            />

            <x-quality-ui.detail-pair
                label="الروابط المرجعية"
                value="موضع عرض الارتباطات — لا توجد روابط مسجلة"
            />

        </div>

        <div class="mt-4">
            <x-quality-ui.empty-state
                title="لا توجد إصدارات للعرض"
                description="سيظهر سجل الإصدارات هنا عند توفر بيانات فعلية، مع إمكانية عرض الإصدارات السابقة للمستخدم المخول."
            />
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="مبدأ الاحتفاظ بالإصدارات"
        description="توضيح مبدأ إدارة تاريخ الوثيقة دون تنفيذ أي عمليات."
    >
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex items-start gap-3">
                <x-heroicon-o-clock class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />

                <div class="space-y-2">
                    <h3 class="font-semibold text-gray-900 dark:text-white">
                        الإصدارات السابقة محفوظة في السجل
                    </h3>

                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        إنشاء إصدار جديد للوثيقة ينتج إصدارًا مستقلًا ويضيفه إلى تاريخها،
                        ولا يؤدي إلى محو الإصدار السابق أو استبدال سجله التاريخي.
                    </p>
                </div>
            </div>
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="لا تتضمن هذه المعاينة حذف الإصدارات أو استبدالها أو تنزيل ملفات فعلية. كما لا تنفذ عمليات رفع أو حفظ أو استعلام."
    />

</div>
