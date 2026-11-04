@props([
    'items' => [],
])

<div
    {{ $attributes->class([
        'w-full overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700',
    ]) }}
    dir="rtl"
>
    <table class="w-full min-w-max divide-y divide-gray-200 text-right dark:divide-gray-700">
        <caption class="sr-only">
            سجل المراجعات
        </caption>

        <thead class="bg-gray-50 dark:bg-gray-800/70">
            <tr>
                <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    العنصر المستهدف
                </th>

                <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    الإصدار
                </th>

                <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    المراجع
                </th>

                <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    الوقت
                </th>
            </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            @forelse ($items as $item)
                <tr>
                    <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">
                        {{ $item['target'] ?? 'غير متوفر' }}
                    </td>

                    <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">
                        {{ $item['version'] ?? 'غير متوفر' }}
                    </td>

                    <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">
                        {{ $item['reviewer'] ?? 'غير متوفر' }}
                    </td>

                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                        {{ $item['time'] ?? 'غير متوفر' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="4"
                        class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                    >
                        لا يوجد سجل مراجعات للعرض في هذه المعاينة.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
