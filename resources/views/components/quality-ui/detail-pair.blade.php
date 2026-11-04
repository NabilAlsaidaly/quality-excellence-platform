@props([
    'label' => null,
    'value' => null,
])

@php
    $displayValue = $value;

    if (is_null($displayValue) || (is_string($displayValue) && trim($displayValue) === '')) {
        $displayValue = 'غير متوفر';
    }
@endphp

<div {{ $attributes->class([
    'grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-4',
]) }}>
    @if ($label)
        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
            {{ $label }}
        </dt>
    @endif

    <dd class="min-w-0 break-words text-sm text-gray-900 dark:text-white sm:col-span-2">
        {{ $displayValue }}
    </dd>
</div>
