<x-institution.identity-layout title="تعيين كلمة مرور جديدة" :show-errors="true" :validation-errors="$errors">
    <x-filament::fieldset label="كلمة المرور الجديدة" class="bg-white dark:bg-gray-900">
        <p id="reset-description" class="mb-6 text-sm leading-7 text-gray-600 dark:text-gray-400">الرابط خاص بالحساب والمؤسسة المحددين في طلب الاستعادة.</p>
        <form method="post" action="{{ request()->getRequestUri() }}" aria-describedby="reset-description" class="space-y-5">
            @csrf
            <div class="space-y-2">
                <label for="password" class="text-sm font-medium">كلمة المرور الجديدة</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-2">
                <label for="confirmation" class="text-sm font-medium">تأكيد كلمة المرور</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" class="min-h-11" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button :color="\Filament\Facades\Filament::getPanel('institution')->getColors()['primary']" type="submit" class="min-h-11 w-full dark:bg-primary-700 dark:hover:bg-primary-800">حفظ كلمة المرور</x-filament::button>
        </form>
    </x-filament::fieldset>
</x-institution.identity-layout>
