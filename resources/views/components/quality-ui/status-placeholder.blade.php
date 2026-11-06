@props([
    'label',
    'tone' => 'neutral',
])

@php
    $toneClasses = [
        'neutral' => 'border-gray-200 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ];

    $classes = $toneClasses[$tone] ?? $toneClasses['neutral'];
@endphp

<span
    {{ $attributes->class([
        'inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium',
        $classes,
    ]) }}
>
    <span
        class="size-1.5 shrink-0 rounded-full bg-current opacity-60"
        aria-hidden="true"
    ></span>

    {{ $label }}
</span>
