<?php

declare(strict_types=1);

namespace App\Filament\UiPreview;

use Filament\Pages\Page;
use Livewire\Attributes\Url;

final class UiPreviewPage extends Page
{
    protected static ?string $slug = 'ui-preview';

    protected static ?string $title = 'معرض معاينة الواجهات';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.ui-preview.gallery';

    /**
     * The currently selected preview screen.
     *
     * The value is always validated against ScreenCatalog
     * before it is used by the gallery.
     */
    #[Url]
    public mixed $screen = null;

    /**
     * Determine whether the preview page is accessible.
     *
     * The existing Filament panel authentication middleware
     * remains responsible for authenticating users.
     */
    public static function canAccess(): bool
    {
        return app()->environment('local', 'testing');
    }

    /**
     * Provide the screen catalog and selected screen
     * to the preview gallery.
     *
     * @return array{
     *     screens: array,
     *     groups: array,
     *     totalScreens: int,
     *     selectedScreen: array|null
     * }
     */
    protected function getViewData(): array
    {
        $selectedScreen = null;

        if ($this->screen !== null) {
            if (! is_string($this->screen)) {
                abort(404);
            }

            $selectedScreen = ScreenCatalog::find($this->screen);

            if ($selectedScreen === null) {
                abort(404);
            }
        }

        return [
            'screens' => ScreenCatalog::all(),
            'groups' => ScreenCatalog::grouped(),
            'totalScreens' => ScreenCatalog::count(),
            'selectedScreen' => $selectedScreen,
        ];
    }
}
