<?php

namespace App\Providers\Filament;

use App\Filament\Institution\InstitutionPanel;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class InstitutionPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return InstitutionPanel::make($panel);
    }

    public function boot(): void
    {
        // Filament 5.11's Arabic catalogue lacks this screen-reader hint.
        // Load the vendor group first to preserve all its existing translations.
        $translator = app('translator');
        $translator->load('filament-forms', 'components', 'ar');
        $translator->addLines(['components.select.required_description' => 'اختر خيارًا.'], 'ar', 'filament-forms');

        // Keep domain409; return only safe Arabic feedback for this panel,
        // including Livewire updates with local APP_DEBUG=true. No auto-retry.
        app(ExceptionHandler::class)->renderable(function (ConflictHttpException $error) {
            if (Filament::getCurrentPanel()?->getId() === 'institution') {
                return response()->json(['message' => __('approach_workspace.conflict')], 409, ['Cache-Control' => 'no-store, private']);
            }

            return null;
        });
    }
}
