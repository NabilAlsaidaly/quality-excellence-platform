@props([
    'tabs' => [],
    'active' => null,
])

@php
    $validTabs = collect($tabs)
        ->filter(function ($tab): bool {
            if (! is_array($tab)) {
                return false;
            }

            $key = $tab['key'] ?? null;
            $label = $tab['label'] ?? null;

            return is_string($key)
                && \App\Filament\UiPreview\ScreenCatalog::has($key)
                && is_string($label)
                && trim($label) !== '';
        })
        ->values();

    $activeTab = is_string($active)
        && \App\Filament\UiPreview\ScreenCatalog::has($active)
            ? $active
            : null;
@endphp

<div {{ $attributes->class(['space-y-4']) }}>
    @if ($validTabs->isNotEmpty())
        <nav
            aria-label="التنقل بين شاشات المعاينة"
            class="border-b border-gray-200 dark:border-gray-700"
        >
            <ul class="flex flex-wrap gap-1">
                @foreach ($validTabs as $tab)
                    @php
                        $isActive = $tab['key'] === $activeTab;
                    @endphp

                    <li>
                        <a
                            href="{{ request()->fullUrlWithQuery([
                                'screen' => $tab['key'],
                            ]) }}"
                            @if ($isActive)
                                aria-current="page"
                            @endif
                            class="{{ $isActive
                                ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:text-gray-200' }}
                                inline-flex min-h-11 items-center border-b-2 px-4 py-2.5 text-sm font-medium transition-colors
                                focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                        >
                            {{ $tab['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <div>
        {{ $slot }}
    </div>
</div>
