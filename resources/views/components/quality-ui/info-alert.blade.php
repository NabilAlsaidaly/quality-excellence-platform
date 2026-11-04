@props([
    'variant' => 'info',
    'message',
])

@php
    $variants = [
        'info' => [
            'container' => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40',
            'text' => 'text-sky-800 dark:text-sky-300',
            'icon' => 'heroicon-o-information-circle',
            'role' => 'status',
        ],
        'warning' => [
            'container' => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40',
            'text' => 'text-amber-900 dark:text-amber-200',
            'icon' => 'heroicon-o-exclamation-triangle',
            'role' => 'alert',
        ],
        'preview' => [
            'container' => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40',
            'text' => 'text-amber-900 dark:text-amber-200',
            'icon' => 'heroicon-o-eye',
            'role' => 'status',
        ],
    ];

    $presentation = $variants[$variant] ?? $variants['info'];
@endphp

<div
    {{ $attributes->class([
        'flex items-start gap-3 rounded-lg border p-4',
        $presentation['container'],
        $presentation['text'],
    ]) }}
    role="{{ $presentation['role'] }}"
>
    <x-filament::icon
        :icon="$presentation['icon']"
        class="mt-0.5 size-5 shrink-0"
        aria-hidden="true"
    />

    <p class="min-w-0 text-sm leading-6">
        {{ $message }}
    </p>
</div>
