<?php

declare(strict_types=1);

namespace App\Filament\UiPreview;

use Filament\Pages\Page;

final class UiPreviewPage extends Page
{
    protected static ?string $slug = 'ui-preview';

    protected static ?string $title = 'معرض معاينة الواجهات';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.ui-preview.gallery';

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
     * Provide the screen catalog to the preview view.
     *
     * @return array{
     *     screens: array,
     *     groups: array,
     *     totalScreens: int
     * }
     */
    protected function getViewData(): array
    {
        return [
            'screens' => ScreenCatalog::all(),
            'groups' => ScreenCatalog::grouped(),
            'totalScreens' => ScreenCatalog::count(),
        ];
    }
}
