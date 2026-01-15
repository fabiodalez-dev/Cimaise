<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Cache tag constants for tag-based cache invalidation.
 *
 * Tags allow invalidating multiple related cache entries at once.
 * Example: When an album is updated, invalidate all caches tagged with that album.
 */
final class CacheTags
{
    // Page type tags
    public const HOME = 'tag:home';
    public const GALLERIES = 'tag:galleries';
    public const ALBUM = 'tag:album';
    public const ABOUT = 'tag:about';

    // Settings/config tags
    public const SETTINGS = 'tag:settings';
    public const NAVIGATION = 'tag:navigation';
    public const SEO = 'tag:seo';

    // Content type prefixes
    public const CATEGORY_PREFIX = 'tag:category:';
    public const TAG_PREFIX = 'tag:content_tag:';
    public const ALBUM_PREFIX = 'tag:album:';

    /**
     * Generate tag for a specific category.
     */
    public static function category(int|string $id): string
    {
        return self::CATEGORY_PREFIX . $id;
    }

    /**
     * Generate tag for a specific content tag.
     */
    public static function contentTag(int|string $id): string
    {
        return self::TAG_PREFIX . $id;
    }

    /**
     * Generate tag for a specific album.
     */
    public static function album(int|string $idOrSlug): string
    {
        return self::ALBUM_PREFIX . $idOrSlug;
    }

    /**
     * Get all tags that should be invalidated when home page content changes.
     */
    public static function homeRelated(): array
    {
        return [self::HOME, self::NAVIGATION];
    }

    /**
     * Get all tags that should be invalidated when an album changes.
     */
    public static function albumRelated(int|string $albumId): array
    {
        return [
            self::album($albumId),
            self::HOME,
            self::GALLERIES,
        ];
    }

    /**
     * Get all tags that should be invalidated when a category changes.
     */
    public static function categoryRelated(int|string $categoryId): array
    {
        return [
            self::category($categoryId),
            self::NAVIGATION,
            self::GALLERIES,
            self::HOME,
        ];
    }

    /**
     * Get all tags that should be invalidated when settings change.
     */
    public static function settingsRelated(): array
    {
        return [
            self::SETTINGS,
            self::SEO,
            self::NAVIGATION,
        ];
    }
}
