@props([
    'columns',
    'caption' => null,
])

<div
    {{ $attributes->class([
        'w-full overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700',
    ]) }}
    dir="rtl"
>
    <table class="w-full min-w-max divide-y divide-gray-200 text-right dark:divide-gray-700">
        <caption class="sr-only">
            {{ $caption ?: 'جدول معاينة' }}
        </caption>

        <thead class="bg-gray-50 dark:bg-gray-800/70">
            <tr>
                @foreach ($columns as $column)
                    <th
                        scope="col"
                        class="whitespace-nowrap px-5 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300"
                    >
                        {{ $column }}
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            @if ($slot->isEmpty())
                <tr>
                    <td
                        colspan="{{ max(1, count($columns)) }}"
                        class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                    >
                        لا توجد سجلات للعرض في هذه المعاينة.
                    </td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>
