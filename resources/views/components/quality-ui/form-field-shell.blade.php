@props([
    'label' => null,
    'hint' => null,
    'kind' => 'input',
])

@php
    $controlId = 'quality-field-' . \Illuminate\Support\Str::uuid();
    $hintId = $controlId . '-hint';
@endphp

<div
    {{ $attributes->class(['space-y-2']) }}
    dir="rtl"
>
    @if ($label)
        <label
            for="{{ $controlId }}"
            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
        >
            {{ $label }}
        </label>
    @endif

    @switch($kind)
        @case('textarea')
            <textarea
                id="{{ $controlId }}"
                rows="3"
                disabled
                aria-disabled="true"
                @if ($hint) aria-describedby="{{ $hintId }}" @endif
                @if (!$label) aria-label="حقل نصي توضيحي" @endif
                placeholder="غير متاح في المعاينة"
                class="block w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-gray-500 placeholder:text-gray-400 disabled:cursor-not-allowed dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:placeholder:text-gray-500"
            ></textarea>
            @break

        @case('select')
            <select
                id="{{ $controlId }}"
                disabled
                aria-disabled="true"
                @if ($hint) aria-describedby="{{ $hintId }}" @endif
                @if (!$label) aria-label="قائمة اختيار توضيحية" @endif
                class="block min-h-10 w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-gray-500 disabled:cursor-not-allowed dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
            >
                <option selected>غير متاح في المعاينة</option>
            </select>
            @break

        @default
            <input
                id="{{ $controlId }}"
                type="text"
                disabled
                aria-disabled="true"
                @if ($hint) aria-describedby="{{ $hintId }}" @endif
                @if (!$label) aria-label="حقل إدخال توضيحي" @endif
                placeholder="غير متاح في المعاينة"
                class="block min-h-10 w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-gray-500 placeholder:text-gray-400 disabled:cursor-not-allowed dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:placeholder:text-gray-500"
            />
    @endswitch

    @if ($hint)
        <p
            id="{{ $hintId }}"
            class="text-xs leading-5 text-gray-500 dark:text-gray-400"
        >
            {{ $hint }}
        </p>
    @endif
</div>
