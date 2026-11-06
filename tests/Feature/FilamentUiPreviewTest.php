<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\UiPreview\ScreenCatalog;
use App\Filament\UiPreview\UiPreviewPage;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FilamentUiPreviewTest extends TestCase
{
    /**
     * The 24 contracted screens plus the shared states showcase.
     *
     * @var list<string>
     */
    private const EXPECTED_SLUGS = [
        // Platform
        'platform.overview',
        'platform.organizations',
        'platform.organization-details',

        // Organization
        'organization.overview',
        'organization.units',
        'organization.users',
        'organization.permissions',

        // Approaches
        'approaches.index',
        'approaches.create',
        'approaches.summary',
        'approaches.content',
        'approaches.participants',
        'approaches.evidence',
        'approaches.reviews',
        'approaches.maturity',
        'approaches.activity',

        // Documents
        'documents.index',
        'documents.details',
        'documents.versions',
        'documents.upload',

        // Reviews
        'reviews.inbox',
        'reviews.details',

        // Shared screens
        'shared.notifications',
        'shared.activity-log',

        // Shared states showcase (gallery view)
        'shared.states',
    ];

    /**
     * The six contracted navigation groups.
     *
     * @var list<string>
     */
    private const EXPECTED_GROUPS = [
        'platform',
        'organization',
        'approaches',
        'documents',
        'reviews',
        'shared',
    ];

    // -------------------------------------------------------------------------
    // Screen catalog
    // -------------------------------------------------------------------------

    public function test_screen_catalog_contains_twenty_five_entries(): void
    {
        $this->assertSame(25, ScreenCatalog::count());
        $this->assertCount(25, ScreenCatalog::all());
        $this->assertCount(25, ScreenCatalog::slugs());
    }

    public function test_screen_catalog_contains_all_contracted_slugs(): void
    {
        $this->assertEqualsCanonicalizing(
            self::EXPECTED_SLUGS,
            ScreenCatalog::slugs(),
        );
    }

    public function test_catalog_contains_twenty_four_screens_and_one_shared_states_entry(): void
    {
        $slugs = ScreenCatalog::slugs();

        $actualScreenSlugs = array_filter(
            $slugs,
            static fn (string $slug): bool => $slug !== 'shared.states',
        );

        $this->assertCount(24, $actualScreenSlugs);
        $this->assertContains('shared.states', $slugs);
    }

    public function test_screen_catalog_contains_exactly_six_groups(): void
    {
        $this->assertEqualsCanonicalizing(
            self::EXPECTED_GROUPS,
            array_keys(ScreenCatalog::grouped()),
        );

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

    public function test_every_catalog_entry_has_a_valid_view_definition(): void
    {
        foreach (ScreenCatalog::all() as $screen) {
            $this->assertIsArray($screen);

            $this->assertArrayHasKey('slug', $screen);
            $this->assertArrayHasKey('group', $screen);
            $this->assertArrayHasKey('title', $screen);
            $this->assertArrayHasKey('view', $screen);

            $this->assertIsString($screen['slug']);
            $this->assertIsString($screen['group']);
            $this->assertIsString($screen['title']);
            $this->assertIsString($screen['view']);

            $this->assertNotSame('', trim($screen['slug']));
            $this->assertNotSame('', trim($screen['group']));
            $this->assertNotSame('', trim($screen['title']));
            $this->assertNotSame('', trim($screen['view']));

            if ($screen['slug'] === 'shared.states') {
                $this->assertSame(
                    'filament.ui-preview.gallery',
                    $screen['view'],
                );

                continue;
            }

            $this->assertTrue(
                view()->exists($screen['view']),
                "The view [{$screen['view']}] for screen [{$screen['slug']}] does not exist.",
            );
        }
    }

    public function test_shared_states_is_registered_in_the_shared_group(): void
    {
        $screen = ScreenCatalog::find('shared.states');

        $this->assertIsArray($screen);
        $this->assertSame('shared', $screen['group']);

        $this->assertSame(
            'filament.ui-preview.gallery',
            $screen['view'],
        );
    }

    public function test_screen_catalog_returns_null_for_unknown_slugs(): void
    {
        $this->assertFalse(ScreenCatalog::has('unknown.screen'));
        $this->assertNull(ScreenCatalog::find('unknown.screen'));
    }

    // -------------------------------------------------------------------------
    // Environment and access control
    // -------------------------------------------------------------------------

    public function test_preview_access_is_limited_to_local_and_testing_environments(): void
    {
        $originalEnvironment = app()->environment();

        try {
            foreach ([
                'local' => true,
                'testing' => true,
                'production' => false,
            ] as $environment => $expectedAccess) {
                app()->detectEnvironment(
                    static fn (): string => $environment,
                );

                $this->assertSame(
                    $expectedAccess,
                    UiPreviewPage::canAccess(),
                    "Unexpected preview access for [{$environment}] environment.",
                );
            }
        } finally {
            app()->detectEnvironment(
                static fn (): string => $originalEnvironment,
            );
        }
    }

    public function test_preview_route_is_forbidden_in_production_environment(): void
    {
        $originalEnvironment = app()->environment();
        $originalConfigEnvironment = config('app.env');

        try {
            app()->detectEnvironment(
                static fn (): string => 'production',
            );

            /*
             * Keep Filament's fallback authentication check in its
             * permitted local state so this request reaches the preview
             * page's own canAccess() environment restriction.
             *
             * The Laravel application environment remains production.
             */
            config()->set('app.env', 'local');

            $this->actingAs(User::factory()->make(), 'web')
                ->get('/admin/ui-preview')
                ->assertForbidden();
        } finally {
            app()->detectEnvironment(
                static fn (): string => $originalEnvironment,
            );

            config()->set('app.env', $originalConfigEnvironment);
        }
    }

    public function test_preview_page_is_not_registered_in_production_panel(): void
    {
        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(
                static fn (): string => 'production',
            );

            $provider = $this->app->getProvider(
                AdminPanelProvider::class,
            );

            $this->assertInstanceOf(
                AdminPanelProvider::class,
                $provider,
            );

            $panel = $provider->panel(
                Panel::make(),
            );

            $this->assertContains(
                Dashboard::class,
                $panel->getPages(),
            );

            $this->assertNotContains(
                UiPreviewPage::class,
                $panel->getPages(),
            );
        } finally {
            app()->detectEnvironment(
                static fn (): string => $originalEnvironment,
            );
        }
    }

    // -------------------------------------------------------------------------
    // Authentication and existing admin routes
    // -------------------------------------------------------------------------

    public function test_array_screen_query_parameter_returns_not_found(): void
    {
        $this->actingAsPreviewUser();

        $this->get('/admin/ui-preview?screen%5B%5D=platform.overview')
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/ui-preview')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_login_route_remains_available_to_guests(): void
    {
        $this->get('/admin/login')
            ->assertOk();
    }

    public function test_guest_access_to_admin_panel_preserves_existing_authentication(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_view_preview_gallery(): void
    {
        $this->actingAsPreviewUser();

        $this->get('/admin/ui-preview')
            ->assertOk()
            ->assertSee('معاينة تصميمية — لا توجد بيانات أو وظائف مفعّلة')
            ->assertSee('معرض الواجهات الإدارية');
    }

    // -------------------------------------------------------------------------
    // Gallery rendering and navigation
    // -------------------------------------------------------------------------

    public function test_every_catalog_entry_can_be_opened_in_the_preview_gallery(): void
    {
        $this->actingAsPreviewUser();

        foreach (ScreenCatalog::slugs() as $slug) {
            $this->get('/admin/ui-preview?screen='.rawurlencode($slug))
                ->assertOk();
        }
    }

    public function test_unknown_screen_slug_returns_not_found(): void
    {
        $this->actingAsPreviewUser();

        $this->get('/admin/ui-preview?screen=unknown.screen')
            ->assertNotFound();
    }

    public function test_shared_states_showcase_renders_its_component_examples(): void
    {
        $this->actingAsPreviewUser();

        $this->get('/admin/ui-preview?screen=shared.states')
            ->assertOk()
            ->assertSee('معاينة تصميمية — لا توجد بيانات أو وظائف مفعّلة')
            ->assertSee('لا توجد بيانات للعرض')
            ->assertSee('التوجه')
            ->assertSee('التحسين والتطوير');
    }

    public function test_preview_gallery_uses_right_to_left_layout(): void
    {
        $this->actingAsPreviewUser();

        $response = $this->get('/admin/ui-preview?screen=shared.states')
            ->assertOk();

        $this->assertStringContainsString(
            'dir="rtl"',
            $response->getContent(),
        );
    }

    // -------------------------------------------------------------------------
    // Shared component tests
    // -------------------------------------------------------------------------

    public function test_page_header_renders_title_description_breadcrumbs_and_preview_label(): void
    {
        $html = Blade::render(
            '<x-quality-ui.page-header
                title="Page title"
                description="Page description"
                :crumbs="$crumbs"
            />',
            [
                'crumbs' => [
                    'المنصة',
                    'نظرة عامة',
                ],
            ],
        );

        $this->assertStringContainsString('Page title', $html);
        $this->assertStringContainsString('Page description', $html);
        $this->assertStringContainsString('المنصة', $html);
        $this->assertStringContainsString('نظرة عامة', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('معاينة تصميمية', $html);
    }

    public function test_section_card_renders_title_description_and_slot(): void
    {
        $html = Blade::render(
            '<x-quality-ui.section-card
                title="Section title"
                description="Section description"
            >
                Section content
            </x-quality-ui.section-card>',
        );

        $this->assertStringContainsString('Section title', $html);
        $this->assertStringContainsString('Section description', $html);
        $this->assertStringContainsString('Section content', $html);
    }

    public function test_empty_state_renders_title_description_and_status_role(): void
    {
        $html = Blade::render(
            '<x-quality-ui.empty-state
                title="No records"
                description="Nothing to display"
            />',
        );

        $this->assertStringContainsString('No records', $html);
        $this->assertStringContainsString('Nothing to display', $html);
        $this->assertStringContainsString('role="status"', $html);

        $this->assertDoesNotMatchRegularExpression(
            '/<button\b/i',
            $html,
        );
    }

    public function test_table_shell_renders_columns_caption_and_rows(): void
    {
        $html = Blade::render(
            '<x-quality-ui.table-shell
                :columns="$columns"
                caption="Preview table"
            >
                <tr>
                    <td>Example name</td>
                    <td>Example state</td>
                </tr>
            </x-quality-ui.table-shell>',
            [
                'columns' => [
                    'الاسم',
                    'الحالة',
                ],
            ],
        );

        $this->assertStringContainsString('Preview table', $html);
        $this->assertStringContainsString('الاسم', $html);
        $this->assertStringContainsString('الحالة', $html);
        $this->assertStringContainsString('Example name', $html);
        $this->assertStringContainsString('Example state', $html);
    }

    public function test_filter_toolbar_renders_rtl_empty_state_without_filter_inputs(): void
    {
        $html = Blade::render(
            '<x-quality-ui.filter-toolbar />',
        );

        $this->assertStringContainsString('dir="rtl"', $html);

        $this->assertStringContainsString(
            'عناصر التصفية للعرض التصميمي فقط',
            $html,
        );

        $this->assertStringNotContainsString('<input', $html);
    }

    public function test_filter_toolbar_renders_disabled_rtl_filters(): void
    {
        $html = Blade::render(
            '<x-quality-ui.filter-toolbar :filters="$filters" />',
            [
                'filters' => [
                    [
                        'label' => 'الحالة',
                        'value' => 'نشط',
                        'placeholder' => 'اختر الحالة',
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('الحالة', $html);
        $this->assertStringContainsString('نشط', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
    }

    public function test_form_field_shell_renders_a_disabled_input_with_label_and_hint(): void
    {
        $html = Blade::render(
            '<x-quality-ui.form-field-shell
                label="البريد الإلكتروني"
                hint="أدخل البريد الإلكتروني"
                kind="input"
            />',
        );

        $this->assertStringContainsString('البريد الإلكتروني', $html);
        $this->assertStringContainsString('أدخل البريد الإلكتروني', $html);
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_form_field_shell_supports_textarea_and_select_kinds(): void
    {
        $textareaHtml = Blade::render(
            '<x-quality-ui.form-field-shell
                label="الوصف"
                kind="textarea"
            />',
        );

        $selectHtml = Blade::render(
            '<x-quality-ui.form-field-shell
                label="الحالة"
                kind="select"
            />',
        );

        $this->assertStringContainsString('<textarea', $textareaHtml);
        $this->assertStringContainsString('disabled', $textareaHtml);

        $this->assertStringContainsString('<select', $selectHtml);
        $this->assertStringContainsString('disabled', $selectHtml);
    }

    public function test_detail_pair_renders_label_value_and_empty_fallback(): void
    {
        $html = Blade::render(
            '<dl>
                <x-quality-ui.detail-pair
                    label="Department"
                    value="Engineering"
                />

                <x-quality-ui.detail-pair
                    label="Contact"
                    :value="null"
                />
            </dl>',
        );

        $this->assertStringContainsString('Department', $html);
        $this->assertStringContainsString('Engineering', $html);
        $this->assertStringContainsString('Contact', $html);
        $this->assertStringContainsString('غير متوفر', $html);
    }

    public function test_info_alert_renders_its_variant_and_message(): void
    {
        $html = Blade::render(
            '<x-quality-ui.info-alert
                variant="warning"
                message="Informational message"
            />',
        );

        $this->assertStringContainsString(
            'Informational message',
            $html,
        );
    }

    public function test_status_placeholder_renders_neutral_status_label(): void
    {
        $html = Blade::render(
            '<x-quality-ui.status-placeholder
                label="Not activated"
                tone="neutral"
            />',
        );

        $this->assertStringContainsString('Not activated', $html);
    }

    public function test_tabs_render_only_valid_catalog_entries_and_mark_active_tab(): void
    {
        $html = Blade::render(
            '<x-quality-ui.tabs
                :tabs="$tabs"
                active="platform.overview"
            />',
            [
                'tabs' => [
                    [
                        'key' => 'platform.overview',
                        'label' => 'نظرة عامة',
                    ],
                    [
                        'key' => 'platform.organizations',
                        'label' => 'المؤسسات',
                    ],
                    [
                        'key' => 'unknown.screen',
                        'label' => 'شاشة غير معتمدة',
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('نظرة عامة', $html);
        $this->assertStringContainsString('المؤسسات', $html);
        $this->assertStringContainsString('aria-current="page"', $html);

        $this->assertStringNotContainsString(
            'شاشة غير معتمدة',
            $html,
        );
    }

    public function test_stage_strip_renders_the_five_fixed_stages(): void
    {
        $html = Blade::render(
            '<x-quality-ui.stage-strip />',
        );

        foreach ([
            'التوجه',
            'النظام',
            'التنفيذ',
            'القياس',
            'التحسين والتطوير',
        ] as $stage) {
            $this->assertStringContainsString($stage, $html);
        }
    }

    public function test_version_timeline_renders_title_and_version_details(): void
    {
        $html = Blade::render(
            '<x-quality-ui.version-timeline
                title="Version history"
                :items="$items"
            />',
            [
                'items' => [
                    [
                        'number' => 'Version 1',
                        'date' => '2026-11-02',
                        'description' => 'First version',
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('Version history', $html);
        $this->assertStringContainsString('Version 1', $html);
        $this->assertStringContainsString('2026-11-02', $html);
        $this->assertStringContainsString('First version', $html);
    }

    public function test_review_history_renders_review_details(): void
    {
        $html = Blade::render(
            '<x-quality-ui.review-history :items="$items" />',
            [
                'items' => [
                    [
                        'target' => 'Approach example',
                        'version' => 'Version 1',
                        'reviewer' => 'مراجع تجريبي',
                        'time' => '2026-11-02',
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('Approach example', $html);
        $this->assertStringContainsString('Version 1', $html);
        $this->assertStringContainsString('مراجع تجريبي', $html);
        $this->assertStringContainsString('2026-11-02', $html);
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /**
     * Authenticate a preview user for Filament feature tests.
     *
     * The model intentionally remains unchanged and does not implement
     * FilamentUser. Filament allows such users to access panels locally.
     *
     * The Laravel application environment remains "testing" so the
     * UiPreviewPage::canAccess() testing restriction is still exercised.
     *
     * Only Filament's app.env fallback is set to "local" for this test
     * process, avoiding changes to the real authentication configuration.
     */
    private function actingAsPreviewUser(): void
    {
        config()->set('app.env', 'local');

        $this->actingAs(
            User::factory()->make(),
            'web',
        );
    }
}
