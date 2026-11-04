<?php

declare(strict_types=1);

namespace App\Filament\UiPreview;

final class ScreenCatalog
{
    /**
     * The explicit and immutable catalog of all preview entries.
     *
     * Every view is statically mapped.
     * shared.states intentionally points to the gallery view.
     *
     * @var array<int, array{
     *     slug: string,
     *     group: string,
     *     title: string,
     *     view: string,
     *     srs: list<string>
     * }>
     */
    private const SCREENS = [
        // Platform
        [
            'slug' => 'platform.overview',
            'group' => 'platform',
            'title' => 'نظرة عامة على المنصة',
            'view' => 'filament.ui-preview.screens.platform.overview',
            'srs' => ['FR-ID-001'],
        ],
        [
            'slug' => 'platform.organizations',
            'group' => 'platform',
            'title' => 'المؤسسات',
            'view' => 'filament.ui-preview.screens.platform.organizations',
            'srs' => ['FR-ID-001'],
        ],
        [
            'slug' => 'platform.organization-details',
            'group' => 'platform',
            'title' => 'تفاصيل المؤسسة',
            'view' => 'filament.ui-preview.screens.platform.organization-details',
            'srs' => ['FR-ID-001'],
        ],

        // Organization
        [
            'slug' => 'organization.overview',
            'group' => 'organization',
            'title' => 'نظرة عامة على المؤسسة',
            'view' => 'filament.ui-preview.screens.organization.overview',
            'srs' => ['FR-ID-002', 'FR-ID-003'],
        ],
        [
            'slug' => 'organization.units',
            'group' => 'organization',
            'title' => 'الوحدات التنظيمية',
            'view' => 'filament.ui-preview.screens.organization.units',
            'srs' => ['FR-ID-002'],
        ],
        [
            'slug' => 'organization.users',
            'group' => 'organization',
            'title' => 'المستخدمون',
            'view' => 'filament.ui-preview.screens.organization.users',
            'srs' => ['FR-ID-003'],
        ],
        [
            'slug' => 'organization.permissions',
            'group' => 'organization',
            'title' => 'نظرة عامة على الصلاحيات',
            'view' => 'filament.ui-preview.screens.organization.permissions-overview',
            'srs' => ['FR-ID-006'],
        ],

        // Approaches
        [
            'slug' => 'approaches.index',
            'group' => 'approaches',
            'title' => 'منهجيات الجودة',
            'view' => 'filament.ui-preview.screens.approaches.index',
            'srs' => ['FR-APP-001', 'FR-APP-006'],
        ],
        [
            'slug' => 'approaches.create',
            'group' => 'approaches',
            'title' => 'إنشاء منهجية',
            'view' => 'filament.ui-preview.screens.approaches.create-shell',
            'srs' => ['FR-APP-002'],
        ],
        [
            'slug' => 'approaches.summary',
            'group' => 'approaches',
            'title' => 'ملخص المنهجية',
            'view' => 'filament.ui-preview.screens.approaches.summary',
            'srs' => ['FR-APP-007'],
        ],
        [
            'slug' => 'approaches.content',
            'group' => 'approaches',
            'title' => 'محتوى المنهجية',
            'view' => 'filament.ui-preview.screens.approaches.content',
            'srs' => ['FR-APP-005'],
        ],
        [
            'slug' => 'approaches.participants',
            'group' => 'approaches',
            'title' => 'المشاركون',
            'view' => 'filament.ui-preview.screens.approaches.participants',
            'srs' => ['FR-APP-003', 'FR-APP-004'],
        ],
        [
            'slug' => 'approaches.evidence',
            'group' => 'approaches',
            'title' => 'الأدلة والشواهد',
            'view' => 'filament.ui-preview.screens.approaches.evidence',
            'srs' => ['FR-DOC-004', 'FR-DOC-005'],
        ],
        [
            'slug' => 'approaches.reviews',
            'group' => 'approaches',
            'title' => 'مراجعات المنهجية',
            'view' => 'filament.ui-preview.screens.approaches.reviews',
            'srs' => ['FR-REV-001', 'FR-REV-006'],
        ],
        [
            'slug' => 'approaches.maturity',
            'group' => 'approaches',
            'title' => 'مستوى النضج',
            'view' => 'filament.ui-preview.screens.approaches.maturity',
            'srs' => ['FR-MAT-001', 'FR-MAT-002'],
        ],
        [
            'slug' => 'approaches.activity',
            'group' => 'approaches',
            'title' => 'نشاط المنهجية',
            'view' => 'filament.ui-preview.screens.approaches.activity',
            'srs' => ['FR-APP-008', 'FR-UX-005'],
        ],

        // Documents
        [
            'slug' => 'documents.index',
            'group' => 'documents',
            'title' => 'الوثائق',
            'view' => 'filament.ui-preview.screens.documents.index',
            'srs' => ['FR-DOC-002', 'FR-DOC-006'],
        ],
        [
            'slug' => 'documents.details',
            'group' => 'documents',
            'title' => 'تفاصيل الوثيقة',
            'view' => 'filament.ui-preview.screens.documents.details',
            'srs' => ['FR-DOC-002', 'FR-DOC-005'],
        ],
        [
            'slug' => 'documents.versions',
            'group' => 'documents',
            'title' => 'إصدارات الوثيقة',
            'view' => 'filament.ui-preview.screens.documents.versions',
            'srs' => ['FR-DOC-003', 'FR-DOC-006'],
        ],
        [
            'slug' => 'documents.upload',
            'group' => 'documents',
            'title' => 'واجهة رفع الوثائق',
            'view' => 'filament.ui-preview.screens.documents.upload-shell',
            'srs' => ['FR-DOC-001'],
        ],

        // Reviews
        [
            'slug' => 'reviews.inbox',
            'group' => 'reviews',
            'title' => 'صندوق المراجعات',
            'view' => 'filament.ui-preview.screens.reviews.inbox',
            'srs' => ['FR-REV-001', 'FR-REV-002'],
        ],
        [
            'slug' => 'reviews.details',
            'group' => 'reviews',
            'title' => 'تفاصيل المراجعة',
            'view' => 'filament.ui-preview.screens.reviews.details',
            'srs' => [
                'FR-REV-003',
                'FR-REV-004',
                'FR-REV-005',
                'FR-REV-006',
            ],
        ],

        // Shared
        [
            'slug' => 'shared.notifications',
            'group' => 'shared',
            'title' => 'الإشعارات',
            'view' => 'filament.ui-preview.screens.shared.notifications',
            'srs' => ['FR-UX-003', 'FR-UX-004'],
        ],
        [
            'slug' => 'shared.activity-log',
            'group' => 'shared',
            'title' => 'سجل النشاط',
            'view' => 'filament.ui-preview.screens.shared.activity-log',
            'srs' => ['FR-UX-005'],
        ],
        [
            'slug' => 'shared.states',
            'group' => 'shared',
            'title' => 'معرض الحالات المشتركة',
            'view' => 'filament.ui-preview.gallery',
            'srs' => ['FR-UX-001'],
        ],
    ];

    /**
     * Arabic labels for catalog groups.
     *
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'platform' => 'إدارة المنصة',
        'organization' => 'المؤسسة',
        'approaches' => 'بنك المنهجيات',
        'documents' => 'الوثائق والأدلة',
        'reviews' => 'المراجعات',
        'shared' => 'المشتركات',
    ];

    /**
     * Return all registered preview entries.
     *
     * @return array<int, array{
     *     slug: string,
     *     group: string,
     *     title: string,
     *     view: string,
     *     srs: list<string>
     * }>
     */
    public static function all(): array
    {
        return self::SCREENS;
    }

    /**
     * Return all registered preview slugs.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::SCREENS, 'slug');
    }

    /**
     * Check whether a preview slug is registered.
     */
    public static function has(string $slug): bool
    {
        return self::find($slug) !== null;
    }

    /**
     * Find a preview entry using its exact registered slug.
     *
     * @return array{
     *     slug: string,
     *     group: string,
     *     title: string,
     *     view: string,
     *     srs: list<string>
     * }|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::SCREENS as $screen) {
            if ($screen['slug'] === $slug) {
                return $screen;
            }
        }

        return null;
    }

    /**
     * Return preview entries grouped by category.
     *
     * @return array<string, array{
     *     label: string,
     *     screens: list<array{
     *         slug: string,
     *         group: string,
     *         title: string,
     *         view: string,
     *         srs: list<string>
     *     }>
     * }>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::SCREENS as $screen) {
            $group = $screen['group'];

            $groups[$group] ??= [
                'label' => self::GROUP_LABELS[$group],
                'screens' => [],
            ];

            $groups[$group]['screens'][] = $screen;
        }

        return $groups;
    }

    /**
     * Return the total number of registered preview entries.
     */
    public static function count(): int
    {
        return count(self::SCREENS);
    }
}
