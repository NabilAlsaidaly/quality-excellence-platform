
<x-filament-panels::page>
    <div dir="rtl" class="space-y-8">

        {{-- Preview notice --}}
        <div class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
            <x-filament::icon
                icon="heroicon-o-information-circle"
                class="mt-0.5 h-5 w-5 shrink-0"
            />

            <div class="space-y-1">
                <p class="font-semibold">
                    معاينة تصميمية — لا توجد بيانات أو وظائف مفعّلة
                </p>

                <p class="text-sm opacity-80">
                    هذه الواجهات نماذج بصرية أولية لأغراض مراجعة التصميم
                    وتقييم تجربة الاستخدام فقط.
                </p>
            </div>
        </div>

        {{-- Gallery summary --}}
        <section class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                        معرض الواجهات الإدارية
                    </h2>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        استعراض تصوري للشاشات المقترحة للمنصة.
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                        {{ $totalScreens }}
                    </span>

                    <span class="mr-1 text-sm text-gray-500 dark:text-gray-400">
                        شاشة تصورية
                    </span>
                </div>
            </div>
        </section>

        {{-- Screen groups --}}
        <div class="space-y-8">
            @foreach ($groups as $groupKey => $group)
                <section
                    id="group-{{ $groupKey }}"
                    class="space-y-4"
                >
                    <div class="flex items-center gap-3">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">
                            {{ $group['label'] }}
                        </h3>

                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ count($group['screens']) }}
                        </span>

                        <div class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($group['screens'] as $screen)
                            <article
                                class="group rounded-xl border border-gray-200 bg-white p-5 transition-colors dark:border-gray-700 dark:bg-gray-900"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 space-y-2">
                                        <h4 class="font-semibold text-gray-900 dark:text-white">
                                            {{ $screen['title'] }}
                                        </h4>

                                        <p class="break-all text-xs text-gray-500 dark:text-gray-400">
                                            {{ $screen['slug'] }}
                                        </p>
                                    </div>

                                    <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        تصميم
                                    </span>
                                </div>

                                <div class="mt-5 border-t border-gray-100 pt-3 dark:border-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        نموذج واجهة قيد التجهيز
                                    </p>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

    </div>
</x-filament-panels::page>
