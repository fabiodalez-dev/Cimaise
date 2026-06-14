<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Logger;
use App\Services\SettingsService;
use App\Services\NavigationService;
use App\Services\PageCacheService;
use App\Services\CacheTags;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GalleriesController extends BaseController
{
    /**
     * Admin-selectable listing templates (setting `galleries.template`).
     * Slug => twig file. 'classic' keeps the historical galleries.twig.
     */
    public const PAGE_TEMPLATES = [
        'classic'         => 'frontend/galleries.twig',
        'editorial-index' => 'frontend/galleries_editorial_index.twig',
        'mosaic'          => 'frontend/galleries_mosaic.twig',
        'filmstrip'       => 'frontend/galleries_filmstrip.twig',
        'split-showcase'  => 'frontend/galleries_split_showcase.twig',
        'contact-sheet'   => 'frontend/galleries_contact_sheet.twig',
        'chronicle'       => 'frontend/galleries_chronicle.twig',
        'panorama'        => 'frontend/galleries_panorama.twig',
        'bento'           => 'frontend/galleries_bento.twig',
        'polaroid'        => 'frontend/galleries_polaroid.twig',
        'index-minimal'   => 'frontend/galleries_index_minimal.twig',
    ];

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    /**
     * Resolve the active listing template (slug + twig file) from settings.
     * Unknown values fall back to classic so a bad setting can never 500.
     *
     * @return array{0: string, 1: string} [slug, twigFile]
     */
    private function resolvePageTemplate(): array
    {
        $slug = (string) ((new SettingsService($this->db))->get('galleries.template', 'classic') ?? 'classic');
        if (!isset(self::PAGE_TEMPLATES[$slug])) {
            $slug = 'classic';
        }
        return [$slug, self::PAGE_TEMPLATES[$slug]];
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // Build filter parameters first to check if any are active
        $filterSettings = $this->getFilterSettings();
        $filters = $this->buildFilters($params, $filterSettings);

        // Check if any filter is active (excluding default sort)
        $hasActiveFilters = !empty($filters['category']) || !empty($filters['tags']) ||
            !empty($filters['cameras']) || !empty($filters['lenses']) ||
            !empty($filters['films']) || !empty($filters['developers']) ||
            !empty($filters['labs']) || !empty($filters['locations']) ||
            !empty($filters['year']) || !empty($filters['search']) ||
            ($filters['sort'] ?? 'published_desc') !== 'published_desc';

        // Admin-selected listing template; the cache key embeds the slug so a
        // template switch can never serve a page cached for another template.
        [$pageTemplateSlug, $pageTemplateFile] = $this->resolvePageTemplate();
        $cacheKey = $pageTemplateSlug === 'classic' ? 'galleries' : 'galleries_' . $pageTemplateSlug;

        // Cache is only used for unfiltered public view
        $canUseCache = !$isAdmin && !$nsfwConsent && !$hasActiveFilters;

        // Try cache first for public unfiltered view
        if ($canUseCache) {
            $cacheService = $this->getPageCacheService();
            $cached = $cacheService->get($cacheKey);

            if ($cached !== null && isset($cached['data']) && is_array($cached['data'])) {
                // Fresh cache hit - render with cached data + session-specific vars
                return $this->view->render($response, $pageTemplateFile, array_merge($cached['data'], [
                    'nsfw_consent' => $nsfwConsent,
                    'is_admin' => $isAdmin,
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]));
            }

            // Lazy regeneration: try stale cache
            $staleCached = $cacheService->get($cacheKey, allowStale: true);
            if ($staleCached !== null && isset($staleCached['data']) && is_array($staleCached['data'])) {
                // Serve stale, schedule background regeneration could be done here
                return $this->view->render($response, $pageTemplateFile, array_merge($staleCached['data'], [
                    'nsfw_consent' => $nsfwConsent,
                    'is_admin' => $isAdmin,
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]));
            }
        }

        // Get page texts from settings
        $pageTexts = $this->getPageTexts();

        // Get albums with filters applied
        $albums = $this->getFilteredAlbums($filters, $isAdmin, $nsfwConsent);

        // Get filter options for dropdowns
        $filterOptions = $this->getFilterOptions();

        // Get navigation categories
        $parentCategories = (new NavigationService($this->db))->getParentCategoriesForNavigation();

        // Prepare data for rendering and caching
        $renderData = [
            'albums' => $albums,
            'filter_settings' => $filterSettings,
            'page_texts' => $pageTexts,
            'filter_options' => $filterOptions,
            'active_filters' => $filters,
            'parent_categories' => $parentCategories,
            'page_title' => $pageTexts['title'],
            'meta_description' => $pageTexts['description'],
        ];

        // Save to cache for future public requests (unfiltered only)
        if ($canUseCache) {
            $this->getPageCacheService()->setWithTags($cacheKey, [
                'data' => $renderData,
            ], [CacheTags::GALLERIES, CacheTags::NAVIGATION]);
        }

        // Add session-specific vars for rendering
        $renderData['nsfw_consent'] = $nsfwConsent;
        $renderData['is_admin'] = $isAdmin;
        $renderData['csrf'] = $_SESSION['csrf'] ?? '';

        return $this->view->render($response, $pageTemplateFile, $renderData);
    }

    /**
     * Get PageCacheService instance (lazy-loaded).
     */
    private function getPageCacheService(): PageCacheService
    {
        static $service = null;
        if ($service === null) {
            $service = new PageCacheService(new SettingsService($this->db), $this->db);
        }
        return $service;
    }

    public function filter(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // Get filter settings
        $filterSettings = $this->getFilterSettings();

        // Build filters from request
        $filters = $this->buildFilters($params, $filterSettings);

        // Optional result cap (validated int, 1..100). Used by callers that only
        // need a handful of results — e.g. the live-search suggestion box should
        // request limit=6 instead of receiving every published album on each
        // keystroke. Absent/invalid limit = full listing (unchanged behavior
        // for the galleries page itself).
        $limit = null;
        if (isset($params['limit']) && is_numeric($params['limit'])) {
            $requested = (int) $params['limit'];
            if ($requested >= 1) {
                $limit = min(100, $requested);
            }
        }

        // Get filtered albums
        $albums = $this->getFilteredAlbums($filters, $isAdmin, $nsfwConsent, $limit);

        // Sanitize album data - remove sensitive fields
        // SECURITY: For NSFW albums without consent, expose only blur_path (no real previews)
        $safeAlbums = array_map(function ($album) use ($isAdmin, $nsfwConsent) {
            $isNsfw = (bool)($album['is_nsfw'] ?? false);
            $canShowNsfw = $isAdmin || $nsfwConsent;

            $coverImage = null;
            if (isset($album['cover_image'])) {
                // Use fallback dimensions to avoid CLS (Cumulative Layout Shift)
                // Default 4:3 aspect ratio at 400px width if dimensions missing
                $coverImage = [
                    'id' => $album['cover_image']['id'],
                    'width' => (int)($album['cover_image']['width'] ?? 400),
                    'height' => (int)($album['cover_image']['height'] ?? 300),
                ];
                $blurPath = $album['cover_image']['blur_path'] ?? null;
                if (!empty($album['is_locked']) && !empty($album['cover_image']['id'])) {
                    $ext = 'jpg';
                    if ($blurPath && preg_match('/\\.([a-z0-9]+)$/i', (string)$blurPath, $matches)) {
                        $ext = strtolower($matches[1]);
                    }
                    $blurPath = '/media/protected/' . (int)$album['cover_image']['id'] . '/blur.' . $ext;
                }
                if (($isNsfw && !$canShowNsfw) || !empty($album['is_locked'])) {
                    $coverImage['blur_path'] = $blurPath;
                } else {
                    $coverImage['preview_path'] = $album['cover_image']['preview_path'] ?? null;
                    $coverImage['blur_path'] = $blurPath;
                }
            }

            return [
                'id' => $album['id'],
                'slug' => $album['slug'],
                'title' => $album['title'],
                'excerpt' => $album['excerpt'] ?? null,
                'shoot_date' => $album['shoot_date'] ?? null,
                'published_at' => $album['published_at'] ?? null,
                'category_name' => $album['category_name'] ?? null,
                'category_slug' => $album['category_slug'] ?? null,
                'images_count' => $album['images_count'] ?? 0,
                'is_password_protected' => $album['is_password_protected'] ?? false,
                'is_locked' => $album['is_locked'] ?? false,
                'is_nsfw' => $isNsfw,
                'cover_image' => $coverImage,
                'tags' => array_map(function ($tag) {
                    return ['id' => $tag['id'], 'name' => $tag['name'], 'slug' => $tag['slug']];
                }, $album['tags'] ?? []),
            ];
        }, $albums);

        // Return JSON response for AJAX
        $response->getBody()->write(json_encode([
            'success' => true,
            'albums' => $safeAlbums,
            'total' => count($safeAlbums),
            'filters' => $filters
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getFilterSettings(): array
    {
        $pdo = $this->db->pdo();

        // Get filter settings from database
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
        $stmt->execute();
        $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        // Default settings if not found
        $defaults = [
            'enabled' => true,
            'show_categories' => true,
            'show_tags' => true,
            'show_cameras' => true,
            'show_lenses' => true,
            'show_films' => true,
            'show_developers' => false,
            'show_labs' => false,
            'show_locations' => true,
            'show_year' => true,
            'grid_columns_desktop' => 3,
            'grid_columns_tablet' => 2,
            'grid_columns_mobile' => 1,
            'grid_gap' => 'normal',
            'animation_enabled' => true,
            'animation_duration' => '0.6'
        ];

        return array_merge($defaults, $settings);
    }

    private function getPageTexts(): array
    {
        $svc = new SettingsService($this->db);

        return [
            'title' => (string)($svc->get('galleries.title', 'All Galleries') ?? 'All Galleries'),
            'subtitle' => (string)($svc->get('galleries.subtitle', 'Explore our complete collection of photography galleries') ?? 'Explore our complete collection of photography galleries'),
            'description' => (string)($svc->get('galleries.description', 'Browse all photography galleries with advanced filtering options') ?? 'Browse all photography galleries with advanced filtering options'),
            'filter_button_text' => (string)($svc->get('galleries.filter_button_text', 'Filters') ?? 'Filters'),
            'clear_filters_text' => (string)($svc->get('galleries.clear_filters_text', 'Clear filters') ?? 'Clear filters'),
            'results_text' => (string)($svc->get('galleries.results_text', 'galleries') ?? 'galleries'),
            'no_results_title' => (string)($svc->get('galleries.no_results_title', 'No galleries found') ?? 'No galleries found'),
            'no_results_text' => (string)($svc->get('galleries.no_results_text', 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.') ?? 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.'),
            'view_button_text' => (string)($svc->get('galleries.view_button_text', 'View') ?? 'View'),
        ];
    }

    private function buildFilters(array $params, array $settings): array
    {
        $filters = [];
        $normalizeList = function ($value): array {
            if (\is_array($value)) {
                return array_values(array_filter($value, static function ($item): bool {
                    return $item !== null && $item !== '';
                }));
            }
            if (\is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    return [];
                }
                if (str_contains($value, ',')) {
                    $parts = array_map('trim', explode(',', $value));
                    return array_values(array_filter($parts, static function ($item): bool {
                        return $item !== '';
                    }));
                }
                return [$value];
            }
            return [];
        };

        // Category filter
        if (!empty($params['category']) && $settings['show_categories']) {
            $filters['category'] = $normalizeList($params['category']);
        }

        // Tag filter
        if (!empty($params['tags']) && $settings['show_tags']) {
            $filters['tags'] = $normalizeList($params['tags']);
        }

        // Camera filter
        if (!empty($params['cameras']) && $settings['show_cameras']) {
            $filters['cameras'] = $normalizeList($params['cameras']);
        }

        // Lens filter
        if (!empty($params['lenses']) && $settings['show_lenses']) {
            $filters['lenses'] = $normalizeList($params['lenses']);
        }

        // Film filter
        if (!empty($params['films']) && $settings['show_films']) {
            $filters['films'] = $normalizeList($params['films']);
        }

        // Developer filter
        if (!empty($params['developers']) && $settings['show_developers']) {
            $filters['developers'] = $normalizeList($params['developers']);
        }

        // Lab filter
        if (!empty($params['labs']) && $settings['show_labs']) {
            $filters['labs'] = $normalizeList($params['labs']);
        }

        // Location filter
        if (!empty($params['locations']) && $settings['show_locations']) {
            $filters['locations'] = $normalizeList($params['locations']);
        }

        // Year filter
        if (!empty($params['year']) && $settings['show_year']) {
            $filters['year'] = (int)$params['year'];
        }

        // Search filter
        if (!empty($params['search'])) {
            $filters['search'] = trim($params['search']);
        }

        // Sort filter
        $filters['sort'] = $params['sort'] ?? 'published_desc';

        return $filters;
    }

    /**
     * @param int|null $limit Optional row cap (already validated to 1..100 by
     *                        the caller); null = no LIMIT (full listing).
     */
    private function getFilteredAlbums(array $filters, ?bool $isAdmin = null, ?bool $nsfwConsent = null, ?int $limit = null): array
    {
        $pdo = $this->db->pdo();

        // Lazy semi-join strategy: the base query only LEFT JOINs categories for
        // the primary category display (c.name/c.slug). Each pivot filter is
        // expressed as an EXISTS subquery — added ONLY when the filter is set —
        // which avoids the cartesian product + DISTINCT pattern from before
        // (18 LEFT JOINs always active). Significantly faster as the dataset grows.
        $sql = '
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
        ';

        $params = [];
        $conditions = [];

        // Category: match either the primary category (albums.category_id) OR the
        // many-to-many album_category. Single EXISTS handles both paths.
        if (!empty($filters['category'])) {
            $placeholders = implode(',', array_fill(0, count($filters['category']), '?'));
            $conditions[] = "(a.category_id IN ($placeholders) OR EXISTS (
                SELECT 1 FROM album_category ac
                WHERE ac.album_id = a.id AND ac.category_id IN ($placeholders)
            ))";
            $params = array_merge($params, $filters['category'], $filters['category']);
        }

        // Pivot-only filters: each becomes one EXISTS, added only when active.
        $pivotFilters = [
            'tags'       => ['table' => 'album_tag',       'col' => 'tag_id'],
            'cameras'    => ['table' => 'album_camera',    'col' => 'camera_id'],
            'lenses'     => ['table' => 'album_lens',      'col' => 'lens_id'],
            'films'      => ['table' => 'album_film',      'col' => 'film_id'],
            'developers' => ['table' => 'album_developer', 'col' => 'developer_id'],
            'labs'       => ['table' => 'album_lab',       'col' => 'lab_id'],
            'locations'  => ['table' => 'album_location',  'col' => 'location_id'],
        ];
        foreach ($pivotFilters as $key => $meta) {
            if (empty($filters[$key])) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($filters[$key]), '?'));
            $conditions[] = "EXISTS (
                SELECT 1 FROM {$meta['table']} px
                WHERE px.album_id = a.id AND px.{$meta['col']} IN ($placeholders)
            )";
            $params = array_merge($params, $filters[$key]);
        }

        if (!empty($filters['year'])) {
            $yearExpr = $this->db->yearExpression('a.shoot_date');
            $conditions[] = "{$yearExpr} = ?";
            $params[] = (string)$filters['year'];
        }

        if (!empty($filters['search'])) {
            // Album-text fields + primary category name (inline) + EXISTS on tags
            // and many-to-many categories. EXISTS keeps the row-count linear in
            // matches rather than exploding via JOIN duplication.
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = "(
                a.title LIKE ? OR a.excerpt LIKE ? OR a.body LIKE ? OR a.slug LIKE ?
                OR c.name LIKE ?
                OR EXISTS (SELECT 1 FROM album_category sac JOIN categories scat ON scat.id = sac.category_id WHERE sac.album_id = a.id AND scat.name LIKE ?)
                OR EXISTS (SELECT 1 FROM album_tag sat JOIN tags st ON st.id = sat.tag_id WHERE sat.album_id = a.id AND st.name LIKE ?)
            )";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($conditions)) {
            $sql .= ' AND ' . implode(' AND ', $conditions);
        }

        // Add sorting
        switch ($filters['sort'] ?? 'published_desc') {
            case 'published_asc':
                $sql .= ' ORDER BY a.published_at ASC';
                break;
            case 'title_asc':
                $sql .= ' ORDER BY a.title ASC';
                break;
            case 'title_desc':
                $sql .= ' ORDER BY a.title DESC';
                break;
            case 'shoot_date_desc':
                $sql .= ' ORDER BY a.shoot_date DESC';
                break;
            case 'shoot_date_asc':
                $sql .= ' ORDER BY a.shoot_date ASC';
                break;
            default:
                $sql .= ' ORDER BY a.published_at DESC';
        }

        // Apply optional cap in SQL so enrichment (covers/tags/counts) only
        // runs for the rows actually returned. $limit is a validated int.
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $albums = $stmt->fetchAll();

        $isAdmin ??= $this->isAdmin();
        $nsfwConsent ??= $this->hasNsfwConsent();

        // Batch enrich albums (eliminates N+1 query problem)
        $albums = $this->enrichAlbumsBatch($albums);

        // Post-process each album for access control and cover sanitization
        $visibleAlbums = [];
        foreach ($albums as $album) {
            // Mark password-protected albums as locked (but still show them in listings)
            $album['is_locked'] = !$isAdmin && !empty($album['is_password_protected']) && !$this->hasAlbumPasswordAccess((int)$album['id']);
            $album = $this->sanitizeAlbumCoverForNsfw($album, $isAdmin, $nsfwConsent);
            $album = $this->ensureAlbumCoverImage($album);
            $visibleAlbums[] = $album;
        }

        return $visibleAlbums;
    }

    private function getFilterOptions(): array
    {
        // PERFORMANCE: Cache filter options for 5 minutes (300 seconds)
        // These queries are expensive (9 JOINs with GROUP BY) and change rarely
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/filter_options.cache';
        $cacheTtl = 300; // 5 minutes

        // Check cache
        if (file_exists($cacheFile)) {
            $cacheData = @file_get_contents($cacheFile);
            if ($cacheData !== false) {
                // Legacy cache migration ended 2025-12-15: do not accept non-JSON data.
                $cache = @json_decode($cacheData, true);

                // SECURITY: Comprehensive validation of cache structure
                if (!$this->validateFilterCacheStructure($cache)) {
                    Logger::warning('GalleriesController: Invalid filter cache structure detected, rebuilding', [
                        'cache_file' => $cacheFile
                    ], 'security');
                    @unlink($cacheFile);
                    $cache = [];
                }

                // Check expiration
                if (is_array($cache) && isset($cache['expires'], $cache['data']) &&
                    is_int($cache['expires']) && $cache['expires'] > time()) {
                    return $cache['data'];
                }
            }
        }

        $pdo = $this->db->pdo();

        // Get categories
        $stmt = $pdo->prepare('
            SELECT c.*, COUNT(DISTINCT ac.album_id) as albums_count
            FROM categories c 
            LEFT JOIN album_category ac ON ac.category_id = c.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            GROUP BY c.id
            HAVING COUNT(DISTINCT ac.album_id) > 0
            ORDER BY COALESCE(c.parent_id, 0) ASC, c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $categories = $stmt->fetchAll();

        // Get tags
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(DISTINCT at.album_id) as albums_count
            FROM tags t 
            LEFT JOIN album_tag at ON at.tag_id = t.id
            LEFT JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id
            HAVING COUNT(DISTINCT at.album_id) > 0
            ORDER BY albums_count DESC, t.name ASC
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();

        // Get cameras
        $stmt = $pdo->prepare('
            SELECT cam.*, COUNT(DISTINCT ac.album_id) as albums_count
            FROM cameras cam
            LEFT JOIN album_camera ac ON ac.camera_id = cam.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            GROUP BY cam.id
            HAVING COUNT(DISTINCT ac.album_id) > 0
            ORDER BY albums_count DESC, cam.make ASC, cam.model ASC
        ');
        $stmt->execute();
        $cameras = $stmt->fetchAll();

        // Get lenses
        $stmt = $pdo->prepare('
            SELECT l.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM lenses l
            LEFT JOIN album_lens al ON al.lens_id = l.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY l.id
            HAVING COUNT(DISTINCT al.album_id) > 0
            ORDER BY albums_count DESC, l.brand ASC, l.model ASC
        ');
        $stmt->execute();
        $lenses = $stmt->fetchAll();

        // Get films
        $stmt = $pdo->prepare('
            SELECT f.*, COUNT(DISTINCT af.album_id) as albums_count
            FROM films f
            LEFT JOIN album_film af ON af.film_id = f.id
            LEFT JOIN albums a ON a.id = af.album_id AND a.is_published = 1
            GROUP BY f.id
            HAVING COUNT(DISTINCT af.album_id) > 0
            ORDER BY albums_count DESC, f.brand ASC, f.name ASC
        ');
        $stmt->execute();
        $films = $stmt->fetchAll();

        // Get developers
        $stmt = $pdo->prepare('
            SELECT d.*, COUNT(DISTINCT ad.album_id) as albums_count
            FROM developers d
            LEFT JOIN album_developer ad ON ad.developer_id = d.id
            LEFT JOIN albums a ON a.id = ad.album_id AND a.is_published = 1
            GROUP BY d.id
            HAVING COUNT(DISTINCT ad.album_id) > 0
            ORDER BY albums_count DESC, d.name ASC
        ');
        $stmt->execute();
        $developers = $stmt->fetchAll();

        // Get labs
        $stmt = $pdo->prepare('
            SELECT lab.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM labs lab
            LEFT JOIN album_lab al ON al.lab_id = lab.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY lab.id
            HAVING COUNT(DISTINCT al.album_id) > 0
            ORDER BY albums_count DESC, lab.name ASC
        ');
        $stmt->execute();
        $labs = $stmt->fetchAll();

        // Get locations
        $stmt = $pdo->prepare('
            SELECT loc.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM locations loc
            LEFT JOIN album_location al ON al.location_id = loc.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY loc.id
            HAVING COUNT(DISTINCT al.album_id) > 0
            ORDER BY albums_count DESC, loc.name ASC
        ');
        $stmt->execute();
        $locations = $stmt->fetchAll();

        // Get years (use database-specific year extraction)
        $yearExpr = $this->db->yearExpression('shoot_date');
        $yearSql = "SELECT DISTINCT {$yearExpr} as year, COUNT(*) as albums_count
            FROM albums
            WHERE is_published = 1 AND shoot_date IS NOT NULL
            GROUP BY year
            ORDER BY year DESC";
        $stmt = $pdo->prepare($yearSql);
        $stmt->execute();
        $years = $stmt->fetchAll();

        $result = [
            'categories' => $categories,
            'tags' => $tags,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'locations' => $locations,
            'years' => $years
        ];

        // Save to cache (use JSON for security - avoids unserialize vulnerabilities)
        $cacheDir = dirname($cacheFile);
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $cacheContent = json_encode(['expires' => time() + $cacheTtl, 'data' => $result]);
            $written = @file_put_contents($cacheFile, $cacheContent, LOCK_EX);
            if ($written === false) {
                Logger::warning('GalleriesController: Failed to write filter options cache', [
                    'cache_file' => $cacheFile
                ], 'frontend');
            }
        }

        return $result;
    }

    /**
     * Batch enrich multiple albums with cover images, tags, and counts.
     * Delegates batch fetching to AlbumEnrichmentService so the SQL stays in
     * one place — the controller is now responsible only for assembling the
     * listing-page shape (cover_image / images_count / tags / is_password_protected).
     */
    private function enrichAlbumsBatch(array $albums): array
    {
        if (empty($albums)) {
            return [];
        }

        $enrich = new \App\Services\AlbumEnrichmentService($this->db->pdo());
        $albumIds = array_column($albums, 'id');

        // Index albums by ID with defaults seeded for the template layer
        $albumsById = [];
        foreach ($albums as $album) {
            $albumsById[$album['id']] = $album;
            $albumsById[$album['id']]['cover_image'] = null;
            $albumsById[$album['id']]['images_count'] = 0;
            $albumsById[$album['id']]['tags'] = [];
        }

        // 1. Explicit covers (those with cover_image_id set)
        $coverImageIds = array_values(array_filter(array_column($albums, 'cover_image_id')));
        $coverImagesById = $enrich->loadListingCoverImages($coverImageIds);
        foreach ($albumsById as &$album) {
            if (!empty($album['cover_image_id']) && isset($coverImagesById[$album['cover_image_id']])) {
                $album['cover_image'] = $coverImagesById[$album['cover_image_id']];
            }
        }
        unset($album);

        // 2. Fallback covers (first image per album) for those still missing one
        $needsFallback = array_keys(array_filter($albumsById, fn ($a) => empty($a['cover_image'])));
        $fallbackByAlbum = $enrich->loadFallbackCoverImages($needsFallback);
        foreach ($fallbackByAlbum as $albumId => $img) {
            if (isset($albumsById[$albumId]) && empty($albumsById[$albumId]['cover_image'])) {
                $albumsById[$albumId]['cover_image'] = $img;
            }
        }

        // 3. Image counts
        foreach ($enrich->loadImageCounts($albumIds) as $albumId => $cnt) {
            if (isset($albumsById[$albumId])) {
                $albumsById[$albumId]['images_count'] = $cnt;
            }
        }

        // 4. Tags
        foreach ($enrich->loadTags($albumIds) as $albumId => $tagList) {
            if (isset($albumsById[$albumId])) {
                $albumsById[$albumId]['tags'] = $tagList;
            }
        }

        // Finalize: derive is_password_protected and drop the raw hash
        foreach ($albumsById as &$album) {
            $album['is_password_protected'] = !empty($album['password_hash']);
            unset($album['password_hash']);
        }
        unset($album);

        // Preserve the input order
        $result = [];
        foreach ($albums as $album) {
            $result[] = $albumsById[$album['id']];
        }
        return $result;
    }


    /**
     * Validate filter cache structure to prevent cache poisoning.
     * SECURITY: Ensures cached data has expected structure before use.
     *
     * @param mixed $cache Decoded cache data
     * @return bool True if structure is valid
     */
    private function validateFilterCacheStructure(mixed $cache): bool
    {
        // 1. Must be an array
        if (!is_array($cache)) {
            return false;
        }

        // 2. Must have required top-level keys
        if (!isset($cache['expires'], $cache['data'])) {
            return false;
        }

        // 3. expires must be an integer
        if (!is_int($cache['expires'])) {
            return false;
        }

        // 4. data must be an array
        if (!is_array($cache['data'])) {
            return false;
        }

        // 5. data must contain all expected keys with array values
        $requiredKeys = ['categories', 'tags', 'cameras', 'lenses', 'films',
                        'developers', 'labs', 'locations', 'years'];

        foreach ($requiredKeys as $key) {
            if (!isset($cache['data'][$key]) || !is_array($cache['data'][$key])) {
                return false;
            }
        }

        // 6. Validate structure of array elements (sample check to avoid performance hit)
        // Check first element of each array if not empty
        if (!empty($cache['data']['categories'])) {
            $first = reset($cache['data']['categories']);
            if (!is_array($first) || !isset($first['id'], $first['name'])) {
                return false;
            }
        }

        if (!empty($cache['data']['tags'])) {
            $first = reset($cache['data']['tags']);
            if (!is_array($first) || !isset($first['id'], $first['name'])) {
                return false;
            }
        }

        return true;
    }

}
