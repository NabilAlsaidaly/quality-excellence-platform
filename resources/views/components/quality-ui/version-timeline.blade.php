@props([
    'title',
    'items' => [],
])

<section
    {{ $attributes->class(['space-y-5']) }}
    dir="rtl"
>
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
        {{ $title }}
    </h3>

    @forelse ($items as $item)
        @if ($loop->first)
            <ol class="relative space-y-6 border-r border-gray-200 pr-6 dark:border-gray-700">
        @endif

                <li class="relative">
                    <span
                        class="absolute -right-[29px] top-1 flex size-3 items-center justify-center rounded-full border-2 border-white bg-gray-400 dark:border-gray-900 dark:bg-gray-500"
                        aria-hidden="true"
                    ></span>

                    <div class="space-y-1">
                        @if (!empty($item['number']))
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $item['number'] }}
                            </p>
                        @endif

                        @if (!empty($item['date']))
                            <time class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ $item['date'] }}
                            </time>
                        @endif

                        @if (!empty($item['description']))
                            <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">
                                {{ $item['description'] }}
                            </p>
                        @endif
                    </div>
                </li>

        @if ($loop->last)
            </ol>
        @endif
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                لا توجد إصدارات للعرض
            </p>

            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                سجل الإصدارات غير متصل ببيانات فعلية في هذه المعاينة.
            </p>
        </div>
    @endforelse
</section>
