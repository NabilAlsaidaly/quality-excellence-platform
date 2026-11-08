<?php

namespace App\Providers\Filament;

use App\Filament\Institution\InstitutionPanel;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // Keep domain status codes; return only safe Arabic feedback for this panel,
        // including Livewire updates with local APP_DEBUG=true. No auto-retry.
        app(ExceptionHandler::class)->renderable(function (HttpExceptionInterface|CannotUpdateLockedPropertyException $error, Request $request) {
            if (Filament::getCurrentPanel()?->getId() !== 'institution') {
                return null;
            }
            $status = $error instanceof CannotUpdateLockedPropertyException ? 403 : $error->getStatusCode();
            $message = match ($status) {
                403 => 'الوصول غير مسموح.',
                404 => 'غير متاح.',
                409 => __('approach_workspace.conflict'),
                default => null,
            };
            if ($message === null) {
                return null;
            }
            $headers = ['Cache-Control' => 'no-store, private'];

            return $request->expectsJson() || $status === 409
                ? response()->json(['message' => $message], $status, $headers)
                : response($message, $status, $headers);
        });
    }
}
