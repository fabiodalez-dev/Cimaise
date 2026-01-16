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
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
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

        // Cache is only used for unfiltered public view
        $canUseCache = !$isAdmin && !$nsfwConsent && !$hasActiveFilters;

        // Try cache first for public unfiltered view
        if ($canUseCache) {
            $cacheService = $this->getPageCacheService();
            $cached = $cacheService->get('galleries');

            if ($cached !== null && isset($cached['data']) && is_array($cached['data'])) {
                // Fresh cache hit - render with cached data + session-specific vars
                return $this->view->render($response, 'frontend/galleries.twig', array_merge($cached['data'], [
                    'nsfw_consent' => $nsfwConsent,
                    'is_admin' => $isAdmin,
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]));
            }

            // Lazy regeneration: try stale cache
            $staleCached = $cacheService->get('galleries', allowStale: true);
            if ($staleCached !== null && isset($staleCached['data']) && is_array($staleCached['data'])) {
                // Serve stale, schedule background regeneration could be done here
                return $this->view->render($response, 'frontend/galleries.twig', array_merge($staleCached['data'], [
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
            $this->getPageCacheService()->setWithTags('galleries', [
                'data' => $renderData,
            ], [CacheTags::GALLERIES, CacheTags::NAVIGATION]);
        }

        // Add session-specific vars for rendering
        $renderData['nsfw_consent'] = $nsfwConsent;
        $renderData['is_admin'] = $isAdmin;
        $renderData['csrf'] = $_SESSION['csrf'] ?? '';

        return $this->view->render($response, 'frontend/galleries.twig', $renderData);
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

        // Get filtered albums
        $albums = $this->getFilteredAlbums($filters, $isAdmin, $nsfwConsent);

        // Sanitize album data - remove sensitive fields
        // SECURITY: For NSFW albums without consent, expose only blur_path (no real previews)
        $safeAlbums = array_map(function($album) use ($isAdmin, $nsfwConsent) {
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
                'tags' => array_map(function($tag) {
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

    private function getFilteredAlbums(array $filters, ?bool $isAdmin = null, ?bool $nsfwConsent = null): array
    {
        $pdo = $this->db->pdo();
        
        // Base query
        $sql = '
            SELECT DISTINCT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            LEFT JOIN categories c ON c.id = a.category_id 
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories cat ON cat.id = ac.category_id
            LEFT JOIN album_tag at ON at.album_id = a.id
            LEFT JOIN tags t ON t.id = at.tag_id
            LEFT JOIN album_camera acam ON acam.album_id = a.id
            LEFT JOIN cameras cam ON cam.id = acam.camera_id
            LEFT JOIN album_lens al ON al.album_id = a.id
            LEFT JOIN lenses l ON l.id = al.lens_id
            LEFT JOIN album_film af ON af.album_id = a.id
            LEFT JOIN films f ON f.id = af.film_id
            LEFT JOIN album_developer ad ON ad.album_id = a.id
            LEFT JOIN developers d ON d.id = ad.developer_id
            LEFT JOIN album_lab alab ON alab.album_id = a.id
            LEFT JOIN labs lab ON lab.id = alab.lab_id
            LEFT JOIN album_location aloc ON aloc.album_id = a.id
            LEFT JOIN locations loc ON loc.id = aloc.location_id
            WHERE a.is_published = 1
        ';
        
        $params = [];
        $conditions = [];
        
        // Apply filters
        if (!empty($filters['category'])) {
            $placeholders = str_repeat('?,', count($filters['category']) - 1) . '?';
            $conditions[] = "(cat.id IN ($placeholders) OR c.id IN ($placeholders))";
            $params = array_merge($params, $filters['category'], $filters['category']);
        }
        
        if (!empty($filters['tags'])) {
            $placeholders = str_repeat('?,', count($filters['tags']) - 1) . '?';
            $conditions[] = "t.id IN ($placeholders)";
            $params = array_merge($params, $filters['tags']);
        }
        
        if (!empty($filters['cameras'])) {
            $placeholders = str_repeat('?,', count($filters['cameras']) - 1) . '?';
            $conditions[] = "cam.id IN ($placeholders)";
            $params = array_merge($params, $filters['cameras']);
        }
        
        if (!empty($filters['lenses'])) {
            $placeholders = str_repeat('?,', count($filters['lenses']) - 1) . '?';
            $conditions[] = "l.id IN ($placeholders)";
            $params = array_merge($params, $filters['lenses']);
        }
        
        if (!empty($filters['films'])) {
            $placeholders = str_repeat('?,', count($filters['films']) - 1) . '?';
            $conditions[] = "f.id IN ($placeholders)";
            $params = array_merge($params, $filters['films']);
        }
        
        if (!empty($filters['developers'])) {
            $placeholders = str_repeat('?,', count($filters['developers']) - 1) . '?';
            $conditions[] = "d.id IN ($placeholders)";
            $params = array_merge($params, $filters['developers']);
        }
        
        if (!empty($filters['labs'])) {
            $placeholders = str_repeat('?,', count($filters['labs']) - 1) . '?';
            $conditions[] = "lab.id IN ($placeholders)";
            $params = array_merge($params, $filters['labs']);
        }
        
        if (!empty($filters['locations'])) {
            $placeholders = str_repeat('?,', count($filters['locations']) - 1) . '?';
            $conditions[] = "loc.id IN ($placeholders)";
            $params = array_merge($params, $filters['locations']);
        }
        
        if (!empty($filters['year'])) {
            $yearExpr = $this->db->yearExpression('a.shoot_date');
            $conditions[] = "{$yearExpr} = ?";
            $params[] = (string)$filters['year'];
        }
        
        if (!empty($filters['search'])) {
            $conditions[] = "(a.title LIKE ? OR a.excerpt LIKE ? OR a.body LIKE ? OR a.slug LIKE ? OR c.name LIKE ? OR cat.name LIKE ? OR t.name LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Add conditions to query
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
                if (!is_array($cache)) {
                    @unlink($cacheFile);
                    $cache = [];
                }
                if (is_array($cache) && isset($cache['expires'], $cache['data']) && $cache['expires'] > time()) {
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
     * Reduces N+1 queries from ~4 per album to 4 total queries.
     *
     * @param array $albums Array of album records
     * @return array Enriched albums
     */
    private function enrichAlbumsBatch(array $albums): array
    {
        if (empty($albums)) {
            return [];
        }

        $pdo = $this->db->pdo();
        $albumIds = array_column($albums, 'id');
        $albumPlaceholders = implode(',', array_fill(0, count($albumIds), '?'));

        // Index albums by ID for easy lookup
        $albumsById = [];
        foreach ($albums as $album) {
            $albumsById[$album['id']] = $album;
            $albumsById[$album['id']]['cover_image'] = null;
            $albumsById[$album['id']]['images_count'] = 0;
            $albumsById[$album['id']]['tags'] = [];
        }

        // 1. Batch fetch cover images (explicit cover_image_id)
        $coverImageIds = array_filter(array_column($albums, 'cover_image_id'));
        $coverImagesById = [];
        if (!empty($coverImageIds)) {
            $coverPlaceholders = implode(',', array_fill(0, count($coverImageIds), '?'));
            $stmt = $pdo->prepare("
                SELECT i.*,
                       iv.path AS preview_path,
                       blur.path AS blur_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
                WHERE i.id IN ($coverPlaceholders)
            ");
            $stmt->execute($coverImageIds);
            foreach ($stmt->fetchAll() as $img) {
                $coverImagesById[$img['id']] = $img;
            }
        }

        // Assign explicit covers
        foreach ($albumsById as $albumId => &$album) {
            if (!empty($album['cover_image_id']) && isset($coverImagesById[$album['cover_image_id']])) {
                $album['cover_image'] = $coverImagesById[$album['cover_image_id']];
            }
        }
        unset($album);

        // 2. Batch fetch first images for albums without explicit covers
        $albumsNeedingFallback = array_filter($albumsById, fn($a) => empty($a['cover_image']));
        if (!empty($albumsNeedingFallback)) {
            $fallbackIds = array_keys($albumsNeedingFallback);
            $fallbackPlaceholders = implode(',', array_fill(0, count($fallbackIds), '?'));

            // Get first image per album using window function or subquery
            $stmt = $pdo->prepare("
                SELECT i.*,
                       iv.path AS preview_path,
                       blur.path AS blur_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
                WHERE i.album_id IN ($fallbackPlaceholders)
                  AND i.id = (
                      SELECT i2.id FROM images i2
                      WHERE i2.album_id = i.album_id
                      ORDER BY i2.sort_order ASC, i2.id ASC
                      LIMIT 1
                  )
            ");
            $stmt->execute($fallbackIds);
            foreach ($stmt->fetchAll() as $img) {
                if (isset($albumsById[$img['album_id']]) && empty($albumsById[$img['album_id']]['cover_image'])) {
                    $albumsById[$img['album_id']]['cover_image'] = $img;
                }
            }
        }

        // 3. Batch fetch image counts
        $stmt = $pdo->prepare("
            SELECT album_id, COUNT(*) as cnt
            FROM images
            WHERE album_id IN ($albumPlaceholders)
            GROUP BY album_id
        ");
        $stmt->execute($albumIds);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($albumsById[$row['album_id']])) {
                $albumsById[$row['album_id']]['images_count'] = (int)$row['cnt'];
            }
        }

        // 4. Batch fetch tags
        $stmt = $pdo->prepare("
            SELECT at.album_id, t.*
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            WHERE at.album_id IN ($albumPlaceholders)
            ORDER BY t.name ASC
        ");
        $stmt->execute($albumIds);
        foreach ($stmt->fetchAll() as $tag) {
            $albumId = $tag['album_id'];
            unset($tag['album_id']);
            if (isset($albumsById[$albumId])) {
                $albumsById[$albumId]['tags'][] = $tag;
            }
        }

        // Finalize: set password protection flag
        foreach ($albumsById as &$album) {
            $album['is_password_protected'] = !empty($album['password_hash']);
            unset($album['password_hash']);
        }
        unset($album);

        // Return in original order
        $result = [];
        foreach ($albums as $album) {
            $result[] = $albumsById[$album['id']];
        }
        return $result;
    }

    /**
     * @deprecated Use enrichAlbumsBatch() for better performance
     */
    private function enrichAlbum(array $album): array
    {
        $pdo = $this->db->pdo();

        // Get cover image with blur variant for NSFW albums
        if (!empty($album['cover_image_id'])) {
            $stmt = $pdo->prepare("
                SELECT i.*,
                       iv.path AS preview_path,
                       blur.path AS blur_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
                WHERE i.id = :id
            ");
            $stmt->execute([':id' => $album['cover_image_id']]);
            $cover = $stmt->fetch();
            if ($cover) {
                if (empty($cover['blur_path'])) {
                    $cover['blur_path'] = $this->fetchBlurPath($pdo, (int)$cover['id']);
                }
                $album['cover_image'] = $cover;
            }
        }

        // If no cover image, get first image
        if (empty($album['cover_image'])) {
            $stmt = $pdo->prepare("
                SELECT i.*,
                       iv.path AS preview_path,
                       blur.path AS blur_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
                WHERE i.album_id = :album_id
                ORDER BY i.sort_order ASC, i.id ASC
                LIMIT 1
            ");
            $stmt->execute([':album_id' => $album['id']]);
            $cover = $stmt->fetch();
            if ($cover) {
                if (empty($cover['blur_path'])) {
                    $cover['blur_path'] = $this->fetchBlurPath($pdo, (int)$cover['id']);
                }
                $album['cover_image'] = $cover;
            }
        }

        // Get images count
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE album_id = :album_id');
        $stmt->execute([':album_id' => $album['id']]);
        $album['images_count'] = $stmt->fetchColumn();

        // Get tags
        $stmt = $pdo->prepare('
            SELECT t.* FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            WHERE at.album_id = :album_id
            ORDER BY t.name ASC
        ');
        $stmt->execute([':album_id' => $album['id']]);
        $album['tags'] = $stmt->fetchAll();

        // Add password protection flag (don't expose the hash itself)
        $album['is_password_protected'] = !empty($album['password_hash']);
        unset($album['password_hash']);

        return $album;
    }

    private function fetchBlurPath(\PDO $pdo, int $imageId): ?string
    {
        if ($imageId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND variant = 'blur' LIMIT 1");
        $stmt->execute([':id' => $imageId]);
        $path = $stmt->fetchColumn();

        return $path !== false ? (string)$path : null;
    }

}
