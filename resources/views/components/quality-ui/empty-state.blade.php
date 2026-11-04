@props([
    'title' => 'لا توجد بيانات للعرض',
    'description' => 'هذه حالة توضيحية ضمن معاينة التصميم، ولا توجد بيانات فعلية أو وظائف مفعّلة.',
])

<div
    {{ $attributes->class([
        'flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center',
        'dark:border-gray-700',
    ]) }}
    role="status"
>
    <div
        class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"
        aria-hidden="true"
    >
        <x-heroicon-o-inbox class="size-6" />
    </div>

    <h3 class="mt-4 text-sm font-semibold text-gray-950 dark:text-white">
        {{ $title }}
    </h3>

    @if ($description)
        <p class="mt-1 max-w-md text-sm leading-6 text-gray-600 dark:text-gray-400">
            {{ $description }}
        </p>
    @endif
</div>
