@props([
    'current' => null,
])

@php
    $stages = [
        'التوجه',
        'النظام',
        'التنفيذ',
        'القياس',
        'التحسين والتطوير',
    ];

    $currentStage = filter_var($current, FILTER_VALIDATE_INT);

    if ($currentStage === false || $currentStage < 1 || $currentStage > 5) {
        $currentStage = null;
    }
@endphp

<div {{ $attributes->class(['space-y-4']) }} dir="rtl">
    <ol
        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-2"
        aria-label="المراحل الخمس للمنهجية"
    >
        @foreach ($stages as $index => $stage)
            @php
                $stageNumber = $index + 1;
                $isCurrent = $currentStage === $stageNumber;
            @endphp

            <li
                class="flex min-w-0 flex-1 items-start gap-3 sm:flex-col sm:gap-3"
                @if ($isCurrent) aria-current="step" @endif
            >
                <span
                    @class([
                        'flex size-9 shrink-0 items-center justify-center rounded-full border text-sm font-semibold',
                        'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950/40 dark:text-primary-300' => $isCurrent,
                        'border-gray-300 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => !$isCurrent,
                    ])
                    aria-hidden="true"
                >
                    {{ $stageNumber }}
                </span>

                <div class="min-w-0 space-y-1 pt-1 sm:pt-0">
                    <p class="text-sm font-medium leading-5 text-gray-800 dark:text-gray-200">
                        {{ $stage }}
                    </p>

                    @if ($isCurrent)
                        <span class="text-xs text-primary-700 dark:text-primary-300">
                            المرحلة الحالية
                        </span>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
        ترتيب توضيحي للمراحل فقط، ولا يعكس حالة تنفيذ فعلية أو درجة تقييم أو مستوى إنجاز.
    </p>
</div>
