<x-institution.identity-layout title="دخول الحساب المؤسسي" :show-errors="true" :validation-errors="$errors" :show-status="true">
    <p class="text-sm leading-7 text-gray-600 dark:text-gray-400">بيئة اختبار: الدخول لا يمنح صلاحيات إدارة أو وصولًا إلى وثائق المؤسسة.</p>
    <x-filament::fieldset label="تسجيل الدخول" class="bg-white dark:bg-gray-900">
        <p id="login-description" class="mb-6 text-sm leading-7 text-gray-600 dark:text-gray-400">أدخل معرّف المؤسسة الذي استلمته من الجهة المخولة. لا تتوفر قائمة عامة بالمؤسسات.</p>
        <form method="post" action="{{ route('institution.login', [], false) }}" aria-describedby="login-description" class="space-y-5">
            @csrf
            <div class="space-y-2">
                <label for="organization" class="text-sm font-medium">معرّف المؤسسة</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="organization" name="organization" :value="old('organization')" required maxlength="128" dir="ltr" autocomplete="organization" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-2">
                <label for="email" class="text-sm font-medium">البريد المسجل</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="email" name="email" type="email" :value="old('email')" required maxlength="254" dir="ltr" autocomplete="username" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-2">
                <label for="password" class="text-sm font-medium">كلمة المرور</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="password" name="password" type="password" required autocomplete="current-password" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button :color="\Filament\Facades\Filament::getPanel('institution')->getColors()['primary']" type="submit" class="min-h-11 w-full dark:bg-primary-700 dark:hover:bg-primary-800">تسجيل الدخول</x-filament::button>
        </form>
    </x-filament::fieldset>
    <x-filament::fieldset label="استعادة الوصول" class="bg-white dark:bg-gray-900">
        <p id="recovery-description" class="mb-6 text-sm leading-7 text-gray-600 dark:text-gray-400">حدد المؤسسة والبريد للحساب الذي تريد استعادته.</p>
        <form method="post" action="{{ route('institution.recovery', [], false) }}" aria-describedby="recovery-description" class="space-y-5">
            @csrf
            <div class="space-y-2">
                <label for="recovery-organization" class="text-sm font-medium">معرّف المؤسسة</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="recovery-organization" name="organization" required maxlength="128" dir="ltr" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-2">
                <label for="recovery-email" class="text-sm font-medium">البريد المسجل</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="recovery-email" name="email" type="email" required maxlength="254" dir="ltr" autocomplete="email" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button type="submit" color="gray" outlined class="min-h-11 w-full">طلب تعليمات الاستعادة</x-filament::button>
        </form>
    </x-filament::fieldset>
</x-institution.identity-layout>
