<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\UiPreview\ScreenCatalog;
use App\Filament\UiPreview\UiPreviewPage;
use App\Models\User;
use Tests\TestCase;

class FilamentUiPreviewTest extends TestCase
{
    public function test_screen_catalog_contains_twenty_four_screens(): void
    {
        $this->assertSame(24, ScreenCatalog::count());
        $this->assertCount(24, ScreenCatalog::all());
        $this->assertCount(24, ScreenCatalog::slugs());
    }

    public function test_screen_catalog_contains_six_groups(): void
    {
        $this->assertCount(6, ScreenCatalog::grouped());
    }

    public function test_screen_catalog_slugs_are_unique(): void
    {
        $slugs = ScreenCatalog::slugs();

        $this->assertCount(
            count($slugs),
            array_unique($slugs),
        );
    }

    public function test_preview_access_is_limited_to_local_and_testing_environments(): void
    {
        try {
            foreach ([
                'local' => true,
                'testing' => true,
                'production' => false,
            ] as $environment => $expectedAccess) {
                app()->detectEnvironment(
                    fn (): string => $environment,
                );

                $this->assertSame(
                    $expectedAccess,
                    UiPreviewPage::canAccess(),
                    "Unexpected preview access for [{$environment}] environment.",
                );
            }
        } finally {
            app()->detectEnvironment(
                fn (): string => 'testing',
            );
        }
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/ui-preview')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_view_preview_gallery(): void
    {
        $originalAppEnv = config('app.env');

        try {
            config(['app.env' => 'local']);

            $user = User::factory()->make();

            $this->actingAs($user, 'web')
                ->get('/admin/ui-preview')
                ->assertOk()
                ->assertSee('معاينة تصميمية — لا توجد بيانات أو وظائف مفعّلة')
                ->assertSee('معرض الواجهات الإدارية')
                ->assertSee('نظرة عامة على المنصة')
                ->assertSee('سجل النشاط');
        } finally {
            config(['app.env' => $originalAppEnv]);
        }
    }
}
