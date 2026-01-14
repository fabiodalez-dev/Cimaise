<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;

/**
 * Service for pre-generating page caches.
 *
 * Builds cache data for home and galleries pages without HTTP requests.
 * Used by both CLI commands and admin interface.
 */
class CacheWarmService
{
    private PageCacheService $pageCacheService;
    private SettingsService $settings;
    private NavigationService $navigationService;

    public function __construct(private Database $db)
    {
        $this->settings = new SettingsService($this->db);
        $this->pageCacheService = new PageCacheService($this->settings, $this->db);
        $this->navigationService = new NavigationService($this->db);
    }

    /**
     * Warm all caches.
     *
     * @return array Stats: ['home' => bool, 'galleries' => bool, 'albums' => int, 'errors' => array]
     */
    public function warmAll(): array
    {
        $stats = [
            'home' => false,
            'galleries' => false,
            'albums' => 0,
            'errors' => [],
        ];

        // Warm home page cache
        try {
            if ($this->buildHomeCache()) {
                $stats['home'] = true;
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Home: ' . $e->getMessage();
            Logger::warning("Cache warm failed for home: " . $e->getMessage());
        }

        // Warm galleries page cache
        try {
            if ($this->buildGalleriesCache()) {
                $stats['galleries'] = true;
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Galleries: ' . $e->getMessage();
            Logger::warning("Cache warm failed for galleries: " . $e->getMessage());
        }

        // Warm individual album caches
        try {
            $stats['albums'] = $this->buildAlbumCaches();
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Albums: ' . $e->getMessage();
            Logger::warning("Cache warm failed for albums: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Build and cache home page data.
     */
    public function buildHomeCache(): bool
    {
        $pdo = $this->db->pdo();

        // Fetch home page settings
        $homeTemplate = (string) ($this->settings->get('home.template', 'classic') ?? 'classic');
        $homeSettings = [
            'template' => $homeTemplate,
            'hero_title' => (string) ($this->settings->get('home.hero_title', 'Portfolio') ?? 'Portfolio'),
            'hero_subtitle' => (string) ($this->settings->get('home.hero_subtitle', '') ?? ''),
            'albums_title' => (string) ($this->settings->get('home.albums_title', 'Latest Albums') ?? 'Latest Albums'),
            'albums_subtitle' => (string) ($this->settings->get('home.albums_subtitle', '') ?? ''),
            'empty_title' => (string) ($this->settings->get('home.empty_title', 'No albums yet') ?? 'No albums yet'),
            'empty_text' => (string) ($this->settings->get('home.empty_text', 'Check back soon for new work.') ?? 'Check back soon for new work.'),
            'gallery_scroll_direction' => (string) ($this->settings->get('home.gallery_scroll_direction', 'vertical') ?? 'vertical'),
            'gallery_text_title' => (string) ($this->settings->get('home.gallery_text_title', '') ?? ''),
            'gallery_text_content' => (string) ($this->settings->get('home.gallery_text_content', '') ?? ''),
            'masonry_gap_h' => max(0, min(40, (int) ($this->settings->get('home.masonry_gap_h', 0) ?? 0))),
            'masonry_gap_v' => max(0, min(40, (int) ($this->settings->get('home.masonry_gap_v', 0) ?? 0))),
            'masonry_col_desktop' => max(2, min(8, (int) ($this->settings->get('home.masonry_col_desktop', 5) ?? 5))),
            'masonry_col_tablet' => max(2, min(6, (int) ($this->settings->get('home.masonry_col_tablet', 3) ?? 3))),
            'masonry_col_mobile' => max(1, min(4, (int) ($this->settings->get('home.masonry_col_mobile', 1) ?? 1))),
            'masonry_layout_mode' => in_array((string) ($this->settings->get('home.masonry_layout_mode', 'fullwidth') ?? 'fullwidth'), ['fullwidth', 'boxed'], true) ? (string) $this->settings->get('home.masonry_layout_mode', 'fullwidth') : 'fullwidth',
            'masonry_max_images' => max(0, min(5000, (int) ($this->settings->get('home.masonry_max_images', 0) ?? 0))),
        ];

        // Pagination parameters
        $perPage = (int) $this->settings->get('pagination.limit', 12);

        // Get total count of published albums (public view = exclude NSFW and password-protected)
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM albums a WHERE a.is_published = 1 AND a.is_nsfw = 0 AND (a.password_hash IS NULL OR a.password_hash = \'\')');
        $countStmt->execute();
        $totalAlbums = (int) $countStmt->fetchColumn();

        // Get latest published albums (public view = exclude NSFW and password-protected)
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a
            JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1 AND a.is_nsfw = 0 AND (a.password_hash IS NULL OR a.password_hash = \'\')
            ORDER BY a.published_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->execute();
        $albums = $stmt->fetchAll();

        // Enrich albums with cover images and tags
        $albums = $this->enrichAlbums($albums);

        // Calculate pagination
        $hasMore = $totalAlbums > $perPage;

        // Get categories for navigation (public view = exclude NSFW)
        $parentCategories = $this->navigationService->getParentCategoriesForNavigation(false);

        // Build flat category list
        $categories = [];
        foreach ($parentCategories as $parent) {
            if ($parent['albums_count'] > 0) {
                $categories[] = $parent;
            }
            foreach ($parent['children'] as $child) {
                if ($child['albums_count'] > 0) {
                    $categories[] = $child;
                }
            }
        }

        // Progressive image loading
        $homeImageService = new HomeImageService($this->db);
        $includeNsfw = false; // Public cache = no NSFW

        if ($homeTemplate === 'masonry') {
            $masonryMaxImages = $homeSettings['masonry_max_images'] ?? 0;
            $initialLimit = 40;
            if ($masonryMaxImages > 0) {
                $initialLimit = min($initialLimit, $masonryMaxImages);
            }
            $imageResult = $homeImageService->getAllImages($initialLimit, $includeNsfw);
            $shownImageIds = array_column($imageResult['images'], 'id');
            $shownAlbumIds = array_unique(array_column($imageResult['images'], 'album_id'));
            $totalImagesCount = $imageResult['totalImages'];
            $hasMoreImages = $masonryMaxImages > 0
                ? $masonryMaxImages > count($imageResult['images'])
                : $totalImagesCount > count($imageResult['images']);
        } else {
            $initialLimit = 20;
            $imageResult = $homeImageService->getInitialImages($initialLimit, $includeNsfw);
            $shownImageIds = $imageResult['shownImageIds'];
            $shownAlbumIds = $imageResult['shownAlbumIds'];
            $totalImagesCount = $imageResult['totalImages'];
            $hasMoreImages = $totalImagesCount > count($imageResult['images']);
        }

        // Process images with responsive sources
        $allImages = $this->processImageSourcesBatch($imageResult['images']);

        // Build categories dynamically from loaded images
        $loadedCategorySlugs = [];
        foreach ($allImages as $image) {
            if (!empty($image['category_slugs'])) {
                foreach (explode(',', $image['category_slugs']) as $slug) {
                    $slug = trim($slug);
                    if ($slug !== '') {
                        $loadedCategorySlugs[$slug] = true;
                    }
                }
            }
        }

        // Filter categories to only include those with loaded images
        $categories = array_filter($categories, function ($cat) use ($loadedCategorySlugs) {
            return isset($loadedCategorySlugs[$cat['slug']]);
        });
        $categories = array_values($categories);

        // Filter parent_categories
        $parentCategories = array_filter($parentCategories, function ($parent) use ($loadedCategorySlugs) {
            if (isset($loadedCategorySlugs[$parent['slug']])) {
                return true;
            }
            foreach ($parent['children'] as $child) {
                if (isset($loadedCategorySlugs[$child['slug']])) {
                    return true;
                }
            }
            return false;
        });
        foreach ($parentCategories as &$parent) {
            $parent['children'] = array_filter($parent['children'], function ($child) use ($loadedCategorySlugs) {
                return isset($loadedCategorySlugs[$child['slug']]);
            });
            $parent['children'] = array_values($parent['children']);
        }
        unset($parent);
        $parentCategories = array_values($parentCategories);

        // Select template file
        $templateMap = [
            'modern' => 'frontend/home_modern.twig',
            'parallax' => 'frontend/home_parallax.twig',
            'masonry' => 'frontend/home_masonry.twig',
            'snap' => 'frontend/home_snap.twig',
            'gallery' => 'frontend/home_gallery.twig',
        ];
        $templateFile = $templateMap[$homeTemplate] ?? 'frontend/home.twig';

        // Get galleries slug
        $galleriesSlug = (string) ($this->settings->get('galleries.slug', 'galleries') ?? 'galleries');

        // Build cacheable data
        $cacheableData = [
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $parentCategories,
            'tags' => [],
            'all_images' => $allImages,
            'has_more' => $hasMore,
            'total_albums' => $totalAlbums,
            'home_settings' => $homeSettings,
            'galleries_slug' => $galleriesSlug,
            'shown_image_ids' => $shownImageIds,
            'shown_album_ids' => $shownAlbumIds,
            'total_images' => $totalImagesCount,
            'has_more_images' => $hasMoreImages,
        ];

        return $this->pageCacheService->set('home', [
            'template_file' => $templateFile,
            'data' => $cacheableData,
        ]);
    }

    /**
     * Build and cache galleries page data.
     */
    public function buildGalleriesCache(): bool
    {
        $pdo = $this->db->pdo();

        // Get page texts
        $pageTexts = [
            'title' => (string) ($this->settings->get('galleries.title', 'Galleries') ?? 'Galleries'),
            'description' => (string) ($this->settings->get('galleries.description', '') ?? ''),
            'hero_text' => (string) ($this->settings->get('galleries.hero_text', '') ?? ''),
        ];

        // Get filter settings
        $filterSettings = $this->getFilterSettings();

        // Get albums (public view = exclude NSFW and password-protected)
        $stmt = $pdo->prepare('
            SELECT
                a.*,
                c.name as category_name,
                c.slug as category_slug,
                (SELECT COUNT(*) FROM images WHERE album_id = a.id) as images_count
            FROM albums a
            JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1 AND a.is_nsfw = 0 AND (a.password_hash IS NULL OR a.password_hash = \'\')
            ORDER BY a.published_at DESC
        ');
        $stmt->execute();
        $albums = $stmt->fetchAll();

        // Enrich albums
        $albums = $this->enrichAlbums($albums);

        // Get filter options
        $filterOptions = $this->getFilterOptions();

        // Get navigation categories
        $parentCategories = $this->navigationService->getParentCategoriesForNavigation(false);

        // Build cacheable data
        $cacheableData = [
            'albums' => $albums,
            'filter_settings' => $filterSettings,
            'page_texts' => $pageTexts,
            'filter_options' => $filterOptions,
            'active_filters' => [],
            'parent_categories' => $parentCategories,
        ];

        return $this->pageCacheService->set('galleries', [
            'template_file' => 'frontend/galleries.twig',
            'data' => $cacheableData,
        ]);
    }

    /**
     * Build and cache individual album pages.
     *
     * @return int Number of albums cached
     */
    public function buildAlbumCaches(): int
    {
        $pdo = $this->db->pdo();
        $cached = 0;

        // Get all published albums (public view = exclude NSFW and password-protected)
        $stmt = $pdo->prepare("
            SELECT slug
            FROM albums
            WHERE is_published = 1
            AND is_nsfw = 0
            AND (password_hash IS NULL OR password_hash = '')
            ORDER BY published_at DESC
        ");
        $stmt->execute();
        $slugs = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($slugs as $slug) {
            try {
                if ($this->buildAlbumCache($slug)) {
                    $cached++;
                }
            } catch (\Throwable $e) {
                Logger::warning("Cache warm failed for album {$slug}: " . $e->getMessage());
            }
        }

        return $cached;
    }

    /**
     * Build and cache a single album page.
     */
    public function buildAlbumCache(string $slug): bool
    {
        $pdo = $this->db->pdo();

        // Get album data
        $stmt = $pdo->prepare("
            SELECT
                a.*,
                c.name as category_name,
                c.slug as category_slug,
                t.name as template_name,
                t.slug as template_slug,
                t.settings as template_settings
            FROM albums a
            JOIN categories c ON c.id = a.category_id
            LEFT JOIN templates t ON t.id = a.template_id
            WHERE a.slug = ? AND a.is_published = 1 AND a.is_nsfw = 0
            AND (a.password_hash IS NULL OR a.password_hash = '')
        ");
        $stmt->execute([$slug]);
        $album = $stmt->fetch();

        if (!$album) {
            return false;
        }

        // Get album images (fetch raw columns, concatenate in PHP for MySQL/SQLite compatibility)
        $imgStmt = $pdo->prepare("
            SELECT i.*,
                   cam.make as camera_make,
                   cam.model as camera_model,
                   l.brand as lens_brand,
                   l.model as lens_model,
                   f.brand as film_brand,
                   f.name as film_name,
                   dev.name as developer_name,
                   lab.name as lab_name,
                   loc.name as location_name
            FROM images i
            LEFT JOIN cameras cam ON cam.id = i.camera_id
            LEFT JOIN lenses l ON l.id = i.lens_id
            LEFT JOIN films f ON f.id = i.film_id
            LEFT JOIN developers dev ON dev.id = i.developer_id
            LEFT JOIN labs lab ON lab.id = i.lab_id
            LEFT JOIN locations loc ON loc.id = i.location_id
            WHERE i.album_id = ?
            ORDER BY i.sort_order ASC, i.id ASC
        ");
        $imgStmt->execute([$album['id']]);
        $images = $imgStmt->fetchAll();

        // Build equipment names in PHP for MySQL/SQLite compatibility
        foreach ($images as &$img) {
            $img['camera_name'] = trim(($img['camera_make'] ?? '') . ' ' . ($img['camera_model'] ?? ''));
            $img['lens_name'] = trim(($img['lens_brand'] ?? '') . ' ' . ($img['lens_model'] ?? ''));
            $img['film_name'] = trim(($img['film_brand'] ?? '') . ' ' . ($img['film_name'] ?? ''));
        }
        unset($img);

        // Process images with responsive sources
        $images = $this->processImageSourcesBatch($images);

        // Get album tags
        $tagStmt = $pdo->prepare('
            SELECT t.id, t.name, t.slug
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            WHERE at.album_id = ?
            ORDER BY t.name ASC
        ');
        $tagStmt->execute([$album['id']]);
        $tags = $tagStmt->fetchAll();

        // Parse template settings
        $templateSettings = [];
        if (!empty($album['template_settings'])) {
            $templateSettings = json_decode($album['template_settings'], true) ?: [];
        }

        // Build cacheable data
        $cacheableData = [
            'album' => $album,
            'images' => $images,
            'tags' => $tags,
            'template_settings' => $templateSettings,
        ];

        // Determine page template (hero, magazine, or classic)
        $pageTemplate = (string) ($album['album_page_template'] ?? '');
        if ($pageTemplate === '') {
            $pageTemplate = (string) ($this->settings->get('gallery.page_template', 'classic') ?? 'classic');
        }
        if (!in_array($pageTemplate, ['classic', 'hero', 'magazine'], true)) {
            $pageTemplate = 'classic';
        }
        $templateFile = match ($pageTemplate) {
            'hero' => 'frontend/gallery_hero.twig',
            'magazine' => 'frontend/gallery_magazine.twig',
            default => 'frontend/album.twig',
        };

        return $this->pageCacheService->set("album:{$slug}", [
            'template_file' => $templateFile,
            'data' => $cacheableData,
        ]);
    }

    /**
     * Enrich albums with cover images and tags.
     */
    private function enrichAlbums(array $albums): array
    {
        if (empty($albums)) {
            return [];
        }

        $pdo = $this->db->pdo();
        $albumIds = array_column($albums, 'id');
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));

        // Get cover images
        $imgStmt = $pdo->prepare("
            SELECT i.*, a.id as album_id
            FROM images i
            JOIN albums a ON a.cover_image_id = i.id
            WHERE a.id IN ({$placeholders})
        ");
        $imgStmt->execute($albumIds);
        $coverImages = [];
        foreach ($imgStmt->fetchAll() as $img) {
            $coverImages[$img['album_id']] = $img;
        }

        // Get tags for all albums
        $tagStmt = $pdo->prepare("
            SELECT at.album_id, t.id, t.name, t.slug
            FROM album_tag at
            JOIN tags t ON t.id = at.tag_id
            WHERE at.album_id IN ({$placeholders})
            ORDER BY t.name ASC
        ");
        $tagStmt->execute($albumIds);
        $albumTags = [];
        foreach ($tagStmt->fetchAll() as $tag) {
            $albumTags[$tag['album_id']][] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'slug' => $tag['slug'],
            ];
        }

        // Enrich albums
        foreach ($albums as &$album) {
            $album['cover_image'] = $coverImages[$album['id']] ?? null;
            $album['tags'] = $albumTags[$album['id']] ?? [];

            // Process cover image sources
            if ($album['cover_image']) {
                $album['cover_image'] = $this->processImageSources($album['cover_image']);
            }

            // Set locked status for password-protected albums
            $album['is_locked'] = !empty($album['password_hash']);
            $album['is_password_protected'] = !empty($album['password_hash']);
        }
        unset($album);

        return $albums;
    }

    /**
     * Process a batch of images to add responsive sources.
     */
    private function processImageSourcesBatch(array $images): array
    {
        if (empty($images)) {
            return [];
        }

        $pdo = $this->db->pdo();
        $imageIds = array_column($images, 'id');
        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));

        // Get all variants for these images
        $varStmt = $pdo->prepare("
            SELECT * FROM image_variants
            WHERE image_id IN ({$placeholders})
            ORDER BY image_id, width ASC
        ");
        $varStmt->execute($imageIds);

        $variantsByImage = [];
        foreach ($varStmt->fetchAll() as $var) {
            $variantsByImage[$var['image_id']][] = $var;
        }

        // Process each image
        foreach ($images as &$image) {
            $variants = $variantsByImage[$image['id']] ?? [];
            $image = $this->buildImageSources($image, $variants);
        }
        unset($image);

        return $images;
    }

    /**
     * Process a single image to add responsive sources.
     */
    private function processImageSources(array $image): array
    {
        $pdo = $this->db->pdo();

        $varStmt = $pdo->prepare('
            SELECT * FROM image_variants
            WHERE image_id = ?
            ORDER BY width ASC
        ');
        $varStmt->execute([$image['id']]);
        $variants = $varStmt->fetchAll();

        return $this->buildImageSources($image, $variants);
    }

    /**
     * Build image sources array from variants.
     * Uses string format "path 800w" to match PageController::processImageSourcesBatch().
     */
    private function buildImageSources(array $image, array $variants): array
    {
        $sources = [
            'avif' => [],
            'webp' => [],
            'jpg' => [],
        ];

        $fallbackSrc = null;
        $bestWidth = 0;

        foreach ($variants as $var) {
            $path = (string) ($var['path'] ?? '');
            $width = (int) ($var['width'] ?? 0);
            $variantType = $var['variant'] ?? '';

            // Skip blur variants, storage paths, and invalid entries
            if (!empty($var['is_blur']) || str_contains($path, '_blur') || $variantType === 'blur') {
                continue;
            }
            if ($path === '' || str_starts_with($path, '/storage/') || $width <= 0) {
                continue;
            }

            $format = strtolower($var['format'] ?? 'jpg');
            if ($format === 'jpeg') $format = 'jpg';

            if (!isset($sources[$format])) {
                $sources[$format] = [];
            }

            // Use string format "path 800w" like PageController
            $sources[$format][] = $path . ' ' . $width . 'w';

            // Track best fallback (largest jpg)
            if ($format === 'jpg' && $width > $bestWidth) {
                $bestWidth = $width;
                $fallbackSrc = $path;
            }
        }

        $image['sources'] = $sources;
        $image['fallback_src'] = $fallbackSrc ?: ($image['original_path'] ?? '');
        $image['variants'] = $variants;

        return $image;
    }

    /**
     * Get filter settings for galleries page.
     */
    private function getFilterSettings(): array
    {
        $pdo = $this->db->pdo();

        // filter_settings table may not exist in older installations
        try {
            $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
            $stmt->execute();
            $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
            // Table doesn't exist, use defaults
            $settings = [];
        }

        return [
            'enabled' => (bool) ($settings['enabled'] ?? true),
            'show_categories' => (bool) ($settings['show_categories'] ?? true),
            'show_tags' => (bool) ($settings['show_tags'] ?? true),
            'show_cameras' => (bool) ($settings['show_cameras'] ?? false),
            'show_lenses' => (bool) ($settings['show_lenses'] ?? false),
            'show_films' => (bool) ($settings['show_films'] ?? false),
            'show_locations' => (bool) ($settings['show_locations'] ?? false),
            'show_year' => (bool) ($settings['show_year'] ?? false),
            'show_search' => (bool) ($settings['show_search'] ?? true),
        ];
    }

    /**
     * Get filter options for galleries page.
     */
    private function getFilterOptions(): array
    {
        $pdo = $this->db->pdo();

        // Categories with album counts (exclude NSFW and password-protected)
        $catStmt = $pdo->prepare('
            SELECT c.id, c.name, c.slug, COUNT(DISTINCT a.id) as count
            FROM categories c
            LEFT JOIN albums a ON a.category_id = c.id AND a.is_published = 1 AND a.is_nsfw = 0
                AND (a.password_hash IS NULL OR a.password_hash = \'\')
            GROUP BY c.id
            HAVING count > 0
            ORDER BY c.name ASC
        ');
        $catStmt->execute();
        $categories = $catStmt->fetchAll();

        // Tags with album counts (exclude NSFW and password-protected)
        $tagStmt = $pdo->prepare('
            SELECT t.id, t.name, t.slug, COUNT(DISTINCT at.album_id) as count
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1 AND a.is_nsfw = 0
                AND (a.password_hash IS NULL OR a.password_hash = \'\')
            GROUP BY t.id
            HAVING count > 0
            ORDER BY t.name ASC
        ');
        $tagStmt->execute();
        $tags = $tagStmt->fetchAll();

        // Years (use Database::yearExpression for MySQL/SQLite compatibility)
        // Exclude NSFW and password-protected albums
        $yearExpr = $this->db->yearExpression('shoot_date');
        $yearStmt = $pdo->prepare("
            SELECT DISTINCT {$yearExpr} as year
            FROM albums
            WHERE is_published = 1 AND is_nsfw = 0 AND shoot_date IS NOT NULL
                AND (password_hash IS NULL OR password_hash = '')
            ORDER BY year DESC
        ");
        $yearStmt->execute();
        $years = $yearStmt->fetchAll(\PDO::FETCH_COLUMN);

        return [
            'categories' => $categories,
            'tags' => $tags,
            'years' => $years,
            'cameras' => [],
            'lenses' => [],
            'films' => [],
            'locations' => [],
        ];
    }

    /**
     * Get PageCacheService instance.
     */
    public function getPageCacheService(): PageCacheService
    {
        return $this->pageCacheService;
    }
}
