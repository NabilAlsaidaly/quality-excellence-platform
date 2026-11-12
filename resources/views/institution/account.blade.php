<x-institution.identity-layout title="الحساب المؤسسي">
    <x-filament::fieldset label="حسابك" class="bg-white dark:bg-gray-900">
        <div class="space-y-6">
            <p class="break-words text-lg font-medium">أهلًا {{ $account->name }}. تم التحقق من حسابك وسياق المؤسسة.</p>
            <p class="text-sm leading-7 text-gray-600 dark:text-gray-400">هذه صفحة الحساب فقط. لا تمنح الوصول إلى محتوى المؤسسة أو صلاحيات إدارتها.</p>
            @if ($account->canAccessPanel(\Filament\Facades\Filament::getPanel('institution')))
                <x-filament::button :color="\Filament\Facades\Filament::getPanel('institution')->getColors()['primary']" tag="a" :href="route('filament.institution.pages.institution-dashboard', [], false)" class="min-h-11 w-full dark:bg-primary-700 dark:hover:bg-primary-800">فتح مساحة المؤسسة</x-filament::button>
            @endif
            @isset ($localDemoWorkspaceUrl)
                <x-filament::button tag="a" :href="$localDemoWorkspaceUrl" color="gray" outlined class="min-h-11 w-full">فتح العرض المتكامل</x-filament::button>
            @endisset
            <form method="post" action="{{ route('institution.logout', ['organization' => request()->route('organization')], false) }}">
                @csrf
                <x-filament::button type="submit" color="gray" outlined class="min-h-11 w-full">تسجيل الخروج</x-filament::button>
            </form>
        </div>
    </x-filament::fieldset>
</x-institution.identity-layout>
