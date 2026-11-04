@props([
    'filters' => [],
])

<div
    {{ $attributes->class([
        'flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4',
        'dark:border-gray-700 dark:bg-gray-900',
        'sm:flex-row sm:flex-wrap sm:items-end',
    ]) }}
    dir="rtl"
>
    @foreach ($filters as $filter)
        <label class="flex min-w-0 flex-1 flex-col gap-2 sm:min-w-40">
            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ $filter['label'] ?? 'عامل تصفية' }}
            </span>

            <input
                type="text"
                value="{{ $filter['value'] ?? '' }}"
                placeholder="{{ $filter['placeholder'] ?? 'غير متاح في المعاينة' }}"
                disabled
                aria-disabled="true"
                class="w-full min-h-10 rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-gray-500 placeholder:text-gray-400 disabled:cursor-not-allowed dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:placeholder:text-gray-500"
            />
        </label>
    @endforeach

    <p class="w-full text-xs leading-5 text-gray-500 dark:text-gray-400">
        عناصر التصفية للعرض التصميمي فقط، ولا تطبق أي عمليات بحث أو تصفية فعلية.
    </p>
</div>
