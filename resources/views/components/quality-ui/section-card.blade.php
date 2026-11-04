@props([
    'title',
    'description' => null,
])

<section {{ $attributes->class([
    'overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm',
    'dark:border-gray-700 dark:bg-gray-900',
]) }}>
    <header class="space-y-1 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ $title }}
        </h2>

        @if ($description)
            <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">
                {{ $description }}
            </p>
        @endif
    </header>

    <div class="p-5">
        {{ $slot }}
    </div>
</section>
