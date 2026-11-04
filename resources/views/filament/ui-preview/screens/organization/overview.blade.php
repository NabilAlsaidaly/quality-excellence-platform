<div class="space-y-6" dir="rtl">

    {{-- Organization identity --}}
    <x-quality-ui.section-card
        title="اسم المؤسسة"
        description="المساحة الرئيسية لعرض هوية المؤسسة ضمن معاينة الواجهة."
    >
        <div class="space-y-2">
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-300">
                يظهر هنا اسم المؤسسة عند توفر بيانات مؤسسية معتمدة.
            </p>

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                هذه معاينة للواجهة فقط، ولا تعرض اسم مؤسسة أو بيانات فعلية.
            </p>
        </div>
    </x-quality-ui.section-card>

    {{-- Main organization workspaces --}}
    <x-quality-ui.section-card
        title="المساحات الرئيسية"
        description="روابط للانتقال بين نماذج عرض أقسام المنصة. جميع الروابط تفتح شاشات المعاينة فقط."
    >
        <div class="space-y-4">
            <x-quality-ui.tabs
                :tabs="[
                    [
                        'key' => 'organization.overview',
                        'label' => 'الرئيسية المؤسسية',
                    ],
                    [
                        'key' => 'organization.units',
                        'label' => 'الوحدات التنظيمية',
                    ],
                    [
                        'key' => 'organization.users',
                        'label' => 'المستخدمون والعضويات',
                    ],
                    [
                        'key' => 'organization.permissions',
                        'label' => 'معلومات الصلاحيات',
                    ],
                    [
                        'key' => 'approaches.index',
                        'label' => 'بنك المنهجيات',
                    ],
                    [
                        'key' => 'documents.index',
                        'label' => 'الوثائق والأدلة',
                    ],
                    [
                        'key' => 'reviews.inbox',
                        'label' => 'المراجعات',
                    ],
                ]"
                active="organization.overview"
            />

            <p class="text-xs leading-6 text-gray-500 dark:text-gray-400">
                التنقل السابق مخصص لاستعراض قوالب الواجهات داخل المعرض،
                ولا يمثل تفويضًا أو صلاحية فعلية للوصول إلى أقسام المؤسسة.
            </p>
        </div>
    </x-quality-ui.section-card>

    {{-- Assigned work --}}
    <x-quality-ui.section-card
        title="الأعمال المسندة"
        description="مساحة معلوماتية مخصصة لعرض الأعمال المرتبطة بسياق المستخدم عند توفر التكامل المعتمد."
    >
        <x-quality-ui.empty-state
            title="لا تتوفر بيانات للأعمال المسندة"
            description="لا تتضمن هذه المعاينة مهامًا أو إسنادات فعلية للمستخدم."
        />
    </x-quality-ui.section-card>

</div>
