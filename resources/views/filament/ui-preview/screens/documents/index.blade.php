<x-quality-ui.page-header
    title="مكتبة الوثائق"
    description="عرض تصوري لهوية الوثائق وإصداراتها وحالة اعتمادها المؤسسي وروابط الأدلة المرتبطة بها."
/>

<x-quality-ui.info-alert
    variant="info"
    message="هذه المكتبة للمعاينة التصميمية فقط. لا توجد وثائق فعلية أو عمليات رفع أو تنزيل أو اعتماد مفعّلة."
/>

<div class="space-y-6">

    <x-quality-ui.section-card
        title="البحث والتعريف"
        description="عناصر تصفية توضيحية للتعرف على الوثائق. جميع الحقول معطّلة في هذه المعاينة."
    >
        <x-quality-ui.filter-toolbar
            :filters="[
                [
                    'label' => 'اسم الوثيقة أو معرّفها',
                    'placeholder' => 'البحث باسم الوثيقة أو المعرّف',
                ],
                [
                    'label' => 'المالك',
                    'placeholder' => 'اختيار مالك الوثيقة',
                ],
                [
                    'label' => 'حالة الاعتماد المؤسسي',
                    'placeholder' => 'اختيار حالة الاعتماد',
                ],
            ]"
        />
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="الوثائق"
        description="قائمة تصورية تعرض هوية كل وثيقة، ومالكها، وآخر إصدار لها، وحالة اعتمادها المؤسسي، وعدد روابط الأدلة المرتبطة بها."
    >
        <x-quality-ui.table-shell
            :columns="[
                'الهوية',
                'اسم الوثيقة',
                'المالك',
                'آخر إصدار',
                'حالة الاعتماد المؤسسي',
                'عدد روابط الأدلة',
            ]"
        >
            <tr>
                <td colspan="6" class="px-4 py-8">
                    <x-quality-ui.empty-state
                        title="لا توجد وثائق للعرض"
                        description="ستظهر الوثائق هنا عند توفر بيانات فعلية. لا توجد سجلات أو روابط أدلة ضمن المعاينة الحالية."
                    />
                </td>
            </tr>
        </x-quality-ui.table-shell>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="الوثيقة وإصداراتها"
        description="توضيح الفرق بين هوية الوثيقة والإصدارات المستقلة التابعة لها."
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-quality-ui.detail-pair
                label="هوية الوثيقة"
                value="تمثل الوثيقة نفسها بوصفها عنصرًا مستقلًا."
            />

            <x-quality-ui.detail-pair
                label="إصدار الوثيقة"
                value="يمثل نسخة محددة من الوثيقة، مع الاحتفاظ بتاريخها وإصداراتها."
            />
        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.info-alert
        variant="info"
        message="اعتماد الوثيقة مؤسسيًا مفهوم مستقل عن قبول الوثيقة بوصفها دليلًا ضمن أحد المناهج أو المشاركات. لا تنفذ هذه المعاينة أيًا من العمليتين."
    />

</div>
