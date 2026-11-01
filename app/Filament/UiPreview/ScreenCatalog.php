<?php

declare(strict_types=1);

namespace App\Filament\UiPreview;

final class ScreenCatalog
{
    /**
     * The complete catalog of UI preview screens.
     *
     * Each screen has a unique slug, a group identifier,
     * and an Arabic display title.
     *
     * @var array<int, array{
     *     slug: string,
     *     group: string,
     *     title: string
     * }>
     */
    private const SCREENS = [
        // Platform
        [
            'slug' => 'platform.overview',
            'group' => 'platform',
            'title' => 'نظرة عامة على المنصة',
        ],
        [
            'slug' => 'platform.organizations',
            'group' => 'platform',
            'title' => 'المؤسسات',
        ],
        [
            'slug' => 'platform.organization-details',
            'group' => 'platform',
            'title' => 'تفاصيل المؤسسة',
        ],

        // Organization
        [
            'slug' => 'organization.overview',
            'group' => 'organization',
            'title' => 'نظرة عامة على المؤسسة',
        ],
        [
            'slug' => 'organization.units',
            'group' => 'organization',
            'title' => 'الوحدات التنظيمية',
        ],
        [
            'slug' => 'organization.users',
            'group' => 'organization',
            'title' => 'المستخدمون',
        ],
        [
            'slug' => 'organization.permissions-overview',
            'group' => 'organization',
            'title' => 'نظرة عامة على الصلاحيات',
        ],

        // Approaches
        [
            'slug' => 'approaches.index',
            'group' => 'approaches',
            'title' => 'منهجيات الجودة',
        ],
        [
            'slug' => 'approaches.create-shell',
            'group' => 'approaches',
            'title' => 'إنشاء منهجية',
        ],
        [
            'slug' => 'approaches.summary',
            'group' => 'approaches',
            'title' => 'ملخص المنهجية',
        ],
        [
            'slug' => 'approaches.content',
            'group' => 'approaches',
            'title' => 'محتوى المنهجية',
        ],
        [
            'slug' => 'approaches.participants',
            'group' => 'approaches',
            'title' => 'المشاركون',
        ],
        [
            'slug' => 'approaches.evidence',
            'group' => 'approaches',
            'title' => 'الأدلة والشواهد',
        ],
        [
            'slug' => 'approaches.reviews',
            'group' => 'approaches',
            'title' => 'مراجعات المنهجية',
        ],
        [
            'slug' => 'approaches.maturity',
            'group' => 'approaches',
            'title' => 'مستوى النضج',
        ],
        [
            'slug' => 'approaches.activity',
            'group' => 'approaches',
            'title' => 'نشاط المنهجية',
        ],

        // Documents
        [
            'slug' => 'documents.index',
            'group' => 'documents',
            'title' => 'الوثائق',
        ],
        [
            'slug' => 'documents.details',
            'group' => 'documents',
            'title' => 'تفاصيل الوثيقة',
        ],
        [
            'slug' => 'documents.versions',
            'group' => 'documents',
            'title' => 'إصدارات الوثيقة',
        ],
        [
            'slug' => 'documents.upload-shell',
            'group' => 'documents',
            'title' => 'واجهة رفع الوثائق',
        ],

        // Reviews
        [
            'slug' => 'reviews.inbox',
            'group' => 'reviews',
            'title' => 'صندوق المراجعات',
        ],
        [
            'slug' => 'reviews.details',
            'group' => 'reviews',
            'title' => 'تفاصيل المراجعة',
        ],

        // Shared
        [
            'slug' => 'shared.notifications',
            'group' => 'shared',
            'title' => 'الإشعارات',
        ],
        [
            'slug' => 'shared.activity-log',
            'group' => 'shared',
            'title' => 'سجل النشاط',
        ],
    ];

    /**
     * Arabic labels for catalog groups.
     *
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'platform' => 'المنصة',
        'organization' => 'المؤسسة',
        'approaches' => 'منهجيات الجودة',
        'documents' => 'الوثائق',
        'reviews' => 'المراجعات',
        'shared' => 'الشاشات المشتركة',
    ];

    /**
     * Return all registered screens.
     *
     * @return array<int, array{
     *     slug: string,
     *     group: string,
     *     title: string
     * }>
     */
    public static function all(): array
    {
        return self::SCREENS;
    }

    /**
     * Return all registered screen slugs.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::SCREENS, 'slug');
    }

    /**
     * Check whether a screen slug exists.
     */
    public static function has(string $slug): bool
    {
        foreach (self::SCREENS as $screen) {
            if ($screen['slug'] === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a screen by its unique slug.
     *
     * @return array{
     *     slug: string,
     *     group: string,
     *     title: string
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
     * Return screens grouped by their category.
     *
     * @return array<string, array{
     *     label: string,
     *     screens: list<array{
     *         slug: string,
     *         group: string,
     *         title: string
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
     * Return the total number of registered screens.
     */
    public static function count(): int
    {
        return count(self::SCREENS);
    }
}
