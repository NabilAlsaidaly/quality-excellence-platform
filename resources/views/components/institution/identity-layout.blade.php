@props(['title', 'showErrors' => false, 'showStatus' => false, 'validationErrors' => null])

@php
    // Read visual configuration only; never boot a panel or change its color registry.
    $panel = \Filament\Facades\Filament::getPanel('institution');
    $visualVariables = collect($panel->getColors()['primary'])
        ->map(fn ($color, $shade) => "--primary-{$shade}: {$color}")->implode('; ');
    $visualVariables .= '; --font-family: "'.$panel->getFontFamily().'"';
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp

<!doctype html>
<html lang="ar" dir="rtl" class="fi" style="{{ $visualVariables }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    @filamentStyles
    @vite('resources/css/filament/admin/theme.css')
    <script @if (filled($cspNonce)) nonce="{{ $cspNonce }}" @endif>
        // Reuse the existing Filament visual preference, never authentication state.
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('filament:theme:mode') ?? 'system'; } catch {}
            document.documentElement.classList.toggle('dark', mode === 'dark' ||
                (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches));
        })();
    </script>
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-950 antialiased dark:bg-gray-950 dark:text-white">
    <main class="mx-auto w-full max-w-2xl space-y-8 px-4 py-8 sm:px-8 sm:py-12">
        <header class="space-y-3 text-center">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $panel->getBrandName() }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h1>
        </header>
        @if ($showStatus && session('status'))
            <p role="status" class="rounded-xl bg-success-50 p-4 text-sm leading-7 text-success-800 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300">{{ session('status') }}</p>
        @endif
        @if ($showErrors && $validationErrors?->any())
            <div role="alert" class="rounded-xl bg-danger-50 p-4 text-sm leading-7 text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300">{{ $validationErrors->first() }}</div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
