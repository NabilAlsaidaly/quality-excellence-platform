@props([
    'title' => null,
    'description' => null,
    'crumbs' => [],
])

<header
    {{ $attributes->class([
        'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between',
    ]) }}
>
    <div class="min-w-0 space-y-2">
        @if (!empty($crumbs))
            <nav aria-label="مسار التنقل">
                <ol class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($crumbs as $crumb)
                        @if (!$loop->first)
                            <li aria-hidden="true">/</li>
                        @endif

                        <li @if ($loop->last) aria-current="page" @endif>
                            {{ $crumb }}
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        @if ($title)
            <h1 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-2xl">
                {{ $title }}
            </h1>
        @endif

        @if ($description)
            <p class="max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                {{ $description }}
            </p>
        @endif
    </div>

    <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
        <span class="size-2 rounded-full bg-gray-400 dark:bg-gray-500"></span>
        معاينة تصميمية
    </span>
</header>
