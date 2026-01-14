<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\QueryCache;

class NavigationService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(private Database $db)
    {
    }

    private static ?array $navigationCategoriesCache = null;
    private static array $parentCategoriesCache = [];

    public function getNavigationCategories(): array
    {
        // Per-request cache (fastest)
        if (self::$navigationCategoriesCache !== null) {
            return self::$navigationCategoriesCache;
        }

        // Persistent cache via QueryCache
        $cache = QueryCache::getInstance();
        self::$navigationCategoriesCache = $cache->remember('nav_categories', function () {
            $stmt = $this->db->pdo()->prepare('SELECT id, name, slug FROM categories ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, name ASC');
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }, self::CACHE_TTL);

        return self::$navigationCategoriesCache;
    }

    /**
     * Get parent categories with children and album counts for mega menu navigation
     * @param bool $includeNsfw Whether to include NSFW albums in count (default: false)
     */
    public function getParentCategoriesForNavigation(bool $includeNsfw = false): array
    {
        $cacheKey = $includeNsfw ? 'nsfw' : 'clean';

        // Per-request cache (fastest)
        if (isset(self::$parentCategoriesCache[$cacheKey])) {
            return self::$parentCategoriesCache[$cacheKey];
        }

        // Persistent cache via QueryCache
        $cache = QueryCache::getInstance();
        $queryCacheKey = 'nav_parent_categories_' . $cacheKey;

        self::$parentCategoriesCache[$cacheKey] = $cache->remember($queryCacheKey, function () use ($includeNsfw) {
            $pdo = $this->db->pdo();

            // Get parent categories with album counts (using album_category junction table)
            // Exclude password-protected albums and optionally NSFW albums from count
            // Optimize to single query: Fetch ALL categories with counts
            $stmt = $pdo->prepare('
                SELECT c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at,
                       COUNT(DISTINCT a.id) as albums_count
                FROM categories c
                LEFT JOIN album_category ac ON ac.category_id = c.id
                LEFT JOIN albums a ON a.id = ac.album_id
                    AND a.is_published = 1
                    AND (a.password_hash IS NULL OR a.password_hash = \'\')
                    AND (:include_nsfw = 1 OR a.is_nsfw = 0)
                GROUP BY c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at
                ORDER BY c.sort_order ASC, c.name ASC
            ');
            $stmt->execute([':include_nsfw' => $includeNsfw ? 1 : 0]);
            $allCategories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Build tree in PHP
            $parents = [];
            $childrenByParent = [];

            foreach ($allCategories as $cat) {
                if ($cat['parent_id'] === null) {
                    $parents[] = $cat;
                } else {
                    $pid = (int) $cat['parent_id'];
                    if (!isset($childrenByParent[$pid])) {
                        $childrenByParent[$pid] = [];
                    }
                    $childrenByParent[$pid][] = $cat;
                }
            }

            // Assign children to parents
            foreach ($parents as &$parent) {
                $pid = (int) $parent['id'];
                $parent['children'] = $childrenByParent[$pid] ?? [];
            }

            return $parents;
        }, self::CACHE_TTL);

        return self::$parentCategoriesCache[$cacheKey];
    }

    /**
     * Invalidate all navigation caches.
     * Call this when categories are created/updated/deleted.
     */
    public static function invalidateCache(): void
    {
        self::$navigationCategoriesCache = null;
        self::$parentCategoriesCache = [];

        $cache = QueryCache::getInstance();
        $cache->forget('nav_categories');
        $cache->forget('nav_parent_categories_nsfw');
        $cache->forget('nav_parent_categories_clean');
    }
}
