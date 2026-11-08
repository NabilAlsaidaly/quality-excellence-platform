<div lang="ar" dir="rtl" class="qep-preview mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
    <h1 class="qep-primary text-2xl font-bold">{{ __('institution_documents.title', [], 'ar') }}</h1>
    <x-filament::section>
        <p role="status">{{ __('institution_documents.disabled', [], 'ar') }}</p>
        @if ($synthetic ?? false)
            <p>{{ __('institution_documents.synthetic', [], 'ar') }}</p>
        @endif
    </x-filament::section>
    @if ($forbidden ?? true)
        <x-filament::section>
            <p role="alert">{{ __('institution_documents.forbidden', [], 'ar') }}</p>
        </x-filament::section>
    @elseif ($error ?? false)
        <x-filament::section>
            <p role="alert">{{ __('institution_documents.error', [], 'ar') }}</p>
        </x-filament::section>
    @else
        @if (($mode ?? 'list') === 'details')
            {{ $this->details }}
        @elseif (($mode ?? 'list') === 'form')
            {{ $this->form }}
        @else
            <div role="region" aria-label="قائمة الوثائق والإصدارات">
                {{ $this->table }}
            </div>
        @endif
    @endif
    @include('filament.documents.upload-disabled')
</div>
