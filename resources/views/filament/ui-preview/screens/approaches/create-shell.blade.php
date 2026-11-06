<div class="space-y-6" dir="rtl">

    <x-quality-ui.info-alert
        variant="warning"
        message="هذه واجهة تصميمية فقط. تفاصيل حقول المنهجية الإلزامية والمرنة تنتظر اعتماد القرار D-005."
    />

    <x-quality-ui.section-card
        title="بيانات المنهجية الأساسية"
        description="نموذج تصوري لعرض المعلومات الأساسية اللازمة لتعريف منهجية مؤسسية."
    >
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

            <x-quality-ui.form-field-shell
                label="اسم المنهجية"
                hint="الاسم التعريفي للمنهجية"
                kind="input"
            />

            <x-quality-ui.form-field-shell
                label="الجهة المالكة الرئيسية"
                hint="موضع تحديد الوحدة التنظيمية المالكة الرئيسية"
                kind="select"
            />

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="وصف المنهجية"
                    hint="وصف موجز لطبيعة المنهجية وطريقة العمل التي تنظمها"
                    kind="textarea"
                />
            </div>

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="الغاية"
                    hint="الغرض الذي تسعى المنهجية إلى تحقيقه"
                    kind="textarea"
                />
            </div>

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="النطاق"
                    hint="حدود تطبيق المنهجية داخل المؤسسة"
                    kind="textarea"
                />
            </div>

            <div class="md:col-span-2">
                <x-quality-ui.form-field-shell
                    label="الأهداف"
                    hint="الأهداف المرتبطة بالمنهجية"
                    kind="textarea"
                />
            </div>

            <x-quality-ui.form-field-shell
                label="دورية المراجعة"
                hint="موضع تحديد دورية مراجعة المنهجية"
                kind="select"
            />

        </div>
    </x-quality-ui.section-card>

    <x-quality-ui.section-card
        title="ملاحظات التصميم"
        description="توضيح الحدود الحالية للنموذج المقترح."
    >
        <div class="space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <p>
                تختلف طبيعة محتوى المنهجيات وحقولها التفصيلية بحسب طريقة العمل
                التي تنظمها، ولا يُفترض أن تستخدم جميع المنهجيات نموذجًا
                تفصيليًا واحدًا.
            </p>

            <p>
                تحديد الحقول الإلزامية والمرنة وقوالب المحتوى ينتظر اعتماد
                القرار D-005.
            </p>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                جميع الحقول معطّلة، ولا يوجد إرسال أو تحقق أو حفظ أو إنشاء
                لسجل منهجية في هذه المعاينة.
            </p>
        </div>
    </x-quality-ui.section-card>

</div>
